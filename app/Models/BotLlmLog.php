<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLlmLog extends Model
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'bot_id',
        'bot_analysis_run_id',
        'bot_signal_id',
        'bot_trade_id',
        'instrument',
        'provider',
        'model',
        'status',
        'latency_ms',
        'request_json',
        'response_json',
        'request_text',
        'response_text',
        'usage_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_json' => 'array',
            'response_json' => 'array',
            'usage_json' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(BotAnalysisRun::class, 'bot_analysis_run_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(BotSignal::class, 'bot_signal_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(BotTrade::class, 'bot_trade_id');
    }
}
