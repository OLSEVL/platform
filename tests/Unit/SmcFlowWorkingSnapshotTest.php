<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\User;
use App\Support\SmcFlowWorkingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmcFlowWorkingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposes_bot_and_indicator_entry_params(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'SMC Flow bot',
            'type' => 'smc_flow',
            'is_active' => true,
            'config' => [
                'min_payout' => 85,
                'smc_require_ob' => true,
                'smc_three_br_confirm_max_bars' => 30,
                'smc_ob_touch_max_bars' => 3,
                'expiry_sec' => 180,
            ],
        ]);

        $snap = SmcFlowWorkingSnapshot::forBot($bot);

        $this->assertTrue($snap['bot']['require_ob']);
        $this->assertSame(30, $snap['bot']['confirm_max_bars']);
        $this->assertSame(85.0, $snap['bot']['min_payout']);
        $this->assertSame(180, $snap['bot']['expiry_sec']);
        $this->assertArrayHasKey('ut_key_value', $snap['indicator']);
        $this->assertArrayHasKey('lux_ob_length', $snap['indicator']);
        $this->assertArrayHasKey('skips_24h', $snap['stats']);
    }
}
