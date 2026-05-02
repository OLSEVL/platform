<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotSignal extends Model
{
    protected $fillable = [
        'bot_id',
        'instrument',
        'side',
        'confidence',
        'payout_snapshot',
        'signal_at',
        'expiry_time',
        'reasons_json',
        'analysis_text',
        'features_json',
        'decision_version',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'payout_snapshot' => 'float',
            'signal_at' => 'datetime',
            'expiry_time' => 'datetime',
            'reasons_json' => 'array',
            'features_json' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function trade(): HasOne
    {
        return $this->hasOne(BotTrade::class, 'bot_signal_id');
    }

    public function llmLogs(): HasMany
    {
        return $this->hasMany(BotLlmLog::class, 'bot_signal_id');
    }
}
