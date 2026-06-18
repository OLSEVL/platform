<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProxyRotationService
{
    public function __construct(
        private readonly ProxyDeadProbeService $deadProbe,
    ) {
    }
    private const SETTING_AUTO_ROTATION_ENABLED = 'proxy_auto_rotation_enabled';
    private const SETTING_DEAD_STREAK_THRESHOLD = 'proxy_dead_streak_threshold';
    /** @deprecated читается как fallback при миграции с proxy_cooldown_streak_threshold */
    private const LEGACY_COOLDOWN_STREAK_THRESHOLD = 'proxy_cooldown_streak_threshold';
    private const SETTING_MAX_INSTRUMENTS_PER_PROXY = 'proxy_max_instruments_per_endpoint';
    /** Версия пула для ingest: меняется при «Обновить прокси», чтобы воркеры сразу подхватили новые URL. */
    public const SETTING_POOL_VERSION = 'proxy_pool_version';
    /** Метка полного refresh из Webshare — grace-период без перевода в dead (массовый reconnect). */
    public const SETTING_POOL_REFRESHED_AT = 'proxy_pool_refreshed_at';
    private const POOL_REFRESH_GRACE_MINUTES = 15;
    private const DEFAULT_DEAD_STREAK_THRESHOLD = 10;
    private const DEFAULT_MAX_INSTRUMENTS_PER_PROXY = 1;

    public function isAutoRotationEnabled(): bool
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_AUTO_ROTATION_ENABLED)
            ->value('value');

        return (bool) (int) ($value ?? 0);
    }

    public function setAutoRotationEnabled(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_AUTO_ROTATION_ENABLED],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function getDeadStreakThreshold(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_DEAD_STREAK_THRESHOLD)
            ->value('value');

        if ($value === null) {
            $value = DB::table('app_settings')
                ->where('key', self::LEGACY_COOLDOWN_STREAK_THRESHOLD)
                ->value('value');
        }

        $threshold = (int) ($value ?? self::DEFAULT_DEAD_STREAK_THRESHOLD);

        return max(1, min(1000, $threshold));
    }

    public function setDeadStreakThreshold(int $threshold): void
    {
        $normalized = max(1, min(1000, $threshold));

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_DEAD_STREAK_THRESHOLD],
            [
                'value' => (string) $normalized,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function getMaxInstrumentsPerProxy(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_MAX_INSTRUMENTS_PER_PROXY)
            ->value('value');

        $max = (int) ($value ?? self::DEFAULT_MAX_INSTRUMENTS_PER_PROXY);

        return max(1, min(100, $max));
    }

    public function setMaxInstrumentsPerProxy(int $max): void
    {
        $normalized = max(1, min(100, $max));

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_MAX_INSTRUMENTS_PER_PROXY],
            [
                'value' => (string) $normalized,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function bumpPoolVersion(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_POOL_VERSION],
            [
                'value' => (string) now()->timestamp,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function getPoolVersion(): int
    {
        return (int) (DB::table('app_settings')
            ->where('key', self::SETTING_POOL_VERSION)
            ->value('value') ?? 0);
    }

    public function markPoolRefreshed(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_POOL_REFRESHED_AT],
            [
                'value' => now()->toIso8601String(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $this->bumpPoolVersion();
    }

    public function isWithinPoolRefreshGracePeriod(): bool
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_POOL_REFRESHED_AT)
            ->value('value');

        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            $refreshedAt = Carbon::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return $refreshedAt->greaterThan(now()->subMinutes(self::POOL_REFRESH_GRACE_MINUTES));
    }

    /**
     * Переназначает активные инструменты, оставшиеся на dead endpoint.
     */
    public function reassignInstrumentsOnUnhealthyProxies(): int
    {
        $moved = 0;
        $instruments = PocketInstrument::query()
            ->active()
            ->with('proxyEndpoint')
            ->get();

        foreach ($instruments as $instrument) {
            if ($this->isProxyHealthyForInstrument($instrument->proxyEndpoint)) {
                continue;
            }

            if ($this->rotateInstrumentProxy($instrument, false) !== null) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Массовое переназначение всех активных инструментов прокси из пула.
     */
    public function assignRoundRobinToActiveInstruments(): int
    {
        $activeInstruments = PocketInstrument::query()
            ->active()
            ->orderByPriority()
            ->get(['id']);

        if ($activeInstruments->isEmpty()) {
            return 0;
        }

        $pool = ProxyEndpoint::query()
            ->readyForAssignment()
            ->orderBy('id')
            ->get(['id']);

        if ($pool->isEmpty()) {
            return 0;
        }

        $poolIds = $pool->pluck('id')->values();
        $poolCount = $poolIds->count();
        $assigned = 0;

        DB::transaction(function () use ($activeInstruments, $poolIds, $poolCount, &$assigned): void {
            foreach ($activeInstruments as $index => $instrument) {
                PocketInstrument::query()
                    ->whereKey($instrument->id)
                    ->update([
                        'proxy_endpoint_id' => $poolIds[$index % $poolCount],
                    ]);
                $assigned++;
            }
        });

        return $assigned;
    }

    /**
     * @return array{
     *   active_instruments: int,
     *   ready_proxies: int,
     *   max_per_proxy: int,
     *   total_slots: int,
     *   used_slots_on_ready: int,
     *   free_slots: int,
     *   is_exhausted: bool,
     *   instruments_on_dead_proxy: int,
     *   instruments_without_proxy: int
     * }
     */
    public function getPoolCapacitySummary(): array
    {
        $maxPerProxy = $this->getMaxInstrumentsPerProxy();
        $readyProxies = ProxyEndpoint::query()
            ->readyForAssignment()
            ->withCount([
                'instruments as active_instruments_count' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        $freeSlots = 0;
        $usedOnReady = 0;
        foreach ($readyProxies as $proxy) {
            $used = (int) $proxy->active_instruments_count;
            $usedOnReady += $used;
            $freeSlots += max(0, $maxPerProxy - $used);
        }

        $activeInstruments = PocketInstrument::query()->active()->count();
        $onDead = PocketInstrument::query()
            ->active()
            ->whereHas('proxyEndpoint', fn ($q) => $q->where('status', ProxyEndpoint::STATUS_DEAD))
            ->count();
        $withoutProxy = PocketInstrument::query()
            ->active()
            ->whereNull('proxy_endpoint_id')
            ->count();

        $totalSlots = $readyProxies->count() * $maxPerProxy;

        return [
            'active_instruments' => $activeInstruments,
            'ready_proxies' => $readyProxies->count(),
            'max_per_proxy' => $maxPerProxy,
            'total_slots' => $totalSlots,
            'used_slots_on_ready' => $usedOnReady,
            'free_slots' => $freeSlots,
            'is_exhausted' => $activeInstruments > 0 && $freeSlots === 0,
            'instruments_on_dead_proxy' => $onDead,
            'instruments_without_proxy' => $withoutProxy,
        ];
    }

    public function formatPoolExhaustedMessage(): string
    {
        $c = $this->getPoolCapacitySummary();

        return sprintf(
            'Пул прокси исчерпан: свободных слотов %d из %d (ready-прокси: %d, лимит %d инстр./прокси, активных инструментов: %d). '
            .'Добавьте прокси (Webshare), увеличьте лимит на endpoint или выключите лишние инструменты. '
            .'На dead-прокси сейчас: %d инстр.',
            $c['free_slots'],
            $c['total_slots'],
            $c['ready_proxies'],
            $c['max_per_proxy'],
            $c['active_instruments'],
            $c['instruments_on_dead_proxy'],
        );
    }

    public function rotateInstrumentProxy(PocketInstrument $instrument, bool $markCurrentAsDead = false): ?ProxyEndpoint
    {
        return DB::transaction(function () use ($instrument, $markCurrentAsDead): ?ProxyEndpoint {
            $instrument->refresh();
            $currentProxy = $instrument->proxyEndpoint;

            $replacement = $this->pickReplacementProxy($currentProxy?->id);
            if (! $replacement) {
                return null;
            }

            $instrument->update([
                'proxy_endpoint_id' => $replacement->id,
            ]);

            if ($markCurrentAsDead && $currentProxy) {
                ProxyEndpoint::query()->whereKey($currentProxy->id)->increment('failure_count');
                $currentProxy->update([
                    'status' => ProxyEndpoint::STATUS_DEAD,
                    'last_error' => 'manual_mark_dead',
                    'last_failed_at' => now(),
                ]);
            }

            return $replacement;
        });
    }

    public function ensureInstrumentHasReadyProxy(PocketInstrument $instrument): ?ProxyEndpoint
    {
        if ($this->deadProbe->isInstrumentProbeLocked($instrument)) {
            $instrument->loadMissing('proxyEndpoint');

            return $instrument->proxyEndpoint;
        }

        $instrument->loadMissing('proxyEndpoint');
        $current = $instrument->proxyEndpoint;

        if ($this->isProxyHealthyForInstrument($current)) {
            return $current;
        }

        return $this->rotateInstrumentProxy($instrument, false);
    }

    /**
     * Помечает прокси проблемным; при серии ошибок (streak) переводит в dead и ротирует инструмент.
     */
    public function markProxyFailure(PocketInstrument $instrument, string $error, ?int $streak = null): void
    {
        $instrument->loadMissing('proxyEndpoint');
        $proxy = $instrument->proxyEndpoint;

        if (! $proxy) {
            return;
        }

        if ($this->deadProbe->isInstrumentProbeLocked($instrument)
            || $this->deadProbe->isProxyUnderProbe($proxy->id)) {
            return;
        }

        $autoRotationEnabled = $this->isAutoRotationEnabled();
        $deadThreshold = $this->getDeadStreakThreshold();
        $streakValue = max(0, (int) ($streak ?? 0));
        // После «Обновить прокси» кратковременные connect_failed не уводят свежий пул в dead.
        $shouldMarkDead = $autoRotationEnabled
            && $streakValue >= $deadThreshold
            && ! $this->isWithinPoolRefreshGracePeriod();

        ProxyEndpoint::query()->whereKey($proxy->id)->increment('failure_count');
        $proxy->update([
            'last_error' => mb_substr($error, 0, 1000),
            'last_failed_at' => now(),
            'status' => $shouldMarkDead ? ProxyEndpoint::STATUS_DEAD : $proxy->status,
        ]);

        if ($shouldMarkDead) {
            $this->rotateInstrumentProxy($instrument, false);
        }
    }

    private function pickReplacementProxy(?int $excludeId = null): ?ProxyEndpoint
    {
        $maxPerProxy = $this->getMaxInstrumentsPerProxy();

        return ProxyEndpoint::query()
            ->where('proxy_endpoints.status', ProxyEndpoint::STATUS_ACTIVE)
            ->leftJoin('pocket_instruments', function ($join): void {
                $join->on('pocket_instruments.proxy_endpoint_id', '=', 'proxy_endpoints.id')
                    ->where('pocket_instruments.is_active', '=', 1);
            })
            ->when($excludeId, fn ($q) => $q->where('proxy_endpoints.id', '!=', $excludeId))
            ->groupBy(
                'proxy_endpoints.id',
                'proxy_endpoints.url',
                'proxy_endpoints.status',
                'proxy_endpoints.failure_count',
                'proxy_endpoints.last_error',
                'proxy_endpoints.last_failed_at',
                'proxy_endpoints.last_success_at',
                'proxy_endpoints.created_at',
                'proxy_endpoints.updated_at'
            )
            ->havingRaw('COUNT(pocket_instruments.id) < ?', [$maxPerProxy])
            ->orderByRaw('COUNT(pocket_instruments.id) asc')
            ->orderBy('proxy_endpoints.failure_count')
            ->orderByDesc('proxy_endpoints.last_success_at')
            ->orderBy('proxy_endpoints.id')
            ->select('proxy_endpoints.*')
            ->first();
    }

    private function isProxyHealthyForInstrument(?ProxyEndpoint $proxy): bool
    {
        return $proxy !== null && $proxy->status === ProxyEndpoint::STATUS_ACTIVE;
    }
}
