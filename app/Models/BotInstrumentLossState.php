<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Текущее количество подряд неотработавших сигналов для конкретной пары.
 * Храним отдельно от сделок, чтобы одинаково учитывать виртуальные и реальные входы.
 */
class BotInstrumentLossState extends Model
{
    protected $fillable = [
        'bot_id',
        'instrument',
        'consecutive_losses',
        'last_result',
        'last_settled_at',
    ];

    protected function casts(): array
    {
        return [
            'consecutive_losses' => 'integer',
            'last_settled_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
