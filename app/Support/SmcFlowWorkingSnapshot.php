<?php

namespace App\Support;

use App\Models\Bot;
use App\Models\BotAnalysisRun;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;

/**
 * Снимок параметров, по которым SMC Flow реально входит:
 * поля config бота + merged-настройки индикатора (UT / 3BR / OB).
 * Нужен на странице настроек, потому что часть рычагов живёт на отдельной
 * странице индикатора и иначе не видно, почему сделок мало.
 */
final class SmcFlowWorkingSnapshot
{
    /**
     * @return array{
     *   bot: array<string, mixed>,
     *   indicator: array<string, mixed>,
     *   stats: array<string, mixed>
     * }
     */
    public static function forBot(Bot $bot): array
    {
        $cfg = is_array($bot->config) ? $bot->config : [];
        $ind = IndicatorSetting::mergedSmcFlowParams($bot);

        $requireOb = (bool) ($cfg['smc_require_ob'] ?? true);
        $allowPattern = (bool) ($cfg['smc_three_br_allow_pattern'] ?? true);
        $confirmBars = max(1, min(120, (int) ($cfg['smc_three_br_confirm_max_bars'] ?? 30)));
        $obTouch = max(0, min(12, (int) ($cfg['smc_ob_touch_max_bars'] ?? 3)));
        $minPayout = (float) ($cfg['min_payout'] ?? 80);
        $minConfidence = (float) ($cfg['min_confidence'] ?? 0);
        $expirySec = max(120, min(600, (int) ($cfg['expiry_sec'] ?? 180)));
        $scanSec = (int) ($cfg['scan_interval_sec'] ?? 60);
        $staleSec = (int) ($cfg['stale_after_sec'] ?? 45);
        $count = (int) ($cfg['count'] ?? 500);

        return [
            'bot' => [
                'require_ob' => $requireOb,
                'allow_pattern' => $allowPattern,
                'confirm_max_bars' => $confirmBars,
                'ob_touch_max_bars' => $obTouch,
                'min_payout' => $minPayout,
                'min_confidence' => $minConfidence,
                'expiry_sec' => $expirySec,
                'scan_interval_sec' => $scanSec,
                'stale_after_sec' => $staleSec,
                'count' => $count,
                'min_closed_bars' => 80,
            ],
            'indicator' => [
                'ut_key_value' => (float) ($ind['ut_key_value'] ?? 2),
                'ut_atr_period' => (int) ($ind['ut_atr_period'] ?? 6),
                'three_br_type' => (string) ($ind['three_br_type'] ?? 'All'),
                'three_br_trend_type' => (string) ($ind['three_br_trend_type'] ?? 'None'),
                'three_br_trend_filter' => (string) ($ind['three_br_trend_filter'] ?? 'Aligned'),
                'lux_ob_length' => (int) ($ind['lux_ob_length'] ?? 5),
                'lux_ob_bull_count' => (int) ($ind['lux_ob_bull_count'] ?? 3),
                'lux_ob_bear_count' => (int) ($ind['lux_ob_bear_count'] ?? 3),
                'lux_ob_mitigation' => (string) ($ind['lux_ob_mitigation'] ?? 'Wick'),
            ],
            'stats' => self::recentStats($bot),
        ];
    }

    /**
     * @return array{signals_24h:int,trades_24h:int,skips_24h:int,skip_reasons:array<string,int>}
     */
    private static function recentStats(Bot $bot): array
    {
        $since = now()->subDay();
        $skipRows = BotAnalysisRun::query()
            ->where('bot_id', $bot->id)
            ->where('status', BotAnalysisRun::STATUS_SKIPPED)
            ->where('started_at', '>=', $since)
            ->selectRaw('skip_reason, count(*) as c')
            ->groupBy('skip_reason')
            ->pluck('c', 'skip_reason')
            ->all();
        $skipReasons = [];
        foreach ($skipRows as $reason => $count) {
            $key = is_string($reason) && $reason !== '' ? $reason : 'unknown';
            $skipReasons[$key] = (int) $count;
        }

        return [
            'signals_24h' => BotSignal::query()->where('bot_id', $bot->id)->where('signal_at', '>=', $since)->count(),
            'trades_24h' => BotTrade::query()->where('bot_id', $bot->id)->where('entry_time', '>=', $since)->count(),
            'skips_24h' => array_sum($skipReasons),
            'skip_reasons' => $skipReasons,
        ];
    }
}
