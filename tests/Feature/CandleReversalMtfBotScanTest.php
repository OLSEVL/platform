<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use App\Services\CurrentCandleCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Проверяем полный путь: свечи в БД → scanBot → открытая сделка.
 * Без S5 в тесте используется legacy M1 (timeframe=1), M5 агрегируется из M1.
 */
class CandleReversalMtfBotScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_reversal_mtf_bot_creates_trade_when_pattern_present(): void
    {
        $instrument = 'REV_MTF_TEST';

        $user = User::factory()->create();
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'MTF Reversal Test',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [
                'count' => 120,
                'expiry_sec' => 300,
                'min_confidence' => 0.56,
                'stale_after_sec' => 600,
                'analysis_runs_logging_enabled' => false,
                'allowed_instruments' => [$instrument],
                'm5_reversal_min_wick_ratio' => 0.30,
                'm5_reversal_max_body_ratio' => 0.70,
                'm5_require_break_rev_extreme' => true,
                'm1_min_wick_ratio' => 0.22,
                'm1_min_body_ratio' => 0.15,
                'm1_require_wick_dominance' => true,
                'm1_wick_requirement_scope' => 'last',
            ],
        ]);

        PocketInstrument::query()->create([
            'instrument' => $instrument,
            'display_name' => $instrument,
            'priority' => 100,
            'session_token' => 'test-token',
            'uid' => 'test-uid',
            'is_demo' => true,
            'is_active' => true,
            'current_payout' => 92.0,
        ]);

        $this->seedReversalMtfM1Series($instrument);

        $bot = Bot::query()->firstOrFail();
        $summary = app(BotEngineService::class)->scanBot($bot);

        $this->assertGreaterThan(0, $summary['processed'], 'Инструмент не обработан: '.json_encode($summary));
        $this->assertGreaterThan(0, $summary['signals'], 'Сигнал не создан: '.json_encode($summary));

        $this->assertGreaterThanOrEqual(1, $summary['trades'], 'Ожидалась минимум одна сделка при синтетическом паттерне.');
        $this->assertGreaterThanOrEqual(1, BotTrade::query()->where('instrument', $instrument)->count());
    }

    public function test_entry_aligns_to_m5_bar_when_expiry_is_300_seconds(): void
    {
        $instrument = 'REV_MTF_ALIGN';

        $user = User::factory()->create();
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'MTF Align Test',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [
                'count' => 120,
                'expiry_sec' => 300,
                'min_confidence' => 0.56,
                'stale_after_sec' => 600,
                'allowed_instruments' => [$instrument],
                'm5_reversal_min_wick_ratio' => 0.30,
                'm5_reversal_max_body_ratio' => 0.70,
                'm5_require_break_rev_extreme' => true,
                'm1_min_wick_ratio' => 0.22,
                'm1_min_body_ratio' => 0.15,
                'm1_require_wick_dominance' => true,
                'm1_wick_requirement_scope' => 'last',
            ],
        ]);

        PocketInstrument::query()->create([
            'instrument' => $instrument,
            'display_name' => $instrument,
            'priority' => 100,
            'session_token' => 'test-token',
            'uid' => 'test-uid',
            'is_demo' => true,
            'is_active' => true,
            'current_payout' => 92.0,
        ]);

        $this->seedReversalMtfM1Series($instrument);

        $tFirstPatternBar = 1_700_004_000;
        $t0 = $tFirstPatternBar - 70 * 60;
        $partialStart = $t0 + 85 * 60;
        $m5BarStart = (int) (floor($partialStart / 300) * 300);
        $m5Open = 99.11;

        // «Сейчас» внутри открытого M5-бара, иначе движок отклонит выравнивание (бар уже закрыт).
        Carbon::setTestNow(Carbon::createFromTimestamp($partialStart + 20, 'UTC'));

        $partial = [
            'instrument' => $instrument,
            'time' => $partialStart,
            'open' => $m5Open,
            'high' => $m5Open + 0.05,
            'low' => $m5Open - 0.05,
            'close' => $m5Open + 0.02,
            'volume' => 1.0,
            'is_partial' => true,
            'updated_at' => time(),
        ];

        $this->mock(CurrentCandleCacheService::class, function ($mock) use ($instrument, $partial): void {
            $mock->shouldReceive('getPartial')->with($instrument)->andReturn($partial);
            $mock->shouldReceive('isFresh')->andReturn(true);
        });

        $bot = Bot::query()->firstOrFail();
        $summary = app(BotEngineService::class)->scanBot($bot);

        $this->assertGreaterThan(0, $summary['trades'], json_encode($summary));

        $trade = BotTrade::query()->where('instrument', $instrument)->firstOrFail();
        $this->assertSame($m5BarStart, (int) $trade->entry_time->timestamp);
        $this->assertSame($m5BarStart + 300, (int) $trade->expiry_time->timestamp);
        $this->assertEqualsWithDelta($m5Open, (float) $trade->entry_price, 0.00001);
        $this->assertTrue((bool) ($trade->meta_json['aligned_to_bar'] ?? false));

        Carbon::setTestNow();
    }

    /**
     * 85 минутных свечей: хвост из 15 баров даёт 3 агрегированных M5 (медвежий импульс → бычий разворот → бычий confirm).
     * Последние 3 M1 — бычьи с сильной нижней тенью на последней.
     */
    private function seedReversalMtfM1Series(string $instrument): void
    {
        // Время первого бара паттерна (индекс 70) кратно 300 с, чтобы 5 подряд M1 попали в один M5-бакет.
        $tFirstPatternBar = 1_700_004_000;
        $t0 = $tFirstPatternBar - 70 * 60;
        $rows = [];

        for ($i = 0; $i < 70; $i++) {
            $t = $t0 + $i * 60;
            $p = 100.0 + ($i % 5) * 0.02;
            $rows[] = [
                'instrument' => $instrument,
                'timeframe' => 1,
                'time' => $t,
                'open' => $p,
                'high' => $p + 0.05,
                'low' => $p - 0.05,
                'close' => $p + 0.01,
                'volume' => 1,
            ];
        }

        $tail = [
            [100.0, 100.2, 99.8, 99.7],
            [99.7, 99.8, 99.0, 99.1],
            [99.1, 99.2, 98.5, 98.6],
            [98.6, 98.8, 97.8, 97.9],
            [97.9, 98.0, 96.5, 96.8],
            [96.8, 97.2, 93.0, 96.0],
            [96.2, 96.5, 94.0, 94.8],
            [94.8, 95.0, 93.5, 94.5],
            [94.5, 95.2, 93.8, 94.8],
            [94.8, 97.5, 93.5, 97.3],
            [97.3, 98.2, 97.0, 97.8],
            [97.8, 98.5, 97.5, 98.2],
            [98.2, 98.8, 98.0, 98.6],
            [98.6, 99.0, 98.4, 98.9],
            // Тело должно удовлетворять m1_min_body_ratio: при range=1.0 нужно |close-open| >= 0.15,
            // иначе стратегия отклоняет последнюю из трёх M1 (раньше было 0.10 — strategy_no_signal).
            [98.84, 99.2, 98.2, 99.0],
        ];

        $baseIdx = 70;
        foreach ($tail as $k => $c) {
            $t = $t0 + ($baseIdx + $k) * 60;
            $rows[] = [
                'instrument' => $instrument,
                'timeframe' => 1,
                'time' => $t,
                'open' => $c[0],
                'high' => $c[1],
                'low' => $c[2],
                'close' => $c[3],
                'volume' => 1,
            ];
        }

        foreach ($rows as $row) {
            InstrumentCandle::query()->create($row);
        }
    }
}
