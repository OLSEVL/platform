<?php

namespace Tests\Unit;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Models\PocketInstrument;
use App\Models\User;
use App\Services\Telegram\StrongLevelTelegramHandler;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use App\Services\Telegram\StrongLevelTelegramChartBuilder;
use App\Services\Telegram\StrongTrendTelegramChartBuilder;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramAnalystHandler;
use App\Services\Telegram\TelegramAnalystInstrumentService;
use App\Services\Telegram\TelegramAnalystReplyKeyboard;
use App\Services\Telegram\TelegramChartImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $this->assertTrue(TelegramAnalystReplyKeyboard::isMenuTrigger('/start'));
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

    public function test_strong_level_alert_format_brief_and_detailed(): void
    {
        $service = app(TelegramAlertService::class);
        $decision = [
            'side' => 'CALL',
            'confidence' => 0.72,
            'reasons_short' => ['Отскок от support', 'M5 rejection'],
            'features' => [
                'level_price' => 1.0845,
                'level_strength' => 62.0,
                'entry_regime' => 'bounce',
                'h1_bias' => 'up',
                'level_sources' => ['pa_dgt_sr', 'mxwll_4h'],
            ],
        ];

        $brief = $service->formatStrongLevelAlertText('EURUSD_otc', $decision, 85.0, 180, 1.0846, 'brief');
        $this->assertStringContainsString('Strong Level', $brief);
        $this->assertStringContainsString('EURUSD_otc', $brief);
        $this->assertStringContainsString('1.08450', $brief);
        $this->assertStringNotContainsString('Источники:', $brief);

        $detailed = $service->formatStrongLevelAlertText('EURUSD_otc', $decision, 85.0, 180, 1.0846, 'detailed');
        $this->assertStringContainsString('Источники:', $detailed);
        $this->assertStringContainsString('Отскок от support', $detailed);

        $card = $service->formatStrongLevelAlertText('EURUSD_otc', $decision, 85.0, 180, 1.0846, 'card');
        $this->assertStringContainsString('EURUSD_otc · ВВЕРХ', $card);
        $this->assertStringContainsString('Отбой от уровня', $card);
        $this->assertStringContainsString('Цена подошла', $card);
        $this->assertStringContainsString('Статистика сигнала', $card);
        $this->assertStringContainsString('85%', $card);
    }

    public function test_strong_level_alert_skipped_when_telegram_disabled(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [
                'telegram_enabled' => false,
                'telegram_notify_on' => 'signal_trade',
            ],
        ]);

        $result = app(TelegramAlertService::class)->sendStrongLevelAlert(
            $bot,
            'EURUSD_otc',
            ['side' => 'CALL', 'features' => ['level_price' => 1.0, 'level_strength' => 60]],
            85.0,
            180
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('telegram_disabled', $result['error']);
    }

    public function test_strong_level_alert_format_uses_level_features(): void
    {
        $decision = [
            'side' => 'CALL',
            'confidence' => 0.72,
            'reasons_short' => ['Уровень support', 'M5 rejection'],
            'features' => [
                'level_price' => 1.0845,
                'level_strength' => 62.0,
                'entry_regime' => 'bounce',
                'level_kind' => 'support',
                'level_sources' => ['pa_dgt_sr'],
            ],
        ];
        $service = app(TelegramAlertService::class);
        $text = $service->formatStrongLevelAlertText('EURUSD_otc', $decision, 88.0, 300, 1.0846, 'detailed');
        $this->assertStringContainsString('Strong Level', $text);
        $this->assertStringContainsString('1.0845', $text);
    }

    public function test_strong_level_chart_levels_builder(): void
    {
        $builder = app(StrongLevelTelegramChartBuilder::class);
        $decision = [
            'side' => 'CALL',
            'features' => [
                'level_price' => 1.0845,
                'level_strength' => 62.0,
                'entry_regime' => 'bounce',
                'level_kind' => 'support',
                'level_sources' => ['pa_dgt_sr', 'mxwll_4h'],
                'atr_m1' => 0.00012,
            ],
        ];

        $chart = $builder->chartLevelsFromDecision($decision, 1.0846);
        $this->assertCount(1, $chart['levels']);
        $this->assertSame(1.0845, $chart['levels'][0]['price']);
        $this->assertStringContainsString('Strong 62', (string) $chart['levels'][0]['label']);
        $this->assertNotEmpty($chart['zones']);
        $this->assertSame(1.0846, $chart['entry']['price']);
        $this->assertSame('CALL', $chart['entry']['side']);
    }

    public function test_strong_trend_chart_levels_builder(): void
    {
        $builder = app(StrongTrendTelegramChartBuilder::class);
        $candles = [];
        $price = 1.1;
        for ($i = 0; $i < 10; $i++) {
            $open = $price;
            $close = $price + 0.00015;
            $candles[] = [
                'open' => $open,
                'high' => $close + 0.00005,
                'low' => $open - 0.00003,
                'close' => $close,
            ];
            $price = $close;
        }

        $decision = [
            'side' => 'CALL',
            'trend' => 'up',
            'features' => [
                'streak_len' => 6,
                'trend' => 'up',
            ],
        ];

        $chart = $builder->chartLevelsFromDecision($decision, $candles);
        $this->assertCount(1, $chart['levels']);
        $this->assertStringContainsString('Старт 6×↑', (string) $chart['levels'][0]['label']);
        $this->assertNotEmpty($chart['zones']);
        $this->assertSame('CALL', $chart['entry']['side']);
        $this->assertStringContainsString('6×↑', (string) $chart['entry']['label']);
    }

    public function test_strong_trend_chart_builder_wait_opposite_and_ma(): void
    {
        $builder = app(StrongTrendTelegramChartBuilder::class);
        $candles = [];
        $price = 1.1000;
        for ($i = 0; $i < 6; $i++) {
            $open = $price;
            $close = $price + 0.0004;
            $candles[] = [
                'open' => $open,
                'high' => $close + 0.00005,
                'low' => $open - 0.00003,
                'close' => $close,
            ];
            $price = $close;
        }
        $candles[] = [
            'open' => $price,
            'high' => $price + 0.00005,
            'low' => $price - 0.00035,
            'close' => $price - 0.00025,
        ];

        $decision = [
            'side' => 'CALL',
            'trend' => 'up',
            'features' => [
                'streak_len' => 6,
                'trend' => 'up',
                'wait_opposite_then_continue' => true,
                'pullback_ma_at_entry' => 1.1012,
                'pullback_ma_type' => 'ema',
                'pullback_ma_length' => 9,
            ],
        ];

        $chart = $builder->chartLevelsFromDecision($decision, $candles);
        $this->assertCount(2, $chart['levels']);
        $this->assertStringContainsString('EMA(9)', (string) $chart['levels'][1]['label']);
        $this->assertStringContainsString('контр', (string) $chart['entry']['label']);
        $this->assertSame('CALL', $chart['entry']['side']);
        $this->assertEqualsWithDelta($candles[6]['close'], $chart['entry']['price'], 1e-9);
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

    public function test_strong_level_llm_button_callback_markup(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $strong = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);

        $markup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup(
            $strong->config,
            $strong,
            'EURUSD_otc',
        );

        $this->assertNotNull($markup);
        $this->assertSame(StrongLevelTelegramLlmButton::BUTTON_LABEL, $markup['inline_keyboard'][0][0]['text']);
        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertNotNull($parsed);
        $this->assertSame($analyst->id, $parsed['analyst_bot_id']);
        $this->assertSame('EURUSD_otc', $parsed['instrument']);
    }

    public function test_strong_trend_telegram_llm_button_callback_markup(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $trend = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Trend Telegram',
            'type' => 'strong_trend_telegram',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);

        $markup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup(
            $trend->config,
            $trend,
            'GBPUSD_otc',
        );

        $this->assertNotNull($markup);
        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertNotNull($parsed);
        $this->assertSame($analyst->id, $parsed['analyst_bot_id']);
        $this->assertSame('GBPUSD_otc', $parsed['instrument']);
    }

    public function test_strong_trend_demarker_llm_button_callback_markup(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $demarker = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Trend + DeMarker',
            'type' => 'strong_trend_demarker',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);

        $markup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup(
            $demarker->config,
            $demarker,
            'EURUSD_otc',
        );

        $this->assertNotNull($markup);
        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertNotNull($parsed);
        $this->assertSame($analyst->id, $parsed['analyst_bot_id']);
        $this->assertSame('EURUSD_otc', $parsed['instrument']);
    }

    public function test_sweep_raid_liquidity_llm_button_callback_markup(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [],
        ]);
        $sweep = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Sweep / Raid Liquidity',
            'type' => 'sweep_raid_liquidity',
            'is_active' => true,
            'config' => ['telegram_analyst_llm_enabled' => true],
        ]);

        $markup = StrongLevelTelegramLlmButton::inlineKeyboardMarkup(
            $sweep->config,
            $sweep,
            'AUDCAD_otc',
        );

        $this->assertNotNull($markup);
        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertNotNull($parsed);
        $this->assertSame($analyst->id, $parsed['analyst_bot_id']);
        $this->assertSame('AUDCAD_otc', $parsed['instrument']);
    }

    public function test_hybrid_analyst_self_llm_button_markup(): void
    {
        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst SMC Flow',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => ['hybrid_telegram_llm_button_enabled' => true],
        ]);

        $markup = StrongLevelTelegramLlmButton::inlineKeyboardMarkupForAnalystSelf(
            $analyst,
            'USDCNH_otc',
            $analyst->config,
        );

        $this->assertNotNull($markup);
        $parsed = StrongLevelTelegramLlmButton::parseCallbackData($markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame($analyst->id, $parsed['analyst_bot_id']);
        $this->assertSame('USDCNH_otc', $parsed['instrument']);
    }

    public function test_analyst_handler_dispatches_self_llm_button_without_hybrid(): void
    {
        Queue::fake();

        PocketInstrument::query()->create([
            'instrument' => 'USDCNH_otc',
            'display_name' => 'USD/CNH OTC',
            'is_active' => true,
            'current_payout' => 72,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst SMC Flow',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [
                'telegram_enabled' => true,
                'telegram_bot_token' => 'analyst-token',
                'telegram_analyst_llm_question' => 'краткий вердикт',
            ],
        ]);

        $callbackData = StrongLevelTelegramLlmButton::encodeCallbackData($analyst->id, 'USDCNH_otc');
        $this->assertNotNull($callbackData);

        app(TelegramAnalystHandler::class)->handleUpdate($analyst, [
            'callback_query' => [
                'id' => 'cb-hybrid-self',
                'data' => $callbackData,
                'message' => ['chat' => ['id' => 77777]],
            ],
        ]);

        Queue::assertPushed(RunTelegramAnalystAnalysisJob::class, function ($job) use ($analyst): bool {
            return $job->botId === $analyst->id
                && $job->chatId === '77777'
                && $job->instrument === 'USDCNH_otc'
                && $job->userQuestion === 'краткий вердикт';
        });
    }

    public function test_resolve_active_instrument_ignores_analyst_filters(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'BLOCKEDPAIR',
            'display_name' => 'BLOCKED',
            'is_active' => true,
            'current_payout' => 40,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [
                'telegram_enabled' => true,
                'telegram_bot_token' => 'token',
                'min_payout' => 80,
                'blocked_instruments' => ['BLOCKEDPAIR'],
            ],
        ]);

        $service = app(TelegramAnalystInstrumentService::class);
        $this->assertNull($service->instrumentByIndex($bot, 0));
        $this->assertSame('BLOCKEDPAIR', $service->resolveActiveInstrument('BLOCKEDPAIR'));
    }

    public function test_strong_level_handler_dispatches_analyst_job_on_callback(): void
    {
        Queue::fake();

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'current_payout' => 90,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [
                'telegram_bot_token' => 'analyst-token',
            ],
        ]);
        $strong = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Level',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [
                'telegram_bot_token' => 'sl-token',
                'telegram_chat_id' => '12345',
                'telegram_analyst_llm_question' => 'Кастомный вопрос для LLM',
            ],
        ]);

        $callbackData = StrongLevelTelegramLlmButton::encodeCallbackData($analyst->id, 'EURUSD_otc');
        $this->assertNotNull($callbackData);

        app(StrongLevelTelegramHandler::class)->handleUpdate($strong, [
            'callback_query' => [
                'id' => 'cb1',
                'data' => $callbackData,
                'message' => ['chat' => ['id' => 12345]],
            ],
        ]);

        Queue::assertPushed(\App\Jobs\RunTelegramAnalystAnalysisJob::class, function ($job) use ($analyst): bool {
            return $job->botId === $analyst->id
                && $job->chatId === '12345'
                && $job->instrument === 'EURUSD_otc'
                && $job->userQuestion === 'Кастомный вопрос для LLM';
        });
    }

    public function test_strong_trend_handler_dispatches_analyst_job_on_callback(): void
    {
        Queue::fake();

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'current_payout' => 90,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $analyst = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst',
            'type' => 'openrouter_llm_analyst',
            'is_active' => true,
            'config' => [
                'telegram_bot_token' => 'analyst-token',
            ],
        ]);
        $trend = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Strong Trend Telegram',
            'type' => 'strong_trend_telegram',
            'is_active' => true,
            'config' => [
                'telegram_bot_token' => 'trend-token',
                'telegram_chat_id' => '99999',
            ],
        ]);

        $callbackData = StrongLevelTelegramLlmButton::encodeCallbackData($analyst->id, 'EURUSD_otc');
        $this->assertNotNull($callbackData);

        app(StrongLevelTelegramHandler::class)->handleUpdate($trend, [
            'callback_query' => [
                'id' => 'cb2',
                'data' => $callbackData,
                'message' => ['chat' => ['id' => 99999]],
            ],
        ]);

        Queue::assertPushed(\App\Jobs\RunTelegramAnalystAnalysisJob::class, function ($job) use ($analyst): bool {
            return $job->botId === $analyst->id
                && $job->chatId === '99999'
                && $job->instrument === 'EURUSD_otc';
        });
    }

    public function test_analyst_handler_skips_question_step_when_configured(): void
    {
        Queue::fake();

        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'current_payout' => 90,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst SMC Flow',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'telegram_enabled' => true,
                'telegram_bot_token' => 'analyst-token',
                'telegram_skip_user_question' => true,
                'min_payout' => 0,
            ],
        ]);

        app(TelegramAnalystHandler::class)->handleUpdate($bot, [
            'callback_query' => [
                'id' => 'cb-skip',
                'data' => 'ins:EURUSD_otc',
                'message' => ['chat' => ['id' => 55555]],
            ],
        ]);

        Queue::assertPushed(\App\Jobs\RunTelegramAnalystAnalysisJob::class, function ($job) use ($bot): bool {
            return $job->botId === $bot->id
                && $job->chatId === '55555'
                && $job->instrument === 'EURUSD_otc'
                && $job->userQuestion === '';
        });
    }

    /**
     * Регрессия: кнопка несёт имя пары. Если бы резолв шёл по индексу,
     * после выпадения первой пары из фильтра по payout клик по «EURUSD» дал бы другую.
     */
    public function test_instrument_callback_uses_name_not_shifting_index(): void
    {
        Queue::fake();

        PocketInstrument::query()->create([
            'instrument' => 'AAA_otc',
            'display_name' => 'AAA',
            'is_active' => true,
            'current_payout' => 40, // ниже min_payout — в меню её не было бы, но индекс «0» сейчас это EURUSD
            'priority' => 1,
        ]);
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => 'EUR/USD OTC',
            'is_active' => true,
            'current_payout' => 90,
            'priority' => 1,
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Chart Analyst SMC Flow',
            'type' => 'openrouter_llm_analyst',
            'is_active' => false,
            'config' => [
                'telegram_enabled' => true,
                'telegram_bot_token' => 'analyst-token',
                'telegram_skip_user_question' => true,
                'min_payout' => 80,
            ],
        ]);

        $service = app(TelegramAnalystInstrumentService::class);
        $menu = $service->keyboardItems($bot);
        $this->assertCount(1, $menu['items']);
        $this->assertSame('EURUSD_otc', $menu['items'][0]['instrument']);
        $this->assertSame('ins:EURUSD_otc', $menu['items'][0]['callback_data']);

        // Симуляция: после показа меню AAA снова стала «проходимой» по payout —
        // при индексе «0» резолв дал бы AAA; по имени должен остаться EURUSD.
        PocketInstrument::query()->where('instrument', 'AAA_otc')->update(['current_payout' => 95]);

        app(TelegramAnalystHandler::class)->handleUpdate($bot, [
            'callback_query' => [
                'id' => 'cb-name',
                'data' => 'ins:EURUSD_otc',
                'message' => ['chat' => ['id' => 55556]],
            ],
        ]);

        Queue::assertPushed(RunTelegramAnalystAnalysisJob::class, function ($job) use ($bot): bool {
            return $job->botId === $bot->id
                && $job->chatId === '55556'
                && $job->instrument === 'EURUSD_otc';
        });
    }

    public function test_encode_and_resolve_instrument_callback(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'display_name' => 'GBP/USD OTC',
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
            'config' => ['min_payout' => 80],
        ]);

        $service = app(TelegramAnalystInstrumentService::class);
        $encoded = $service->encodeInstrumentCallback('GBPUSD_otc');
        $this->assertSame('ins:GBPUSD_otc', $encoded);
        $this->assertSame('GBPUSD_otc', $service->parseInstrumentCallback((string) $encoded));
        // Даже ниже min_payout — имя из кнопки резолвится по активному инструменту.
        $this->assertSame('GBPUSD_otc', $service->resolveCallbackSelection($bot, 'GBPUSD_otc'));
    }
}
