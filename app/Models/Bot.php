<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bot extends Model
{
    protected $fillable = ['user_id', 'name', 'type', 'config', 'is_active'];

    protected function casts(): array
    {
        return ['config' => 'array', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotRun::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(BotSignal::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(BotTrade::class);
    }
}
