<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use App\Support\IngestHealth;
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
    /** Включить dead+ротацию, если last_payout_event_at старше порога (мин). */
    private const SETTING_DEAD_ON_PO_STALE_ENABLED = 'proxy_dead_on_po_stale_enabled';
    private const SETTING_DEAD_ON_PO_STALE_MINUTES = 'proxy_dead_on_po_stale_minutes';
    /** Версия пула для ingest: меняется при «Обновить прокси», чтобы воркеры сразу подхватили новые URL. */
    public const SETTING_POOL_VERSION = 'proxy_pool_version';
    /** Метка полного refresh из Webshare — grace-период без перевода в dead (массовый reconnect). */
    public const SETTING_POOL_REFRESHED_AT = 'proxy_pool_refreshed_at';
    private const POOL_REFRESH_GRACE_MINUTES = 15;
    private const DEFAULT_DEAD_STREAK_THRESHOLD = 10;
    private const DEFAULT_MAX_INSTRUMENTS_PER_PROXY = 1;
    private const DEFAULT_DEAD_ON_PO_STALE_MINUTES = 30;

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

    public function isDeadOnPoStaleEnabled(): bool
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_DEAD_ON_PO_STALE_ENABLED)
            ->value('value');

        return (bool) (int) ($value ?? 0);
    }

    public function setDeadOnPoStaleEnabled(bool $enabled): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_DEAD_ON_PO_STALE_ENABLED],
            [
                'value' => $enabled ? '1' : '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function getDeadOnPoStaleMinutes(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_DEAD_ON_PO_STALE_MINUTES)
            ->value('value');

        $minutes = (int) ($value ?? self::DEFAULT_DEAD_ON_PO_STALE_MINUTES);

        return max(1, min(1440, $minutes));
    }

    public function setDeadOnPoStaleMinutes(int $minutes): void
    {
        $normalized = max(1, min(1440, $minutes));

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_DEAD_ON_PO_STALE_MINUTES],
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
                $this->markProxyAsDead($currentProxy, $instrument, 'manual_mark_dead');
            }

            return $replacement;
        });
    }

    /**
     * Если «Событие PO» устарело дольше порога — помечает прокси dead и переназначает инструмент.
     * Вызывается из API /instruments при каждом опросе ingest (~15 с).
     */
    public function handleStalePoProxy(PocketInstrument $instrument): ?ProxyEndpoint
    {
        if (! $this->isAutoRotationEnabled() || ! $this->isDeadOnPoStaleEnabled()) {
            $instrument->loadMissing('proxyEndpoint');

            return $instrument->proxyEndpoint;
        }

        if ($this->deadProbe->isInstrumentProbeLocked($instrument)) {
            $instrument->loadMissing('proxyEndpoint');

            return $instrument->proxyEndpoint;
        }

        if ($this->isWithinPoolRefreshGracePeriod()) {
            $instrument->loadMissing('proxyEndpoint');

            return $instrument->proxyEndpoint;
        }

        $instrument->loadMissing('proxyEndpoint');
        $proxy = $instrument->proxyEndpoint;

        if ($proxy === null || $proxy->status !== ProxyEndpoint::STATUS_ACTIVE) {
            return $proxy;
        }

        if ($this->deadProbe->isProxyUnderProbe($proxy->id)) {
            return $proxy;
        }

        $staleMinutes = $this->getDeadOnPoStaleMinutes();
        if (! IngestHealth::shouldMarkProxyDeadForStalePoEvent($instrument, $staleMinutes)) {
            return $proxy;
        }

        $this->markProxyAsDead($proxy, $instrument, 'po_stale', [
            'last_payout_event_at' => $instrument->last_payout_event_at?->toIso8601String(),
            'last_payout_at' => $instrument->last_payout_at?->toIso8601String(),
            'payout_is_stale' => (bool) $instrument->payout_is_stale,
            'stale_minutes' => $staleMinutes,
        ]);

        $replacement = $this->rotateInstrumentProxy($instrument, false);
        $instrument->load('proxyEndpoint');

        return $replacement ?? $instrument->proxyEndpoint;
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

        $this->markProxyEndpointFailure($proxy, $error, $streak, $instrument, [
            'source' => 'ingest',
        ]);
    }

    /**
     * Учёт ошибки по конкретному endpoint (зеркало PO / любой вызов без привязки к ingest-инструменту).
     * Пишет failure_count + last_error; при достижении порога — dead и ротация пар на этом прокси.
     *
     * @param  array<string, mixed>  $meta
     */
    public function markProxyEndpointFailure(
        ProxyEndpoint $proxy,
        string $error,
        ?int $streak = null,
        ?PocketInstrument $instrument = null,
        array $meta = [],
    ): void {
        if ($this->deadProbe->isProxyUnderProbe($proxy->id)) {
            return;
        }

        if ($instrument !== null && $this->deadProbe->isInstrumentProbeLocked($instrument)) {
            return;
        }

        $autoRotationEnabled = $this->isAutoRotationEnabled();
        $deadThreshold = $this->getDeadStreakThreshold();

        ProxyEndpoint::query()->whereKey($proxy->id)->increment('failure_count');
        $proxy->refresh();

        // Ingest передаёт streak сессии WS; для зеркала без сессии берём накопленный failure_count.
        $streakValue = $streak !== null
            ? max(0, (int) $streak)
            : max(0, (int) $proxy->failure_count);

        // После «Обновить прокси» кратковременные connect_failed не уводят свежий пул в dead.
        $shouldMarkDead = $autoRotationEnabled
            && $streakValue >= $deadThreshold
            && ! $this->isWithinPoolRefreshGracePeriod();

        $errorText = mb_substr($error, 0, 1000);
        $proxy->update([
            'last_error' => $errorText,
            'last_failed_at' => now(),
            'status' => $shouldMarkDead ? ProxyEndpoint::STATUS_DEAD : $proxy->status,
        ]);

        if (! $shouldMarkDead) {
            return;
        }

        $proxy->refresh();
        $this->appendDeadReasonLogOnly($proxy, $instrument, $errorText, array_merge($meta, [
            'streak' => $streakValue,
            'dead_streak_threshold' => $deadThreshold,
        ]));

        if ($instrument !== null) {
            $this->rotateInstrumentProxy($instrument, false);

            return;
        }

        // Зеркало: endpoint мог не быть назначен на пару — переназначаем все active-инструменты с него.
        $this->rotateInstrumentsOnProxy($proxy);
    }

    /**
     * Ручной перевод прокси в dead со страницы /settings.
     * Переназначает активные инструменты с этого endpoint (если есть свободные слоты).
     *
     * @return array{already_dead: bool, rotated: int, instruments_left: int}
     */
    public function markProxyDeadManually(ProxyEndpoint $proxy, string $reason = 'manual_mark_dead'): array
    {
        if ($proxy->status === ProxyEndpoint::STATUS_DEAD) {
            return [
                'already_dead' => true,
                'rotated' => 0,
                'instruments_left' => (int) PocketInstrument::query()
                    ->where('proxy_endpoint_id', $proxy->id)
                    ->where('is_active', true)
                    ->count(),
            ];
        }

        // Сразу dead + журнал причины; дальше пытаемся снять с него пары.
        $this->markProxyAsDead($proxy, null, $reason, [
            'source' => 'settings_manual',
        ]);

        $rotated = $this->rotateInstrumentsOnProxy($proxy->fresh());

        return [
            'already_dead' => false,
            'rotated' => $rotated,
            'instruments_left' => (int) PocketInstrument::query()
                ->where('proxy_endpoint_id', $proxy->id)
                ->where('is_active', true)
                ->count(),
        ];
    }

    /**
     * Переназначить все активные инструменты с данного (обычно уже dead) прокси.
     */
    public function rotateInstrumentsOnProxy(ProxyEndpoint $proxy): int
    {
        $rotated = 0;
        PocketInstrument::query()
            ->where('proxy_endpoint_id', $proxy->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (PocketInstrument $instrument) use (&$rotated): void {
                if ($this->rotateInstrumentProxy($instrument, false) !== null) {
                    $rotated++;
                }
            });

        return $rotated;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function markProxyAsDead(
        ProxyEndpoint $proxy,
        ?PocketInstrument $instrument,
        string $reason,
        array $meta = [],
    ): void {
        ProxyEndpoint::query()->whereKey($proxy->id)->increment('failure_count');
        $proxy->refresh();
        $this->appendDeadReasonLogOnly($proxy, $instrument, $reason, $meta);
        $proxy->update([
            'status' => ProxyEndpoint::STATUS_DEAD,
            'last_error' => $reason,
            'last_failed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function appendDeadReasonLogOnly(
        ProxyEndpoint $proxy,
        ?PocketInstrument $instrument,
        string $reason,
        array $meta = [],
    ): void {
        $proxy->appendDeadReasonLog($reason, $instrument, $meta);
        $proxy->save();
    }

    /**
     * Выбор замены: сначала минимум ошибок (failure_count), затем свободная ёмкость слота.
     */
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
            ->orderBy('proxy_endpoints.failure_count')
            ->orderByRaw('COUNT(pocket_instruments.id) asc')
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
