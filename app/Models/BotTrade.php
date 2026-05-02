<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotTrade extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const RESULT_PENDING = 'pending';

    public const RESULT_WIN = 'win';

    public const RESULT_LOSS = 'loss';

    public const RESULT_DRAW = 'draw';

    protected $fillable = [
        'bot_id',
        'bot_signal_id',
        'instrument',
        'side',
        'status',
        'result',
        'entry_time',
        'expiry_time',
        'settled_at',
        'entry_price',
        'expiry_price',
        'payout_snapshot',
        'pnl',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'entry_time' => 'datetime',
            'expiry_time' => 'datetime',
            'settled_at' => 'datetime',
            'entry_price' => 'float',
            'expiry_price' => 'float',
            'payout_snapshot' => 'float',
            'pnl' => 'float',
            'meta_json' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(BotSignal::class, 'bot_signal_id');
    }

    public function llmLogs(): HasMany
    {
        return $this->hasMany(BotLlmLog::class, 'bot_trade_id');
    }
}
