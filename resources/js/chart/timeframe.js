/**
 * PR5: общие TF-хелперы для dashboard/bot chart (без дубля в двух IIFE).
 * Секундные id: 4005=S5, 4015=S15, 4030=S30; иначе минуты → секунды.
 */
(function (global) {
    'use strict';

    function timeframeSeconds(tf) {
        if (tf === 4005) return 5;
        if (tf === 4015) return 15;
        if (tf === 4030) return 30;
        return Math.max(1, Number(tf) || 1) * 60;
    }

    function timeframeLabel(tf) {
        if (tf === 4005) return 'S5';
        if (tf === 4015) return 'S15';
        if (tf === 4030) return 'S30';
        return 'M' + String(tf);
    }

    function isSecondTimeframe(tf) {
        return tf === 4005 || tf === 4015 || tf === 4030;
    }

    global.PlatformChartTimeframe = {
        seconds: timeframeSeconds,
        label: timeframeLabel,
        isSecond: isSecondTimeframe,
    };
})(typeof window !== 'undefined' ? window : globalThis);
