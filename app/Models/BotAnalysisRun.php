<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotAnalysisRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK = 'ok';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'bot_id',
        'instrument',
        'status',
        'skip_reason',
        'started_at',
        'finished_at',
        'latency_ms',
        'runtime_json',
        'market_snapshot_json',
        'decision_json',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'runtime_json' => 'array',
            'market_snapshot_json' => 'array',
            'decision_json' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function llmLogs(): HasMany
    {
        return $this->hasMany(BotLlmLog::class, 'bot_analysis_run_id');
    }
}
