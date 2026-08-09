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

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(BotAnalysisRun::class);
    }

    public function llmLogs(): HasMany
    {
        return $this->hasMany(BotLlmLog::class);
    }

    /** Параметры оверлей-индикаторов графика (отдельная таблица, не смешиваем с торговым config). */
    public function indicatorSettings(): HasMany
    {
        return $this->hasMany(IndicatorSetting::class);
    }

    /**
     * Зеркалирование сделок этого бота на PO (профиль → Торговля).
     * По умолчанию выключено: без ключа в config бот не зеркалит (нужно явно включить в настройках).
     */
    public function isMirrorTradesEnabled(): bool
    {
        $cfg = is_array($this->config) ? $this->config : [];

        if (! array_key_exists('mirror_trades_enabled', $cfg)) {
            return false;
        }

        return (bool) $cfg['mirror_trades_enabled'];
    }

    /** Бот-контейнер для ручных CALL/PUT с главной (не сканируется). */
    public function isManualDashboardBot(): bool
    {
        return $this->type === \App\Support\ManualTradeConfig::BOT_TYPE;
    }
}
