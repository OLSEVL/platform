<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TimeDebugPanelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimeDebugPanelSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_disabled(): void
    {
        $this->assertFalse(TimeDebugPanelSettings::isEnabled());
    }

    public function test_settings_page_shows_toggle(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Диагностика времени', false)
            ->assertSee('time_debug_panel_enabled', false);
    }

    public function test_can_enable_and_disable_from_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.ui.time-debug-panel'), [
                'time_debug_panel_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue(TimeDebugPanelSettings::isEnabled());
        $this->assertSame(
            '1',
            (string) DB::table('app_settings')->where('key', TimeDebugPanelSettings::SETTING_KEY)->value('value')
        );

        // Checkbox unchecked → поле не приходит; boolean() даёт false.
        $this->actingAs($user)
            ->post(route('settings.ui.time-debug-panel'), [])
            ->assertRedirect();

        $this->assertFalse(TimeDebugPanelSettings::isEnabled());
    }
}
