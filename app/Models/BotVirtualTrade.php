<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Виртуальный сигнал нужен для проверки серии до настоящей сделки:
 * он не попадает в историю paper-сделок и никогда не зеркалируется на PO.
 */
class BotVirtualTrade extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLED = 'settled';

    public const RESULT_PENDING = 'pending';
    public const RESULT_WIN = 'win';
    public const RESULT_LOSS = 'loss';
    public const RESULT_DRAW = 'draw';

    protected $fillable = [
        'bot_id',
        'instrument',
        'side',
        'status',
        'result',
        'entry_time',
        'expiry_time',
        'settled_at',
        'entry_price',
        'expiry_price',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'entry_time' => 'datetime',
            'expiry_time' => 'datetime',
            'settled_at' => 'datetime',
            'entry_price' => 'float',
            'expiry_price' => 'float',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
