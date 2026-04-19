<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PocketInstrument extends Model
{
    protected $fillable = [
        'instrument', 'display_name', 'priority',
        'session_token', 'uid', 'is_demo', 'is_active', 'proxy_url',
        'current_payout', 'last_payout_at', 'payout_source',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'current_payout' => 'decimal:2',
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

    public function scopeOrderByPriority($query)
    {
        return $query->orderByDesc('priority')->orderBy('instrument');
    }
}
