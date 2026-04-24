<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ProxyEndpoint extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COOLDOWN = 'cooldown';
    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'url',
        'status',
        'failure_count',
        'last_error',
        'last_failed_at',
        'last_success_at',
        'cooldown_until',
    ];

    protected function casts(): array
    {
        return [
            'failure_count' => 'integer',
            'last_failed_at' => 'datetime',
            'last_success_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(PocketInstrument::class, 'proxy_endpoint_id');
    }

    /**
     * Возвращает "рабочие сейчас" прокси:
     * - status=active всегда подходит;
     * - status=cooldown подходит только после истечения cooldown_until.
     */
    public function scopeReadyForAssignment($query)
    {
        $now = Carbon::now();

        return $query->where(function ($q) use ($now) {
            $q->where('status', self::STATUS_ACTIVE)
                ->orWhere(function ($qq) use ($now) {
                    $qq->where('status', self::STATUS_COOLDOWN)
                        ->whereNotNull('cooldown_until')
                        ->where('cooldown_until', '<=', $now);
                });
        });
    }
}

