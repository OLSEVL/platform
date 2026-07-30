<?php

namespace Tests\Unit;

use App\Services\Indicators\AtrIndicatorService;
use App\Services\Indicators\AdxIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\EnvelopsIndicatorService;
use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\KeltnerIndicatorService;
use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\OsEngineCandleMath;
use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Services\Indicators\SuperTrendIndicatorService;
use App\Services\Indicators\VwmaIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * Golden / parity tests для классических индикаторов OsEngine (v1).
 * JSON-фикстура — регрессионный lockfile алгоритма; плюс независимые проверки формул.
 */
class OsEngineClassicIndicatorsTest extends TestCase
{
    /** @return array{rows: list<array<string,mixed>>, params: array<string,mixed>, at: array<string,array<string,float>>} */
    private function golden(): array
    {
        $path = dirname(__DIR__).'/Fixtures/os_engine_classic_indicators_golden.json';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true);
        $this->assertIsArray($data);

        return $data;
    }

    public function test_golden_fixture_matches_all_services(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $p = $g['params'];

        $sma = (new SmaIndicatorService)->computeSeries($rows, $p['sma']);
        $ema = (new EmaIndicatorService)->computeSeries($rows, $p['ema']);
        $bb = (new BollingerIndicatorService)->computeSeries($rows, $p['bollinger']);
        $rsi = (new RsiIndicatorService)->computeSeries($rows, $p['rsi']);
        $atr = (new AtrIndicatorService)->computeSeries($rows, $p['atr']);
        $macd = (new MacdIndicatorService)->computeSeries($rows, $p['macd']);
        $stoch = (new StochasticIndicatorService)->computeSeries($rows, $p['stochastic']);
        $cci = (new CciIndicatorService)->computeSeries($rows, $p['cci']);
        $vwma = (new VwmaIndicatorService)->computeSeries($rows, $p['vwma']);
        $keltner = (new KeltnerIndicatorService)->computeSeries($rows, $p['keltner']);
        $envelops = (new EnvelopsIndicatorService)->computeSeries($rows, $p['envelops']);
        $sar = (new ParabolicSarIndicatorService)->computeSeries($rows, $p['parabolic_sar']);
        $superTrend = (new SuperTrendIndicatorService)->computeSeries($rows, $p['supertrend']);
        $adx = (new AdxIndicatorService)->computeSeries($rows, $p['adx']);

        foreach ($g['at'] as $idxStr => $expect) {
            $i = (int) $idxStr;
            $this->assertEqualsWithDelta($expect['sma'], $sma[$i], 1e-8, "sma@$i");
            $this->assertEqualsWithDelta($expect['ema'], $ema[$i], 1e-8, "ema@$i");
            $this->assertEqualsWithDelta($expect['bb_mid'], $bb['mid'][$i], 1e-8, "bb_mid@$i");
            $this->assertEqualsWithDelta($expect['bb_up'], $bb['up'][$i], 1e-6, "bb_up@$i");
            $this->assertEqualsWithDelta($expect['bb_down'], $bb['down'][$i], 1e-6, "bb_down@$i");
            $this->assertEqualsWithDelta($expect['rsi'], $rsi[$i], 1e-2, "rsi@$i");
            $this->assertEqualsWithDelta($expect['atr'], $atr[$i], 1e-9, "atr@$i");
            $this->assertEqualsWithDelta($expect['macd'], (float) $macd['macd'][$i], 1e-8, "macd@$i");
            $this->assertEqualsWithDelta($expect['macd_signal'], (float) $macd['signal'][$i], 1e-8, "macd_signal@$i");
            $this->assertEqualsWithDelta($expect['macd_hist'], (float) $macd['histogram'][$i], 1e-8, "macd_hist@$i");
            $this->assertEqualsWithDelta($expect['stoch_k'], $stoch['k'][$i], 1e-2, "stoch_k@$i");
            $this->assertEqualsWithDelta($expect['stoch_d'], $stoch['d'][$i], 1e-2, "stoch_d@$i");
            // CCI: null на прогреве в golden записан как 0.
            $cciGot = $cci[$i] === null ? 0.0 : (float) $cci[$i];
            $this->assertEqualsWithDelta($expect['cci'], $cciGot, 1e-5, "cci@$i");
            $this->assertEqualsWithDelta($expect['vwma'], $vwma[$i], 1e-8, "vwma@$i");
            $this->assertEqualsWithDelta($expect['keltner_mid'], $keltner['mid'][$i], 1e-8, "keltner_mid@$i");
            $this->assertEqualsWithDelta($expect['keltner_up'], $keltner['up'][$i], 1e-6, "keltner_up@$i");
            $this->assertEqualsWithDelta($expect['keltner_down'], $keltner['down'][$i], 1e-6, "keltner_down@$i");
            $this->assertEqualsWithDelta($expect['envelops_mid'], $envelops['mid'][$i], 1e-8, "envelops_mid@$i");
            $this->assertEqualsWithDelta($expect['envelops_up'], $envelops['up'][$i], 1e-6, "envelops_up@$i");
            $this->assertEqualsWithDelta($expect['envelops_down'], $envelops['down'][$i], 1e-6, "envelops_down@$i");
            $this->assertEqualsWithDelta($expect['parabolic_sar'], $sar[$i], 1e-8, "parabolic_sar@$i");
            $this->assertEqualsWithDelta($expect['supertrend'], $superTrend['center'][$i], 1e-8, "supertrend@$i");
            $this->assertEqualsWithDelta($expect['adx'], $adx['adx'][$i], 1e-4, "adx@$i");
            $this->assertEqualsWithDelta($expect['adx_plus'], $adx['plus'][$i], 1e-4, "adx_plus@$i");
            $this->assertEqualsWithDelta($expect['adx_minus'], $adx['minus'][$i], 1e-4, "adx_minus@$i");
        }
    }

    /** MACD = EMA(fast)−EMA(slow); hist = macd−signal; прогрев с max(fast,slow). */
    public function test_macd_equals_ema_diff_independent(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $fast = 3;
        $slow = 5;
        $index = 10;
        $emaSvc = new EmaIndicatorService;
        $emaFast = $emaSvc->computeSeries($rows, ['length' => $fast, 'candle_point' => 'Close']);
        $emaSlow = $emaSvc->computeSeries($rows, ['length' => $slow, 'candle_point' => 'Close']);
        $expected = round($emaFast[$index] - $emaSlow[$index], 8);
        $macd = (new MacdIndicatorService)->computeSeries($rows, [
            'length_fast' => $fast,
            'length_slow' => $slow,
            'length_signal' => 3,
        ]);
        $this->assertEqualsWithDelta($expected, (float) $macd['macd'][$index], 1e-12);
        $this->assertNull($macd['macd'][$slow - 1]);
    }

    /** Stochastic: до Period1/%K-прогрева значения 0; после — %K в диапазоне 0..100. */
    public function test_stochastic_warmup_and_range(): void
    {
        $g = $this->golden();
        $stoch = (new StochasticIndicatorService)->computeSeries($g['rows'], [
            'period1' => 5,
            'period2' => 3,
            'period3' => 3,
        ]);
        $this->assertSame(0.0, $stoch['k'][5]);
        $this->assertSame(0.0, $stoch['d'][5]);
        // GetK стартует с index >= period2+period3+3 (=9)
        $this->assertGreaterThan(0.0, $stoch['k'][10]);
        $this->assertGreaterThanOrEqual(0.0, $stoch['k'][20]);
        $this->assertLessThanOrEqual(100.0, $stoch['k'][20]);
    }

    /** CCI: прогрев до index<=length; формула (price−MA)/(0.015×meanDev). */
    public function test_cci_warmup_and_independent_formula(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $cci = (new CciIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Typical',
        ]);
        $this->assertNull($cci[$length]); // index - length <= 0
        $this->assertNotNull($cci[$length + 1]);

        $index = 10;
        $sum = 0.0;
        for ($i = $index; $i > $index - $length; $i--) {
            $sum += OsEngineCandleMath::candlePoint($rows[$i], 'Typical');
        }
        $ma = $sum / $length;
        $md = 0.0;
        for ($i = $index; $i > $index - $length; $i--) {
            $md += abs($ma - OsEngineCandleMath::candlePoint($rows[$i], 'Typical'));
        }
        $expected = round(
            (OsEngineCandleMath::candlePoint($rows[$index], 'Typical') - $ma) / ($md * 0.015 / $length),
            5
        );
        $this->assertEqualsWithDelta($expected, (float) $cci[$index], 1e-12);
    }

    /** VWMA = Σ(price×vol)/Σ(vol); при length>index → 0. */
    public function test_vwma_equals_volume_weighted_average(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $index = 10;
        $num = 0.0;
        $den = 0.0;
        for ($i = $index; $i > $index - $length; $i--) {
            $vol = (float) ($rows[$i]['volume'] ?? 0);
            $num += (float) $rows[$i]['close'] * $vol;
            $den += $vol;
        }
        $expected = $den != 0.0 ? $num / $den : 0.0;
        $vwma = (new VwmaIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'candle_point' => 'Close',
        ]);
        $this->assertEqualsWithDelta($expected, $vwma[$index], 1e-12);
        $this->assertSame(0.0, $vwma[$length - 1]);
    }

    /** Независимая проверка SMA = среднее Close[index-length+1 .. index] (OsEngine Summ). */
    public function test_sma_matches_independent_average(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $index = 10;
        $sum = 0.0;
        for ($i = $index - $length + 1; $i <= $index; $i++) {
            $sum += (float) $rows[$i]['close'];
        }
        $expected = $sum / $length;
        $got = (new SmaIndicatorService)->computeSeries($rows, ['length' => $length, 'candle_point' => 'Close']);
        $this->assertEqualsWithDelta($expected, $got[$index], 1e-12);
        $this->assertSame(0.0, $got[$length - 1]); // length > index → 0
    }

    /** EMA seed на index==length = SMA окна; шаг a=2/(len+1). */
    public function test_ema_seed_and_step_independent(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $series = (new EmaIndicatorService)->computeSeries($rows, ['length' => $length, 'candle_point' => 'Close']);

        $seedSum = 0.0;
        for ($i = 1; $i <= $length; $i++) {
            $seedSum += (float) $rows[$i]['close'];
        }
        $seed = $seedSum / $length;
        $this->assertEqualsWithDelta($seed, $series[$length], 1e-8);

        $a = round(2.0 / ($length + 1), 8);
        $next = $seed + ($a * ((float) $rows[$length + 1]['close'] - $seed));
        $this->assertEqualsWithDelta(round($next, 8), $series[$length + 1], 1e-8);
    }

    /** ATR: TR[1] = max(H-L, |prevC-H|, |prevC-L|); seed SMA TR на length-1. */
    public function test_atr_true_range_and_seed_independent(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $tr = [];
        $tr[0] = 0.0;
        for ($i = 1; $i < count($rows); $i++) {
            $hiToLow = abs((float) $rows[$i]['high'] - (float) $rows[$i]['low']);
            $closeToHigh = abs((float) $rows[$i - 1]['close'] - (float) $rows[$i]['high']);
            $closeToLow = abs((float) $rows[$i - 1]['close'] - (float) $rows[$i]['low']);
            $tr[$i] = max($hiToLow, $closeToHigh, $closeToLow);
        }
        $seed = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $seed += $tr[$i];
        }
        $seed /= $length;
        $series = (new AtrIndicatorService)->computeSeries($rows, ['length' => $length, 'series_type' => 'Absolute']);
        $this->assertEqualsWithDelta(round($seed, 9), $series[$length - 1], 1e-9);
    }

    public function test_candle_point_median_and_typical(): void
    {
        $c = ['open' => 1.0, 'high' => 4.0, 'low' => 2.0, 'close' => 3.0, 'time' => 1, 'volume' => 0];
        $this->assertSame(3.0, OsEngineCandleMath::candlePoint($c, 'Median'));
        $this->assertSame(3.0, OsEngineCandleMath::candlePoint($c, 'Typical'));
    }

    /** RSI OsEngine: тёплый старт ~ length+21 из-за окна startIndex = index-length-20. */
    public function test_rsi_warmup_before_length_plus_21_is_zero(): void
    {
        $g = $this->golden();
        $rsi = (new RsiIndicatorService)->computeSeries($g['rows'], ['length' => 5]);
        $this->assertSame(0.0, $rsi[25]);
        $this->assertGreaterThan(0.0, $rsi[26]);
    }

    /** Envelops: up/down = SMA ± SMA×dev/100; прогрев index<=length. */
    public function test_envelops_percent_bands(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $length = 5;
        $dev = 2.0;
        $index = 10;
        $series = (new EnvelopsIndicatorService)->computeSeries($rows, [
            'length' => $length,
            'deviation' => $dev,
        ]);
        $this->assertSame(0.0, $series['mid'][$length]);
        $sma = OsEngineCandleMath::smaAt($rows, $index, $length, 'Close');
        $this->assertEqualsWithDelta($sma, $series['mid'][$index], 1e-12);
        $this->assertEqualsWithDelta(round($sma + $sma * $dev / 100, 6), $series['up'][$index], 1e-12);
        $this->assertEqualsWithDelta(round($sma - $sma * $dev / 100, 6), $series['down'][$index], 1e-12);
    }

    /** ADX: до length*2-1 значение 0; после — в диапазоне 0..100. */
    public function test_adx_warmup_and_range(): void
    {
        $g = $this->golden();
        $length = 5;
        $adx = (new AdxIndicatorService)->computeSeries($g['rows'], ['length' => $length]);
        $this->assertSame(0.0, $adx['adx'][$length * 2 - 2]);
        $this->assertGreaterThan(0.0, $adx['adx'][$length * 2 - 1]);
        $this->assertGreaterThanOrEqual(0.0, $adx['adx'][30]);
        $this->assertLessThanOrEqual(100.0, $adx['adx'][30]);
    }

    /** Keltner mid = EMA; up = mid + coef*EMA(ATR) после прогрева atr+atrEma. */
    public function test_keltner_mid_equals_ema(): void
    {
        $g = $this->golden();
        $rows = $g['rows'];
        $p = ['ema_period' => 5, 'atr_period' => 5, 'atr_ema_period' => 5, 'atr_coef' => 2.0];
        $k = (new KeltnerIndicatorService)->computeSeries($rows, $p);
        $ema = (new EmaIndicatorService)->computeSeries($rows, ['length' => 5, 'candle_point' => 'Close']);
        $index = 20;
        $this->assertEqualsWithDelta($ema[$index], $k['mid'][$index], 1e-12);
        $this->assertGreaterThan($k['mid'][$index], $k['up'][$index]);
        $this->assertLessThan($k['mid'][$index], $k['down'][$index]);
    }
}
