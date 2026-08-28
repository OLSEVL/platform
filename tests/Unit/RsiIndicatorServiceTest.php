<?php

namespace Tests\Unit;

use App\Services\Indicators\RsiIndicatorService;
use PHPUnit\Framework\TestCase;

/**
 * RSI: при короткой истории (H1 после очистки) startIndex не должен
 * ломать seed MovingAverageHard — иначе packSeries отдаёт пустой series.
 */
class RsiIndicatorServiceTest extends TestCase
{
    /** @return list<array{time:int,open:float,high:float,low:float,close:float,volume:float}> */
    private function synthBars(int $n): array
    {
        $rows = [];
        $t = 1_700_000_000;
        $p = 1.0;
        for ($i = 0; $i < $n; $i++) {
            $p += (($i % 3) - 1) * 0.01;
            $rows[] = [
                'time' => $t + $i * 3600,
                'open' => $p,
                'high' => $p + 0.01,
                'low' => $p - 0.01,
                'close' => $p,
                'volume' => 1.0,
            ];
        }

        return $rows;
    }

    public function test_rsi_produces_points_with_length_plus_two_bars(): void
    {
        $svc = new RsiIndicatorService();
        // length=14 → первый валидный index при index > length+1, т.е. ≥16 баров.
        $out = $svc->compute($this->synthBars(18), ['length' => 14]);
        $pts = $out['series']['rsi'] ?? [];

        $this->assertNotEmpty($pts, 'RSI должен давать точки уже при ~18 барах (типичный H1)');
        $this->assertGreaterThan(0.0, (float) $pts[array_key_last($pts)]['value']);
        $this->assertLessThan(100.0, (float) $pts[array_key_last($pts)]['value']);
    }

    public function test_rsi_still_works_with_long_history(): void
    {
        $svc = new RsiIndicatorService();
        $out = $svc->compute($this->synthBars(80), ['length' => 14]);
        $pts = $out['series']['rsi'] ?? [];

        $this->assertGreaterThan(40, count($pts));
    }
}
