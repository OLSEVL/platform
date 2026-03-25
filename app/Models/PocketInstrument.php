<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PocketInstrument extends Model
{
    protected $fillable = [
        'instrument', 'display_name', 'priority',
        'session_token', 'uid', 'is_demo', 'is_active', 'proxy_url',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
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
