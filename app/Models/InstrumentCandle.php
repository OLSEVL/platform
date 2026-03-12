<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrumentCandle extends Model
{
    protected $table = 'candles';

    protected $fillable = [
        'instrument', 'timeframe', 'time',
        'open', 'high', 'low', 'close', 'volume',
    ];

    protected function casts(): array
    {
        return [
            'time' => 'integer',
            'open' => 'float', 'high' => 'float', 'low' => 'float', 'close' => 'float', 'volume' => 'float',
            'timeframe' => 'integer',
        ];
    }

    public function scopeForInstrument($query, string $instrument)
    {
        return $query->where('instrument', $instrument);
    }

    public function scopeForTimeframe($query, int $timeframe)
    {
        return $query->where('timeframe', $timeframe);
    }
}
