<?php

namespace App\Services\Telegram;

/**
 * Собирает chart_levels для TelegramChartImageRenderer из решения Strong Level.
 */
class StrongLevelTelegramChartBuilder
{
    /** Человекочитаемые подписи источников уровня на графике. */
    private const SOURCE_SHORT = [
        'h1_swing' => 'H1',
        'm15_swing' => 'M15',
        'mxwll_1d' => '1D',
        'mxwll_4h' => '4H',
        'pa_dgt_sr' => 'DGT',
        'vp_poc' => 'POC',
        'vp_vah' => 'VAH',
        'vp_val' => 'VAL',
        'rs_sr' => 'RS',
        'three_br_sr' => '3BR',
    ];

    /**
     * @param  array<string, mixed>  $decision
     * @return array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     */
    public function chartLevelsFromDecision(array $decision, ?float $entryPrice): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $levelPrice = isset($features['level_price']) ? (float) $features['level_price'] : 0.0;
        if ($levelPrice <= 0.0) {
            return ['levels' => [], 'zones' => [], 'entry' => null];
        }

        $strength = isset($features['level_strength']) ? (float) $features['level_strength'] : 0.0;
        $regime = (string) ($features['entry_regime'] ?? 'bounce');
        $regimeLabel = $regime === 'breakout' ? 'пробой' : 'отскок';
        $sources = is_array($features['level_sources'] ?? null) ? $features['level_sources'] : [];
        $sourceTags = [];
        foreach ($sources as $source) {
            $key = (string) $source;
            $sourceTags[] = self::SOURCE_SHORT[$key] ?? $key;
        }
        $sourceTags = array_values(array_unique($sourceTags));
        $sourcesText = $sourceTags !== [] ? implode('+', array_slice($sourceTags, 0, 4)) : 'level';

        $levelLabel = sprintf('Strong %.0f · %s · %s', $strength, $sourcesText, $regimeLabel);

        $levels = [[
            'price' => $levelPrice,
            'label' => $levelLabel,
            'timeframe' => 'M5',
            'style' => 'solid',
            'kind' => (string) ($features['level_kind'] ?? 'neutral'),
        ]];

        $zones = [];
        // Зона касания вокруг уровня (ATR M1 × типичный touch mult) — визуальный контекст на графике.
        $atrM1 = isset($features['atr_m1']) ? (float) $features['atr_m1'] : 0.0;
        if ($atrM1 > 0.0) {
            $touchHalf = $atrM1 * 0.55;
            $zones[] = [
                'price_low' => $levelPrice - $touchHalf,
                'price_high' => $levelPrice + $touchHalf,
            ];
        }

        $side = strtoupper((string) ($decision['side'] ?? ''));
        $entry = null;
        if ($entryPrice !== null && $entryPrice > 0.0) {
            $entry = [
                'price' => $entryPrice,
                'side' => $side,
                'label' => $side !== '' ? 'Вход '.$side : 'Вход',
            ];
        }

        return [
            'levels' => $levels,
            'zones' => $zones,
            'entry' => $entry,
        ];
    }
}
