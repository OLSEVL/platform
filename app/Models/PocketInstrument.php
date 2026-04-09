<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PocketInstrument extends Model
{
    protected $fillable = [
        'instrument', 'display_name', 'priority',
        'session_token', 'uid', 'is_demo', 'is_active', 'proxy_url',
        'current_payout', 'last_payout_at', 'payout_source',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'current_payout' => 'decimal:2',
            'last_payout_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrderByPriority($query)
    {
        return $query->orderByDesc('priority')->orderBy('instrument');
    }
}
