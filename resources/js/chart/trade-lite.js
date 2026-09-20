/**
 * PR4 trade-lite: осцилляторные слои создают отдельные LWC pane (~4 canvas каждый).
 * Overlay (SMA/EMA/BB на свечах) оставляем — они на pane 0.
 *
 * При boot.tradeLite=true снимаем галочки осцилляторов после loadLayerPrefs,
 * чтобы торговый экран был ближе к Binodex (1 pane свечей).
 * Пользователь может снова включить слой в UI в этой сессии.
 * Полный analyst-режим: CHART_TRADE_LITE=false в .env.
 */
(function (global) {
    'use strict';

    /** id чекбоксов, которые в applyClassic* делают chart.addPane(). */
    const CHART_OSC_LAYER_IDS = [
        'layer-rsi',
        'layer-atr',
        'layer-macd',
        'layer-stochastic',
        'layer-stoch-rsi',
        'layer-cci',
        'layer-adx',
        'layer-williams',
        'layer-ao',
        'layer-aroon',
        'layer-momentum',
        'layer-roc',
        'layer-osma',
        'layer-trix',
        'layer-cmo',
        'layer-demarker',
        'layer-rvi',
        'layer-ultimate',
        'layer-smi',
        'layer-stddev',
        'layer-ac',
        'layer-bulls-power',
        'layer-bears-power',
        'layer-dpo',
        'layer-qstick',
        'layer-vh-filter',
        'layer-efficiency-ratio',
        'layer-ravi',
        'layer-cog',
        'layer-mass-index',
        'layer-ivashov',
        'layer-atr-channel',
        'layer-volatility-average',
        'layer-volatility-average-channel',
        'layer-volatility-candles',
        'layer-volatility-average-twice',
        'layer-volatility-stages-aw',
        'layer-price-oscillator',
        'layer-adaptive-look-back',
    ];

    function isEnabled(boot) {
        return !!(boot && boot.tradeLite === true);
    }

    /**
     * Снять галочки осцилляторных слоёв.
     * @returns {number} сколько слоёв выключили
     */
    function stripOscillatorLayers(enabled) {
        if (!enabled) return 0;
        let n = 0;
        for (let i = 0; i < CHART_OSC_LAYER_IDS.length; i++) {
            const el = document.getElementById(CHART_OSC_LAYER_IDS[i]);
            if (el && el.checked) {
                el.checked = false;
                n += 1;
            }
        }
        return n;
    }

    global.PlatformChartTradeLite = {
        OSC_LAYER_IDS: CHART_OSC_LAYER_IDS,
        isEnabled: isEnabled,
        stripOscillatorLayers: stripOscillatorLayers,
    };
})(typeof window !== 'undefined' ? window : globalThis);
