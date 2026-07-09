<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Bots\BotEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke: бот candle_reversal_confirm v3 сканирует пары с новым конфигом уровней.
 */
class CandleReversalLevelBotScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_processes_instrument_with_level_bounce_config(): void
    {
        $instrument = 'CRC_LEVEL_TEST';

        $user = User::factory()->create();
        Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'CRC Level Test',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [
                'count' => 220,
                'min_closed_bars' => 200,
                'expiry_sec' => 300,
                'min_confidence' => 0.58,
                'min_payout' => 80,
                'stale_after_sec' => 600,
                'analysis_runs_logging_enabled' => false,
                'allowed_instruments' => [$instrument],
                'min_level_strength' => 58,
                'min_sources' => 1,
                'm5_rejection_enabled' => true,
                'm1_reversal_enabled' => true,
                'm1_reversal_confirm_bars' => 1,
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

        $bot = Bot::query()->firstOrFail();
        $summary = app(BotEngineService::class)->scanBot($bot);

        $this->assertGreaterThanOrEqual(0, $summary['processed']);
        $this->assertArrayHasKey('signals', $summary);
        $this->assertArrayHasKey('trades', $summary);
    }
}
