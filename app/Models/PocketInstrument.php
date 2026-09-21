<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PocketInstrument extends Model
{
    /** Валютные пары (forex / FX OTC). */
    public const TYPE_CURRENCY = 1;

    /** Криптовалюты (crypto OTC / spot). */
    public const TYPE_CRYPTO = 2;

    /** Акции (stock OTC / spot). */
    public const TYPE_STOCK = 3;

    /** Сырьевые товары (commodities OTC / spot: нефть, металлы, газ). */
    public const TYPE_COMMODITY = 4;

    protected $fillable = [
        'instrument', 'display_name', 'type', 'priority',
        'session_token', 'uid', 'is_demo', 'is_active', 'proxy_endpoint_id',
        'current_payout', 'last_payout_at', 'last_payout_event_at', 'payout_is_stale', 'payout_source',
        'last_ws_connected_at', 'last_ws_error', 'last_ws_error_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'current_payout' => 'decimal:2',
            'payout_is_stale' => 'boolean',
        ];
    }

    /**
     * last_payout_at хранится как UTC-инстант.
     *
     * Почему не стандартный cast "datetime":
     * - Eloquent для строки "Y-m-d H:i:s" без timezone читает значение как локальное время приложения.
     * - Тогда 09:30 UTC превращается в "09:30 Europe/Kyiv" (вместо корректного 12:30 local time).
     *
     * Что делаем:
     * - GET: всегда интерпретируем сырое значение из БД как UTC.
     * - SET: всегда приводим вход к UTC и сериализуем в "Y-m-d H:i:s", чтобы в БД лежал стабильный UTC.
     */
    protected function lastPayoutAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    /**
     * Время последнего события payout от Pocket Options (не время POST ingest).
     */
    protected function lastPayoutEventAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    protected function lastWsConnectedAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    protected function lastWsErrorAt(): Attribute
    {
        return $this->utcTimestampAttribute();
    }

    /**
     * Единый UTC cast для payout-таймстампов: в БД храним "Y-m-d H:i:s" как UTC.
     */
    private function utcTimestampAttribute(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?Carbon {
                if ($value === null || $value === '') {
                    return null;
                }

                return Carbon::parse($value, 'UTC');
            },
            set: function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if ($value instanceof DateTimeInterface) {
                    return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
                }

                return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
            },
        );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Только валютные пары (type = 1). */
    public function scopeCurrency($query)
    {
        return $query->where('type', self::TYPE_CURRENCY);
    }

    /** Только криптовалюты (type = 2). */
    public function scopeCrypto($query)
    {
        return $query->where('type', self::TYPE_CRYPTO);
    }

    /** Только акции (type = 3). */
    public function scopeStock($query)
    {
        return $query->where('type', self::TYPE_STOCK);
    }

    /** Только сырьевые товары (type = 4). */
    public function scopeCommodity($query)
    {
        return $query->where('type', self::TYPE_COMMODITY);
    }

    public function scopeOrderByPriority($query)
    {
        return $query->orderByDesc('priority')->orderBy('instrument');
    }

    public function proxyEndpoint(): BelongsTo
    {
        return $this->belongsTo(ProxyEndpoint::class, 'proxy_endpoint_id');
    }
}
