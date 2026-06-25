<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProxyEndpoint extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'url',
        'status',
        'failure_count',
        'last_error',
        'last_failed_at',
        'last_success_at',
        'last_http_check_at',
        'last_http_status',
        'last_http_latency_ms',
        'last_http_check_result',
        'last_http_check_mode',
        'last_auto_test',
        'dead_reason_log',
    ];

    private const DEAD_REASON_LOG_MAX_ENTRIES = 50;

    protected function casts(): array
    {
        return [
            'failure_count' => 'integer',
            'last_failed_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_http_check_at' => 'datetime',
            'last_http_status' => 'integer',
            'last_http_latency_ms' => 'integer',
            'last_auto_test' => 'array',
            'dead_reason_log' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function appendDeadReasonLog(
        string $reason,
        ?PocketInstrument $instrument = null,
        array $meta = [],
    ): void {
        $entry = array_filter([
            'reason' => $reason,
            'instrument' => $instrument?->instrument,
            'instrument_id' => $instrument?->id,
            'at' => now()->toIso8601String(),
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($value) => $value !== null);

        $log = is_array($this->dead_reason_log) ? $this->dead_reason_log : [];
        $log[] = $entry;
        if (count($log) > self::DEAD_REASON_LOG_MAX_ENTRIES) {
            $log = array_slice($log, -self::DEAD_REASON_LOG_MAX_ENTRIES);
        }

        $this->dead_reason_log = $log;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestDeadReasonEntry(): ?array
    {
        $log = $this->dead_reason_log;
        if (! is_array($log) || $log === []) {
            return null;
        }

        $last = end($log);

        return is_array($last) ? $last : null;
    }

    /**
     * Поля сброса при переходе dead → active: новый цикл жизни endpoint без накопленных ошибок.
     *
     * @return array<string, mixed>
     */
    public static function deadToActiveResetAttributes(?CarbonInterface $now = null): array
    {
        $timestamp = $now ?? now();

        return [
            'status' => self::STATUS_ACTIVE,
            'failure_count' => 0,
            'last_error' => null,
            'last_failed_at' => null,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * Реанимирует endpoint из dead в active и обнуляет счётчик/текст ошибок.
     */
    public function reactivateFromDead(?CarbonInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_DEAD) {
            return false;
        }

        $this->update(self::deadToActiveResetAttributes($now));

        return true;
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(PocketInstrument::class, 'proxy_endpoint_id');
    }

    /** Прокси, доступные для назначения инструментам: только status=active. */
    public function scopeReadyForAssignment($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
