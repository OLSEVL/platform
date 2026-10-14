<?php

namespace App\Models;

use App\Services\ExtensionSignalsBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    protected static function booted(): void
    {
        // Любой новый сигнал бота → Chrome-расширение (накопительный список).
        // afterCommit: create часто внутри DB::transaction — публикуем только после успешного commit.
        // Важно: long-running bots:impulse-live держит классы в RAM — после правок модели нужен restartScan.
        static::created(function (BotSignal $signal): void {
            $publish = static function () use ($signal): void {
                try {
                    app(ExtensionSignalsBroadcast::class)->publishFromSignal($signal);
                } catch (Throwable $e) {
                    Log::warning('extension_signals_publish_failed', [
                        'signal_id' => $signal->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            };

            if ($signal->getConnection()->transactionLevel() > 0) {
                $signal->getConnection()->afterCommit($publish);
            } else {
                $publish();
            }
        });
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
