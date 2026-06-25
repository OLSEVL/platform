<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramAnalystInstrumentService;
use App\Services\Telegram\TelegramAnalystReplyKeyboard;
use App\Services\Telegram\TelegramChartImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAnalystServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_instrument_list_input(): void
    {
        $list = TelegramAnalystInstrumentService::parseInstrumentListInput("EURUSD\nGBPUSD, AUDCAD");
        $this->assertSame(['EURUSD', 'GBPUSD', 'AUDCAD'], $list);
    }

    public function test_filtered_instruments_respects_min_payout_and_whitelist(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD',
            'display_name' => 'EUR/USD',
            'is_active' => true,
            'current_payout' => 90,
            'priority' => 1,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'LOWPAY',
            'display_name' => 'LOW',
            'is_active' => true,
            'current_payout' => 50,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'min_payout' => 80,
                'allowed_instruments' => ['EURUSD'],
            ],
        ]);

        $service = app(TelegramAnalystInstrumentService::class);
        $filtered = $service->filteredInstruments($bot);

        $this->assertCount(1, $filtered);
        $this->assertSame('EURUSD', $filtered->first()->instrument);
    }

    public function test_reply_keyboard_menu_and_skip_triggers(): void
    {
        $this->assertTrue(TelegramAnalystReplyKeyboard::isMenuTrigger(TelegramAnalystReplyKeyboard::CMD_MENU));
        $this->assertTrue(TelegramAnalystReplyKeyboard::isMenuTrigger(TelegramAnalystReplyKeyboard::BTN_MENU));
        $this->assertTrue(TelegramAnalystReplyKeyboard::isSkipTrigger(TelegramAnalystReplyKeyboard::CMD_SKIP));
        $this->assertTrue(TelegramAnalystReplyKeyboard::isSkipTrigger(TelegramAnalystReplyKeyboard::BTN_SKIP));
        $this->assertFalse(TelegramAnalystReplyKeyboard::isMenuTrigger('EURUSD'));
    }

    public function test_filtered_instruments_sorted_alphabetically(): void
    {
        foreach (['ZZZPAIR', 'AAA_otc', 'MMM'] as $i => $name) {
            PocketInstrument::query()->create([
                'instrument' => $name,
                'display_name' => $name,
                'is_active' => true,
                'current_payout' => 90 - $i,
                'priority' => 100 - $i,
            ]);
        }

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => ['min_payout' => 0],
        ]);

        $names = app(TelegramAnalystInstrumentService::class)
            ->filteredInstruments($bot)
            ->pluck('instrument')
            ->all();

        $this->assertSame(['AAA_otc', 'MMM', 'ZZZPAIR'], $names);
    }

    public function test_split_telegram_text_on_long_message(): void
    {
        $service = app(TelegramAlertService::class);
        $text = str_repeat("Строка анализа.\n\n", 500);
        $chunks = $service->splitTelegramText($text, 500);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(500, mb_strlen($chunk));
        }
    }

    public function test_chart_renderer_produces_png_when_gd_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not installed');
        }

        $renderer = app(TelegramChartImageRenderer::class);
        $candles = [];
        $price = 1.1;
        for ($i = 0; $i < 30; $i++) {
            $open = $price;
            $close = $price + 0.0002;
            $candles[] = [
                'open' => $open,
                'high' => $close + 0.0001,
                'low' => $open - 0.0001,
                'close' => $close,
            ];
            $price = $close;
        }

        $path = $renderer->render('EURUSD', $candles, [
            'levels' => [
                ['price' => 1.1005, 'kind' => 'support', 'timeframe' => 'M15', 'label' => 'M15 support', 'style' => 'solid'],
            ],
            'zones' => [],
            'entry' => ['price' => 1.1010, 'side' => 'CALL', 'label' => 'entry'],
        ]);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        @unlink($path);
    }
}
