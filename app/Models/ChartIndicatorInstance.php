<?php

namespace App\Models;

use App\Support\ChartIndicatorInstanceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Экземпляр классического индикатора на графике бота.
 *
 * Отличается от indicator_settings: там один набор params на тип,
 * здесь — несколько независимых линий/панелей одного типа (RSI 9 + RSI 21).
 */
class ChartIndicatorInstance extends Model
{
    protected $fillable = [
        'bot_id',
        'indicator_type',
        'settings',
        'visible',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public static function isSupportedType(string $type): bool
    {
        return ChartIndicatorInstanceRegistry::isSupported($type);
    }
}
