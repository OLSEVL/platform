<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotBacktestRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'bot_id',
        'user_id',
        'status',
        'progress_pct',
        'from_ts',
        'to_ts',
        'config_snapshot_json',
        'params_json',
        'summary_json',
        'bars_total',
        'bars_done',
        'trades_total',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_pct' => 'integer',
            'from_ts' => 'integer',
            'to_ts' => 'integer',
            'config_snapshot_json' => 'array',
            'params_json' => 'array',
            'summary_json' => 'array',
            'bars_total' => 'integer',
            'bars_done' => 'integer',
            'trades_total' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BotBacktestTrade::class);
    }
}
