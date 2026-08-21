<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotBacktestTrade extends Model
{
    protected $fillable = [
        'bot_backtest_run_id',
        'instrument',
        'side',
        'result',
        'signal_ts',
        'entry_ts',
        'expiry_ts',
        'expiry_sec',
        'entry_price',
        'expiry_price',
        'payout_snapshot',
        'pnl',
        'confidence',
        'decision_json',
    ];

    protected function casts(): array
    {
        return [
            'signal_ts' => 'integer',
            'entry_ts' => 'integer',
            'expiry_ts' => 'integer',
            'expiry_sec' => 'integer',
            'entry_price' => 'float',
            'expiry_price' => 'float',
            'payout_snapshot' => 'float',
            'pnl' => 'float',
            'confidence' => 'float',
            'decision_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BotBacktestRun::class, 'bot_backtest_run_id');
    }
}
