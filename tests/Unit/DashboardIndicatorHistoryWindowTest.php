<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Services\DataCleanupService;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Окно индикаторов дашборда должно следовать since/limit с графика
 * и не превышать chartHistoryMaxSeconds (настройка очистки).
 */
class DashboardIndicatorHistoryWindowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Без таблицы app_settings: подменяем retention на 7 суток.
        $this->app->instance(DataCleanupService::class, new class extends DataCleanupService
        {
            public function getAutoCleanupRetentionHours(): ?int
            {
                return 168;
            }
        });
    }

    private function callResolve(
        int $timeframe,
        ?int $since = null,
        ?int $limit = null,
        int $defaultMaxBars = 2000,
        int $hardMaxBars = 5000,
    ): array {
        $controller = $this->app->make(DashboardController::class);
        $method = new ReflectionMethod(DashboardController::class, 'resolveIndicatorHistoryWindow');
        $method->setAccessible(true);

        return $method->invoke($controller, $timeframe, $since, $limit, $defaultMaxBars, $hardMaxBars);
    }

    public function test_client_since_and_limit_are_respected_within_retention_floor(): void
    {
        $windowSec = 168 * 3600;
        $floor = time() - $windowSec;

        // Клиент просит ~380 баров M1 (130 visible + 250 warmup).
        $clientSince = time() - (380 * 60);
        [$since, $maxBars] = $this->callResolve(1, $clientSince, 380);

        $this->assertSame(380, $maxBars);
        $this->assertGreaterThanOrEqual($floor, $since);
        $this->assertEqualsWithDelta($clientSince, $since, 2);
        // Не раздуваем до суток: окно ≈ limit×bar, не 24ч.
        $this->assertLessThan(12 * 3600, time() - $since);
    }

    public function test_since_older_than_retention_is_clamped_to_floor(): void
    {
        $windowSec = 168 * 3600;
        $tooOld = time() - $windowSec - 86400;

        [$since] = $this->callResolve(1, $tooOld, 500);
        $this->assertGreaterThanOrEqual(time() - $windowSec - 2, $since);
    }

    public function test_default_without_client_since_stays_bounded(): void
    {
        [$since, $maxBars] = $this->callResolve(1, null, null, 1500, 5000);

        $this->assertSame(1500, $maxBars);
        // Дефолт ≈ 1500 минут, не полный 7-дневный retention.
        $this->assertLessThan(2 * 24 * 3600, time() - $since);
    }

    public function test_hard_max_bars_cap(): void
    {
        [, $maxBars] = $this->callResolve(1, time() - 3600, 99999, 1500, 5000);
        $this->assertSame(5000, $maxBars);
    }

    public function test_indicator_since_limit_from_request(): void
    {
        $controller = $this->app->make(DashboardController::class);
        $method = new ReflectionMethod(DashboardController::class, 'indicatorSinceLimitFromRequest');
        $method->setAccessible(true);

        $req = Request::create('/x', 'GET', ['since' => '1700000000', 'limit' => '400']);
        [$since, $limit] = $method->invoke($controller, $req);

        $this->assertSame(1700000000, $since);
        $this->assertSame(400, $limit);
    }
}
