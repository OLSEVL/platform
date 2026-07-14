<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotMirrorTradesSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_mirror_checkbox_unchecked_by_default(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'CRC',
            'type' => 'candle_reversal_confirm',
            'is_active' => true,
            'config' => [
                'count' => 220,
                'min_closed_bars' => 200,
                'expiry_sec' => 300,
                'min_confidence' => 0.58,
                'min_payout' => 80,
                'stale_after_sec' => 60,
                'min_level_strength' => 58,
                'max_level_strength' => 100,
                'min_sources' => 1,
                'cluster_atr_mult_m5' => 0.25,
                'm5_rejection_enabled' => true,
                'm1_reversal_enabled' => true,
                'm1_reversal_confirm_bars' => 1,
            ],
        ]);

        $this->assertFalse($bot->isMirrorTradesEnabled());

        $html = $this->actingAs($user)
            ->get(route('bots.settings', $bot))
            ->assertOk()
            ->assertSee('Включить зеркалирование на PO', false)
            ->assertSee('name="mirror_trades_enabled"', false)
            ->getContent();

        // Без ключа в config чекбокс не должен быть отмечен.
        preg_match('/id="mirror_trades_enabled"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('checked', $m[0]);
    }

    public function test_can_enable_bot_mirror_via_settings(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Three',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [
                'count' => 100,
                'expiry_sec' => 60,
                'min_confidence' => 0.5,
                'min_payout' => 80,
                'stale_after_sec' => 60,
            ],
        ]);

        $this->assertFalse($bot->isMirrorTradesEnabled());

        $this->actingAs($user)
            ->put(route('bots.settings.update', $bot), [
                'name' => 'Three',
                'is_active' => '1',
                'mirror_trades_enabled' => '1',
                'tv_crosshair_enabled' => '1',
                'analysis_runs_logging_enabled' => '0',
                'count' => 100,
                'expiry_sec' => 60,
                'min_confidence' => 0.5,
                'min_payout' => 80,
                'stale_after_sec' => 60,
            ])
            ->assertRedirect(route('bots.settings', $bot));

        $bot->refresh();
        $this->assertTrue($bot->isMirrorTradesEnabled());
        $this->assertTrue((bool) ($bot->config['mirror_trades_enabled'] ?? false));
    }

    public function test_can_disable_bot_mirror_via_settings(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Three',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => [
                'count' => 100,
                'expiry_sec' => 60,
                'min_confidence' => 0.5,
                'min_payout' => 80,
                'stale_after_sec' => 60,
                'mirror_trades_enabled' => true,
            ],
        ]);

        $this->assertTrue($bot->isMirrorTradesEnabled());

        $this->actingAs($user)
            ->put(route('bots.settings.update', $bot), [
                'name' => 'Three',
                'is_active' => '1',
                'mirror_trades_enabled' => '0',
                'tv_crosshair_enabled' => '1',
                'analysis_runs_logging_enabled' => '0',
                'count' => 100,
                'expiry_sec' => 60,
                'min_confidence' => 0.5,
                'min_payout' => 80,
                'stale_after_sec' => 60,
            ])
            ->assertRedirect(route('bots.settings', $bot));

        $bot->refresh();
        $this->assertFalse($bot->isMirrorTradesEnabled());
        $this->assertFalse((bool) ($bot->config['mirror_trades_enabled'] ?? true));
    }
}
