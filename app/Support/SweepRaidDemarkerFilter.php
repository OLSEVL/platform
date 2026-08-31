<?php

namespace App\Support;

use App\Services\Indicators\DemarkerIndicatorService;

/**
 * Опциональный фильтр DeMarker для бота Sweep / Raid Liquidity.
 *
 * Когда включён — сделка проходит только если на баре входа осциллятор
 * пересёк зону в сторону сигнала (как Strong Trend + DeMarker):
 * CALL — выход вверх из нижней зоны; PUT — выход вниз из верхней.
 */
final class SweepRaidDemarkerFilter
{
    /** @return array{enabled:bool,period:int,oversold:float,overbought:float} */
    public static function parse(array $cfg): array
    {
        $oversoldPct = (float) ($cfg['srl_demarker_oversold'] ?? 10);
        $overboughtPct = (float) ($cfg['srl_demarker_overbought'] ?? 90);

        return [
            'enabled' => (bool) ($cfg['srl_demarker_enabled'] ?? false),
            'period' => max(1, min(500, (int) ($cfg['srl_demarker_period'] ?? 6))),
            'oversold' => max(0.0, min(1.0, $oversoldPct / 100)),
            'overbought' => max(0.0, min(1.0, $overboughtPct / 100)),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $closedCandles
     */
    public static function passes(array $closedCandles, string $side, array $cfg, ?DemarkerIndicatorService $demarker = null): bool
    {
        if (! self::parse($cfg)['enabled']) {
            return true;
        }

        return self::crossingOnLastBar($closedCandles, $side, $cfg, $demarker) !== null;
    }

    /**
     * @param  list<array<string,mixed>>  $closedCandles
     * @return array{period:int,oversold:float,overbought:float,previous:float,current:float}|null
     */
    public static function crossingOnLastBar(
        array $closedCandles,
        string $side,
        array $cfg,
        ?DemarkerIndicatorService $demarker = null,
    ): ?array {
        $parsed = self::parse($cfg);
        if (! $parsed['enabled']) {
            return null;
        }
        if ($parsed['oversold'] >= $parsed['overbought']) {
            return null;
        }

        $needBars = $parsed['period'] + 2;
        if (count($closedCandles) < $needBars) {
            return null;
        }

        $service = $demarker ?? new DemarkerIndicatorService;
        $series = $service->computeSeries($closedCandles, ['period' => $parsed['period']]);
        $last = array_key_last($series);
        if ($last < 1) {
            return null;
        }

        $previous = (float) $series[$last - 1];
        $current = (float) $series[$last];
        $side = strtoupper($side);

        $isCallCross = $previous <= $parsed['oversold'] && $current > $parsed['oversold'];
        $isPutCross = $previous >= $parsed['overbought'] && $current < $parsed['overbought'];

        if ($side === 'CALL' && ! $isCallCross) {
            return null;
        }
        if ($side === 'PUT' && ! $isPutCross) {
            return null;
        }
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            return null;
        }

        return [
            'period' => $parsed['period'],
            'oversold' => $parsed['oversold'],
            'overbought' => $parsed['overbought'],
            'previous' => $previous,
            'current' => $current,
        ];
    }
}
