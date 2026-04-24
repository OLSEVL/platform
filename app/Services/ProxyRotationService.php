<?php

namespace App\Services;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProxyRotationService
{
    private const SETTING_AUTO_ROTATION_ENABLED = 'proxy_auto_rotation_enabled';
    private const SETTING_COOLDOWN_STREAK_THRESHOLD = 'proxy_cooldown_streak_threshold';
    private const SETTING_MAX_INSTRUMENTS_PER_PROXY = 'proxy_max_instruments_per_endpoint';
    private const DEFAULT_COOLDOWN_MINUTES = 60;
    private const DEFAULT_COOLDOWN_STREAK_THRESHOLD = 10;
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

    public function getCooldownStreakThreshold(): int
    {
        $value = DB::table('app_settings')
            ->where('key', self::SETTING_COOLDOWN_STREAK_THRESHOLD)
            ->value('value');

        $threshold = (int) ($value ?? self::DEFAULT_COOLDOWN_STREAK_THRESHOLD);

        // Гарантируем безопасный диапазон даже при ручном редактировании БД.
        return max(1, min(1000, $threshold));
    }

    public function setCooldownStreakThreshold(int $threshold): void
    {
        $normalized = max(1, min(1000, $threshold));

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_COOLDOWN_STREAK_THRESHOLD],
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

        // Ограничиваем диапазон, чтобы ручной ввод в БД/форме не сломал алгоритм
        // подбора replacement-прокси и не вызвал неконтролируемую концентрацию нагрузки.
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

    /**
     * Массовое переназначение всех активных инструментов прокси из пула.
     * Поведение кнопки "Обновить прокси" сохраняется: меняем прокси всем активным инструментам.
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
                // Используем query update по id вместо model->update(), чтобы избежать
                // неоднозначной типизации коллекции в статическом анализаторе.
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
     * Выбирает следующий прокси и назначает его инструменту.
     * При $markCurrentAsDead=true текущий прокси переводится в dead перед ротацией.
     */
    public function rotateInstrumentProxy(PocketInstrument $instrument, bool $markCurrentAsDead = false): ?ProxyEndpoint
    {
        return DB::transaction(function () use ($instrument, $markCurrentAsDead): ?ProxyEndpoint {
            $instrument->refresh();
            $currentProxy = $instrument->proxyEndpoint;

            if ($markCurrentAsDead && $currentProxy) {
                // Инкремент счётчика делаем отдельным SQL update, чтобы не передавать
                // Query\Expression в модельные cast (это даёт warning/500 в рантайме).
                ProxyEndpoint::query()->whereKey($currentProxy->id)->increment('failure_count');
                $currentProxy->update([
                    'status' => ProxyEndpoint::STATUS_DEAD,
                    'last_error' => 'manual_mark_dead',
                    'last_failed_at' => now(),
                ]);
            }

            $replacement = $this->pickReplacementProxy($currentProxy?->id);
            if (! $replacement) {
                return null;
            }

            $instrument->update([
                'proxy_endpoint_id' => $replacement->id,
            ]);

            return $replacement;
        });
    }

    /**
     * Автоматически гарантирует, что инструмент привязан к рабочему прокси.
     * Используется на выдаче /api/proxy/instruments, когда включена авто-ротация.
     */
    public function ensureInstrumentHasReadyProxy(PocketInstrument $instrument): ?ProxyEndpoint
    {
        $instrument->loadMissing('proxyEndpoint');
        $current = $instrument->proxyEndpoint;

        if ($this->isProxyReady($current)) {
            return $current;
        }

        return $this->rotateInstrumentProxy($instrument, false);
    }

    /**
     * Помечает прокси проблемным и при включённой авто-ротации переводит его в cooldown.
     * Этот метод пригодится для вызова из ingest-кода при connect failed.
     */
    public function markProxyFailure(PocketInstrument $instrument, string $error, ?int $streak = null): void
    {
        $instrument->loadMissing('proxyEndpoint');
        $proxy = $instrument->proxyEndpoint;

        if (! $proxy) {
            return;
        }

        $autoRotationEnabled = $this->isAutoRotationEnabled();
        $cooldownThreshold = $this->getCooldownStreakThreshold();
        $streakValue = max(0, (int) ($streak ?? 0));
        $shouldMoveToCooldown = $autoRotationEnabled && $streakValue >= $cooldownThreshold;
        // Безопасный инкремент счётчика ошибок без Expression в model->update().
        ProxyEndpoint::query()->whereKey($proxy->id)->increment('failure_count');
        $proxy->update([
            'last_error' => mb_substr($error, 0, 1000),
            'last_failed_at' => now(),
            'status' => $shouldMoveToCooldown ? ProxyEndpoint::STATUS_COOLDOWN : $proxy->status,
            'cooldown_until' => $shouldMoveToCooldown
                ? Carbon::now()->addMinutes(self::DEFAULT_COOLDOWN_MINUTES)
                : $proxy->cooldown_until,
        ]);

        if ($shouldMoveToCooldown) {
            $this->rotateInstrumentProxy($instrument, false);
        }
    }

    private function pickReplacementProxy(?int $excludeId = null): ?ProxyEndpoint
    {
        $maxPerProxy = $this->getMaxInstrumentsPerProxy();

        return ProxyEndpoint::query()
            ->readyForAssignment()
            ->leftJoin('pocket_instruments', function ($join): void {
                // Считаем фактическую "нагрузку" endpoint только активными инструментами:
                // неактивные не должны блокировать выдачу рабочего прокси новым воркерам.
                $join->on('pocket_instruments.proxy_endpoint_id', '=', 'proxy_endpoints.id')
                    ->where('pocket_instruments.is_active', '=', 1);
            })
            // Явно квалифицируем id таблицей proxy_endpoints, чтобы избежать
            // ambiguous column в запросе после join с pocket_instruments.
            ->when($excludeId, fn ($q) => $q->where('proxy_endpoints.id', '!=', $excludeId))
            ->groupBy(
                'proxy_endpoints.id',
                'proxy_endpoints.url',
                'proxy_endpoints.status',
                'proxy_endpoints.failure_count',
                'proxy_endpoints.last_error',
                'proxy_endpoints.last_failed_at',
                'proxy_endpoints.last_success_at',
                'proxy_endpoints.cooldown_until',
                'proxy_endpoints.created_at',
                'proxy_endpoints.updated_at'
            )
            ->havingRaw('COUNT(pocket_instruments.id) < ?', [$maxPerProxy])
            // Балансируем назначение: сначала самые "здоровые", затем по id.
            ->orderByRaw('COUNT(pocket_instruments.id) asc')
            ->orderBy('proxy_endpoints.failure_count')
            ->orderByDesc('proxy_endpoints.last_success_at')
            ->orderBy('proxy_endpoints.id')
            ->select('proxy_endpoints.*')
            ->first();
    }

    private function isProxyReady(?ProxyEndpoint $proxy): bool
    {
        if (! $proxy) {
            return false;
        }

        if ($proxy->status === ProxyEndpoint::STATUS_ACTIVE) {
            return true;
        }

        if ($proxy->status !== ProxyEndpoint::STATUS_COOLDOWN) {
            return false;
        }

        return $proxy->cooldown_until !== null && $proxy->cooldown_until->lessThanOrEqualTo(now());
    }
}

