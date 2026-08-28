<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\InstrumentCandle;
use App\Models\User;
use App\Services\CandleService;
use App\Services\ChartIndicatorInstanceService;
use App\Support\DemarkerDualFastConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Экземпляры DeMarker (и API /indicators/data) на разных dashboard-TF.
 */
class ChartIndicatorInstanceTimeframeTest extends TestCase
{
    use RefreshDatabase;

    private const INSTRUMENT = 'EURUSD_otc';

    /** @var list<int> */
    private const TIMEFRAMES_TO_CHECK = [
        CandleService::TF_S30,
        CandleService::TF_S15,
        CandleService::TF_S5,
        1,
        5,
        15,
        60,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedS5Candles(self::INSTRUMENT, 10000);
    }

    public function test_indicators_data_returns_demarker_series_on_all_dashboard_timeframes(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Dual DeMarker TF',
            'type' => DemarkerDualFastConfig::BOT_TYPE,
            'is_active' => true,
            'config' => DemarkerDualFastConfig::defaultConfig(),
        ]);

        $service = app(ChartIndicatorInstanceService::class);
        $instances = $service->syncTradingInstances($bot);
        $this->assertCount(2, $instances);
        $ids = array_column($instances, 'id');

        foreach (self::TIMEFRAMES_TO_CHECK as $tf) {
            $response = $this->actingAs($user)->getJson(route('bots.indicators.data', $bot).'?'.http_build_query([
                'instrument' => self::INSTRUMENT,
                'timeframe' => $tf,
                'instances' => $ids,
            ]));

            $response->assertOk("timeframe {$tf} should return 200");
            $rows = $response->json('indicator_instances');
            $this->assertIsArray($rows, "timeframe {$tf}: indicator_instances must be array");
            $this->assertCount(2, $rows, "timeframe {$tf}: expected 2 demarker instances");

            foreach ($rows as $row) {
                $this->assertSame('demarker', $row['type'], "timeframe {$tf}: type demarker");
                $this->assertSame('dem', $row['series_key'], "timeframe {$tf}: series_key dem");
                $dem = $row['series']['dem'] ?? [];
                $this->assertNotEmpty($dem, "timeframe {$tf}: instance {$row['id']} dem series must not be empty");
            }
        }
    }

    public function test_compute_for_chart_demarker_on_all_dashboard_timeframes(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Dual DeMarker compute',
            'type' => DemarkerDualFastConfig::BOT_TYPE,
            'is_active' => true,
            'config' => DemarkerDualFastConfig::defaultConfig(),
        ]);

        $service = app(ChartIndicatorInstanceService::class);
        $ids = array_column($service->syncTradingInstances($bot), 'id');
        $candle = app(CandleService::class);
        $since = time() - 86400;

        foreach (self::TIMEFRAMES_TO_CHECK as $tf) {
            $rows = $candle->seriesForDashboard(self::INSTRUMENT, $tf, $since, null, 500);
            $this->assertNotEmpty($rows, "timeframe {$tf}: candles must exist after seed");

            $out = $service->computeForChart($bot, $ids, $rows);
            $this->assertCount(2, $out, "timeframe {$tf}: two instances computed");
            foreach ($out as $item) {
                $this->assertNotEmpty(
                    $item['series']['dem'] ?? [],
                    "timeframe {$tf}: dem series empty for instance {$item['id']}"
                );
            }
        }
    }

    private function seedS5Candles(string $instrument, int $count): void
    {
        $base = (int) (floor(time() / 5) * 5) - (5 * $count);
        $now = now();
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $base + ($i * 5);
            $price = 1.0 + sin($i / 12.0) * 0.02;
            $batch[] = [
                'instrument' => $instrument,
                'timeframe' => CandleService::TF_S5,
                'time' => $t,
                'open' => $price,
                'high' => $price + 0.005,
                'low' => $price - 0.005,
                'close' => $price + 0.001,
                'volume' => 100.0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($batch, 500) as $chunk) {
            InstrumentCandle::query()->insert($chunk);
        }
    }
}
