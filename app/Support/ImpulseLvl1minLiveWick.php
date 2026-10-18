<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Живая M1 по фитилю, как updateBotM1 LIVE_CANDLE_WICK_SIGNALS.
 * Закрытый бар и бэктест (partial=null) сюда не ходят.
 */
final class ImpulseLvl1minLiveWick
{
    private const EPSILON = 1e-12;

    /** Память между PHP-сканами: минута короткая, TTL с запасом на зависший воркер. */
    private const TTL_SEC = 180;

    /**
     * Максимальный уже достигнутый ход от open и его сторона (ничья → 0).
     *
     * @param  array{open?:float,high?:float,low?:float}  $candle
     * @return array{0:float,1:int}
     */
    public static function measureAndDirection(array $candle): array
    {
        $open = (float) ($candle['open'] ?? 0);
        $up = max(0.0, (float) ($candle['high'] ?? $open) - $open);
        $down = max(0.0, $open - (float) ($candle['low'] ?? $open));
        if ($up > $down + self::EPSILON) {
            return [$up, 1];
        }
        if ($down > $up + self::EPSILON) {
            return [$down, -1];
        }

        return [max($up, $down), 0];
    }

    /**
     * Redis current_candle — формирующийся S5. Для S15/S30 не подставляем его как бар стратегии:
     * open первого S5 в бакете + high/low/close по всем уже виденным S5 того же бакета (RAM, без MySQL).
     * periodSec=60 сюда не зовём: бот 47 по-прежнему получает сырой Redis-partial.
     *
     * @param  array<string, mixed>|null  $s5
     * @param  array<string, mixed>|null  $prevForming  предыдущий сложенный бар того же ТФ
     * @return array{time:int,open:float,high:float,low:float,close:float,volume:float,is_partial:bool,updated_at:int}|null
     */
    public static function foldS5IntoBarPeriod(?array $s5, int $periodSec, ?array $prevForming = null): ?array
    {
        if ($s5 === null || ($periodSec !== 15 && $periodSec !== 30)) {
            return null;
        }
        $t = (int) ($s5['effective_feed_time'] ?? ($s5['time'] ?? 0));
        if ($t <= 0) {
            return null;
        }
        $bucket = intdiv($t, $periodSec) * $periodSec;
        $incoming = [
            'time' => $bucket,
            'open' => (float) ($s5['open'] ?? 0),
            'high' => (float) ($s5['high'] ?? 0),
            'low' => (float) ($s5['low'] ?? 0),
            'close' => (float) ($s5['close'] ?? 0),
            'volume' => (float) ($s5['volume'] ?? 0),
            'is_partial' => true,
            'updated_at' => (int) ($s5['updated_at'] ?? time()),
        ];
        if ($prevForming === null || (int) ($prevForming['time'] ?? 0) !== $bucket) {
            return $incoming;
        }

        // Тот же 15с/30с бакет: open не переписываем, фитиль только расширяем.
        return [
            'time' => $bucket,
            'open' => (float) ($prevForming['open'] ?? $incoming['open']),
            'high' => max((float) ($prevForming['high'] ?? $incoming['high']), $incoming['high']),
            'low' => min((float) ($prevForming['low'] ?? $incoming['low']), $incoming['low']),
            'close' => $incoming['close'],
            'volume' => (float) ($prevForming['volume'] ?? 0) + $incoming['volume'],
            'is_partial' => true,
            'updated_at' => $incoming['updated_at'],
        ];
    }

    /**
     * Неполный poll не имеет права уменьшить уже виденные high/low.
     *
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $previous
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $incoming
     * @return array{time:int,open:float,high:float,low:float,close:float}
     */
    public static function mergeOhlc(array $previous, array $incoming): array
    {
        if ((int) $previous['time'] !== (int) $incoming['time']) {
            return $incoming;
        }

        return [
            'time' => (int) $incoming['time'],
            'open' => (float) $previous['open'],
            'high' => max((float) $previous['high'], (float) $incoming['high']),
            'low' => min((float) $previous['low'], (float) $incoming['low']),
            'close' => (float) $incoming['close'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array{time:int,open:float,high:float,low:float,close:float}  $incoming
     * @return array{
     *     candle:array{time:int,open:float,high:float,low:float,close:float},
     *     measure:float,
     *     direction:int,
     *     last_emitted_measure:float,
     *     best_profile_priority:int|null,
     *     candle_start:int
     * }
     */
    public static function advanceState(?array $stored, array $incoming): array
    {
        $start = (int) $incoming['time'];
        $sameMinute = $stored !== null && (int) ($stored['candle_start'] ?? 0) === $start;
        if ($sameMinute) {
            $prevCandle = is_array($stored['candle'] ?? null) ? $stored['candle'] : $incoming;
            $candle = self::mergeOhlc($prevCandle, $incoming);
            $lastEmitted = (float) ($stored['last_emitted_measure'] ?? 0);
            $best = $stored['best_profile_priority'] ?? null;
            $best = $best === null || $best === '' ? null : (int) $best;
        } else {
            $candle = $incoming;
            $lastEmitted = 0.0;
            $best = null;
        }
        [$measure, $direction] = self::measureAndDirection($candle);

        return [
            'candle' => $candle,
            'measure' => $measure,
            'direction' => $direction,
            'last_emitted_measure' => $lastEmitted,
            'best_profile_priority' => $best,
            'candle_start' => $start,
        ];
    }

    /**
     * Как 1min: MAX без profile_id не трогает фитильную память; цвет — только при новом экстремуме и строго лучшем приоритете.
     *
     * @param  list<array<string, mixed>>  $hits
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     * @return array{hits:list<array<string, mixed>>,state:array<string, mixed>}
     */
    public static function filterHits(array $hits, float $measure, array $config, array $state): array
    {
        $lastEmitted = (float) ($state['last_emitted_measure'] ?? 0);
        $best = $state['best_profile_priority'] ?? null;
        $best = $best === null || $best === '' ? null : (int) $best;
        $out = [];
        foreach ($hits as $hit) {
            $kind = (string) ($hit['kind'] ?? '');
            if ($kind === 'max') {
                $out[] = $hit;
                continue;
            }
            $profileId = (string) ($hit['strategy_id'] ?? $hit['id'] ?? '');
            if ($profileId === '') {
                continue;
            }
            if ($measure <= $lastEmitted + self::EPSILON) {
                continue;
            }
            $priority = ImpulseLvl1minConfig::profileLivePriority($config, $profileId);
            if ($best !== null && $priority >= $best) {
                continue;
            }
            $out[] = $hit;
            $lastEmitted = $measure;
            $best = $priority;
        }
        $state['last_emitted_measure'] = $lastEmitted;
        $state['best_profile_priority'] = $best;

        return ['hits' => $out, 'state' => $state];
    }

    public static function cacheKey(int $botId, string $instrument): string
    {
        return 'impulse_lvl_live_wick:'.$botId.':'.$instrument;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function load(int $botId, string $instrument): ?array
    {
        if ($botId < 1 || $instrument === '') {
            return null;
        }
        $raw = Cache::get(self::cacheKey($botId, $instrument));

        return is_array($raw) ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function save(int $botId, string $instrument, array $state): void
    {
        if ($botId < 1 || $instrument === '') {
            return;
        }
        Cache::put(self::cacheKey($botId, $instrument), $state, self::TTL_SEC);
    }
}
