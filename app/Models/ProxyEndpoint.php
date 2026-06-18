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
    ];

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
        ];
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
