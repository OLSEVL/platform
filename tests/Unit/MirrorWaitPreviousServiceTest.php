<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\User;
use App\Services\Mirror\MirrorWaitPreviousService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MirrorWaitPreviousServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_while_previous_executed_still_open_even_after_expiry(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [],
        ]);

        $previous = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now()->subMinutes(2),
            'expiry_time' => now()->subSecond(),
            'entry_price' => 1.1,
            'meta_json' => ['mirror_status' => 'executed'],
        ]);

        $next = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'GBPUSD_otc',
            'side' => 'PUT',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now(),
            'expiry_time' => now()->addMinute(),
            'entry_price' => 1.2,
            'meta_json' => [],
        ]);

        $svc = app(MirrorWaitPreviousService::class);
        $this->assertTrue($svc->hasBlockingMirroredTrade($user->id, $next->id));

        $previous->update([
            'status' => BotTrade::STATUS_SETTLED,
            'result' => BotTrade::RESULT_LOSS,
        ]);

        $this->assertFalse($svc->hasBlockingMirroredTrade($user->id, $next->id));
    }
}
