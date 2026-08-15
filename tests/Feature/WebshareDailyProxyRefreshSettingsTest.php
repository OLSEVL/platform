<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WebshareProxyRefreshSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebshareDailyProxyRefreshSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_refresh_is_enabled_by_default(): void
    {
        // Новая настройка не требует seed/migration записи: отсутствие ключа
        // осознанно означает включённый ежедневный запрос к Webshare.
        $this->assertTrue(WebshareProxyRefreshSettings::isEnabled());
    }

    public function test_hourly_exhausted_refresh_is_enabled_by_default(): void
    {
        $this->assertTrue(WebshareProxyRefreshSettings::isHourlyOnExhaustedEnabled());
    }

    public function test_settings_page_shows_enabled_webshare_refresh_toggles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Ежедневно обновлять прокси Webshare в 00:00', false)
            ->assertSee('webshare_daily_proxy_refresh_enabled', false)
            ->assertSee('Каждый час обновлять прокси, если пул исчерпан', false)
            ->assertSee('webshare_hourly_exhausted_proxy_refresh_enabled', false);
    }

    public function test_user_can_disable_and_enable_webshare_refresh_schedules(): void
    {
        $user = User::factory()->create();

        // Неприсланные checkbox Laravel трактует как false — оба расписания выключаются.
        $this->actingAs($user)
            ->post(route('settings.proxies.daily-refresh'), [])
            ->assertRedirect();

        $this->assertFalse(WebshareProxyRefreshSettings::isEnabled());
        $this->assertFalse(WebshareProxyRefreshSettings::isHourlyOnExhaustedEnabled());
        $this->assertSame(
            '0',
            (string) DB::table('app_settings')
                ->where('key', WebshareProxyRefreshSettings::SETTING_KEY)
                ->value('value'),
        );
        $this->assertSame(
            '0',
            (string) DB::table('app_settings')
                ->where('key', WebshareProxyRefreshSettings::HOURLY_ON_EXHAUSTED_SETTING_KEY)
                ->value('value'),
        );

        $this->actingAs($user)
            ->post(route('settings.proxies.daily-refresh'), [
                'webshare_daily_proxy_refresh_enabled' => '1',
                'webshare_hourly_exhausted_proxy_refresh_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue(WebshareProxyRefreshSettings::isEnabled());
        $this->assertTrue(WebshareProxyRefreshSettings::isHourlyOnExhaustedEnabled());
    }

    public function test_scheduled_command_skips_when_daily_refresh_is_disabled(): void
    {
        WebshareProxyRefreshSettings::setEnabled(false);

        // При выключенной настройке команда не делает сетевой запрос и завершает
        // работу успешно, поэтому её можно безопасно оставить в scheduler всегда.
        $this->artisan('proxies:refresh-webshare')
            ->expectsOutput('Ежедневное обновление Webshare выключено в /settings — пропуск.')
            ->assertExitCode(0);
    }

    public function test_hourly_exhausted_command_skips_when_disabled(): void
    {
        WebshareProxyRefreshSettings::setHourlyOnExhaustedEnabled(false);

        $this->artisan('proxies:refresh-webshare-if-exhausted')
            ->expectsOutput('Почасовое обновление Webshare при исчерпании пула выключено в /settings — пропуск.')
            ->assertExitCode(0);
    }

    public function test_hourly_exhausted_command_skips_when_pool_has_free_slots(): void
    {
        // Без активных инструментов is_exhausted=false → команда не ходит в Webshare.
        WebshareProxyRefreshSettings::setHourlyOnExhaustedEnabled(true);

        $this->artisan('proxies:refresh-webshare-if-exhausted')
            ->expectsOutputToContain('Пул не исчерпан')
            ->assertExitCode(0);
    }
}
