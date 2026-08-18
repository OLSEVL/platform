<?php

namespace App\Services\Telegram;

use App\Services\Indicators\DemarkerIndicatorService;

/**
 * Собирает уровни/зону серии и серию DeMarker для PNG-алерта
 * Strong Trend + DeMarker. Панель осциллятора рисует TelegramChartImageRenderer.
 */
class StrongTrendDemarkerChartBuilder
{
    public function __construct(
        private readonly DemarkerIndicatorService $demarker,
    ) {}

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closedCandles
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>,oscillator:array<string,mixed>}
     */
    public function chartLevelsFromDecision(array $decision, array $closedCandles): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $streakLen = max(0, min(30, (int) ($features['streak_len'] ?? 0)));
        $period = max(1, min(500, (int) ($features['demarker_period'] ?? 6)));
        // В features зоны уже 0..1 (стратегия делит проценты формы на 100).
        $oversold = max(0.0, min(1.0, (float) ($features['demarker_oversold'] ?? 0.10)));
        $overbought = max(0.0, min(1.0, (float) ($features['demarker_overbought'] ?? 0.90)));

        $trend = (string) ($features['trend'] ?? $decision['trend'] ?? '');
        if (! in_array($trend, ['up', 'down'], true)) {
            $side = strtoupper((string) ($decision['side'] ?? ''));
            $trend = $side === 'PUT' ? 'down' : 'up';
        }
        $side = strtoupper((string) ($decision['side'] ?? ($trend === 'up' ? 'CALL' : 'PUT')));
        $arrow = $trend === 'up' ? '↑' : '↓';

        $levels = [];
        $zones = [];
        if ($streakLen > 0 && count($closedCandles) >= $streakLen) {
            /** @var list<array<string, mixed>> $streak */
            $streak = array_slice($closedCandles, -$streakLen);
            $lows = array_map(static fn (array $c): float => (float) ($c['low'] ?? 0), $streak);
            $highs = array_map(static fn (array $c): float => (float) ($c['high'] ?? 0), $streak);
            $zoneLow = min($lows);
            $zoneHigh = max($highs);
            if ($zoneHigh <= $zoneLow) {
                $zoneHigh = $zoneLow + 1e-8;
            }
            $zones[] = [
                'price_low' => $zoneLow,
                'price_high' => $zoneHigh,
            ];
            $startOpen = (float) ($streak[0]['open'] ?? 0);
            if ($startOpen > 0.0) {
                $levels[] = [
                    'price' => $startOpen,
                    'label' => sprintf('Старт %d×%s', $streakLen, $arrow),
                    'timeframe' => 'M1',
                    'style' => 'dashed',
                    'kind' => $trend === 'up' ? 'support' : 'resistance',
                ];
            }
        }

        $entry = null;
        $entryBar = $closedCandles !== [] ? $closedCandles[array_key_last($closedCandles)] : null;
        $entryClose = is_array($entryBar) ? (float) ($entryBar['close'] ?? 0) : 0.0;
        if ($entryClose > 0.0) {
            $entry = [
                'price' => $entryClose,
                'side' => $side,
                'label' => $streakLen > 0
                    ? sprintf('DeM + %d×%s', $streakLen, $arrow)
                    : 'DeMarker',
            ];
        }

        $values = $closedCandles !== []
            ? $this->demarker->computeSeries($closedCandles, ['period' => $period])
            : [];

        return [
            'levels' => $levels,
            'zones' => $zones,
            'entry' => $entry,
            'oscillator' => [
                'label' => sprintf('DeMarker(%d)', $period),
                'values' => $values,
                'min' => 0.0,
                'max' => 1.0,
                'oversold' => $oversold,
                'overbought' => $overbought,
            ],
        ];
    }
}
