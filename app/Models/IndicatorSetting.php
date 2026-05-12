<?php

namespace App\Models;

use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndicatorSetting extends Model
{
    /** Индикатор Price Action (DGT) на графике бота; JSON — те же ключи, что в PriceActionDgtIndicatorService::defaultParams(). */
    public const KEY_PRICE_ACTION_DGT = 'price_action_dgt';

    /** Параметры оверлея Liquidity Swings (LuxAlgo), те же ключи, что LiquiditySwingsIndicatorService::defaultParams(). */
    public const KEY_LIQUIDITY_SWINGS = 'liquidity_swings';

    protected $fillable = ['bot_id', 'indicator_key', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Параметры Price Action DGT: дефолты сервиса ← legacy config бота ← строка indicator_settings (приоритет).
     */
    public static function mergedPriceActionDgtParams(Bot $bot): array
    {
        $defaults = PriceActionDgtIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_PRICE_ACTION_DGT]) && is_array($botConfig[self::KEY_PRICE_ACTION_DGT])
            ? $botConfig[self::KEY_PRICE_ACTION_DGT]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_PRICE_ACTION_DGT)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }

    /**
     * Параметры Liquidity Swings: дефолты сервиса ← legacy config бота ← indicator_settings (приоритет).
     */
    public static function mergedLiquiditySwingsParams(Bot $bot): array
    {
        $defaults = LiquiditySwingsIndicatorService::defaultParams();
        $botConfig = is_array($bot->config) ? $bot->config : [];
        $legacy = isset($botConfig[self::KEY_LIQUIDITY_SWINGS]) && is_array($botConfig[self::KEY_LIQUIDITY_SWINGS])
            ? $botConfig[self::KEY_LIQUIDITY_SWINGS]
            : [];
        $row = static::query()
            ->where('bot_id', $bot->id)
            ->where('indicator_key', self::KEY_LIQUIDITY_SWINGS)
            ->first();
        $db = is_array($row?->settings) ? $row->settings : [];

        return array_replace($defaults, $legacy, $db);
    }
}
