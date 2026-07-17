<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\User;
use App\Services\Bots\BotSettlementService;
use App\Services\Mirror\MirrorPoResultService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Проверяем критичный кейс: экспирация попадает внутрь минутного бара.
     *
     * До фикса сервис брал close бара и мог захватить движение после экспирации.
     * Теперь при экспирации внутри бара должна браться цена начала бара (open),
     * чтобы результат считался по доступной на момент экспирации цене.
     */
    public function test_settlement_uses_bar_open_when_expiry_is_inside_current_bar(): void
    {
        $expiryAt = now()->subSeconds(5);
        $expiryTs = $expiryAt->timestamp;
        $minuteBucketTs = $expiryTs - ($expiryTs % 60);

        $trade = $this->makeOpenTrade([
            'side' => 'CALL',
            'entry_price' => 100.00000,
            'expiry_time' => $expiryAt, // сделка уже due
        ]);

        // Бар начинается до экспирации:
        // open выше entry (должен быть WIN для CALL),
        // close ниже entry (по старой логике стало бы LOSS — это и была ошибка).
        InstrumentCandle::query()->create([
            'instrument' => $trade->instrument,
            'timeframe' => 1,
            'time' => $minuteBucketTs,
            'open' => 101.00000,
            'high' => 102.00000,
            'low' => 99.00000,
            'close' => 99.00000,
            'volume' => 1,
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($trade->bot_id);
        $trade->refresh();

        $this->assertSame(1, $res['settled']);
        $this->assertSame(BotTrade::STATUS_SETTLED, $trade->status);
        $this->assertSame(BotTrade::RESULT_WIN, $trade->result);
        $this->assertSame(101.0, (float) $trade->expiry_price);
    }

    /**
     * Проверяем приоритет более точного timeframe (stored_timeframe_id) перед M1.
     */
    public function test_settlement_prefers_stored_timeframe_when_available(): void
    {
        config()->set('candles.stored_timeframe_id', 4015);
        config()->set('candles.base_bar_period_seconds', 15);

        $trade = $this->makeOpenTrade([
            'side' => 'PUT',
            'entry_price' => 100.00000,
            'expiry_time' => now()->subSeconds(5),
        ]);

        $expiryTs = $trade->expiry_time->timestamp;
        $bucketTs = $expiryTs - ($expiryTs % 15);

        // M1 говорит "рост" (для PUT это LOSS), но точный 15s-бар говорит "падение" (для PUT это WIN).
        InstrumentCandle::query()->create([
            'instrument' => $trade->instrument,
            'timeframe' => 1,
            'time' => $expiryTs - ($expiryTs % 60),
            'open' => 100.00000,
            'high' => 101.00000,
            'low' => 99.00000,
            'close' => 100.50000,
            'volume' => 1,
        ]);
        InstrumentCandle::query()->create([
            'instrument' => $trade->instrument,
            'timeframe' => 4015,
            'time' => $bucketTs,
            'open' => 99.80000,
            'high' => 100.10000,
            'low' => 99.60000,
            'close' => 99.70000,
            'volume' => 1,
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($trade->bot_id);
        $trade->refresh();

        $this->assertSame(1, $res['settled']);
        $this->assertSame(BotTrade::RESULT_WIN, $trade->result);
        $this->assertSame(99.8, (float) $trade->expiry_price);
    }

    /**
     * Если свечей на экспирации нет и прошло больше grace-периода — закрываем как draw по entry,
     * чтобы сделка не зависала в status=open.
     */
    public function test_settlement_falls_back_to_entry_price_draw_when_no_candles_after_grace(): void
    {
        $trade = $this->makeOpenTrade([
            'side' => 'PUT',
            'entry_price' => 83.45210,
            'expiry_time' => now()->subMinutes(10),
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($trade->bot_id);
        $trade->refresh();

        $this->assertSame(1, $res['settled']);
        $this->assertSame(1, $res['settled_fallback']);
        $this->assertSame(BotTrade::STATUS_SETTLED, $trade->status);
        $this->assertSame(BotTrade::RESULT_DRAW, $trade->result);
        $this->assertSame(83.4521, (float) $trade->expiry_price);
        $this->assertSame(0.0, (float) $trade->pnl);
        $this->assertSame('entry_price_draw', $trade->meta_json['settlement_fallback'] ?? null);
    }

    /**
     * В grace-период после экспирации без свечей сделку пока не трогаем (ждём ingest).
     */
    public function test_settlement_skips_recent_due_trade_without_candles_during_grace(): void
    {
        $trade = $this->makeOpenTrade([
            'entry_price' => 100.00000,
            'expiry_time' => now()->subSeconds(30),
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($trade->bot_id);
        $trade->refresh();

        $this->assertSame(0, $res['settled']);
        $this->assertSame(1, $res['skipped']);
        $this->assertSame(BotTrade::STATUS_OPEN, $trade->status);
    }

    public function test_mirrored_trade_waits_po_delay_after_expiry(): void
    {
        $trade = $this->makeOpenTrade([
            'entry_price' => 100.00000,
            'expiry_time' => now()->subSeconds(2),
            'meta_json' => [
                'mirror_status' => 'executed',
                'pocket_order_id' => 'req-1',
            ],
        ]);

        InstrumentCandle::query()->create([
            'instrument' => $trade->instrument,
            'timeframe' => 60,
            'time' => $trade->expiry_time->timestamp - ($trade->expiry_time->timestamp % 60),
            'open' => 101,
            'high' => 102,
            'low' => 99,
            'close' => 101,
            'volume' => 1,
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($trade->bot_id);
        $this->assertSame(0, $res['settled']);
        $this->assertGreaterThan(0, $res['awaiting_po'] ?? 0);
        $trade->refresh();
        $this->assertSame(BotTrade::STATUS_OPEN, $trade->status);
    }

    public function test_assume_not_opened_source_does_not_advance_martingale_and_marks_meta_status(): void
    {
        Http::fake([
            '*/api/order-result' => Http::response([
                'ok' => true,
                'found' => false,
                'completed' => false,
                'result' => null,
                'source' => 'not_found',
            ]),
        ]);

        $user = User::factory()->create();
        \App\Models\UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"x","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0, 2.0, 4.0],
            'martingale_step' => 1,
            'mirror_trades_enabled' => true,
        ]);

        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Mirror Test Bot',
            'type' => 'three_candles_in_row',
            'config' => ['mirror_trades_enabled' => true],
            'is_active' => true,
        ]);

        $signal = BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'signal_at' => now()->subMinutes(3),
            'expiry_time' => now()->subMinute(),
            'dedupe_key' => uniqid('test:', true),
        ]);

        $trade = BotTrade::query()->create([
            'bot_id' => $bot->id,
            'bot_signal_id' => $signal->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now()->subMinutes(3),
            // expiry уже давно в прошлом, чтобы не ждать PO_RESULT_DELAY_SEC.
            'expiry_time' => now()->subMinute(),
            'entry_price' => 100.0,
            'payout_snapshot' => 92.0,
            'meta_json' => [
                'mirror_status' => 'executed',
                'pocket_order_id' => 'req-unresolved-po',
            ],
        ]);

        // Свеча даёт paper-loss для CALL (expiry open ниже entry), чтобы убедиться:
        // несмотря на paper-loss, step не должен увеличиться при assume_not_opened.
        $expiryTs = $trade->expiry_time->timestamp;
        $bucketTs = $expiryTs - ($expiryTs % 60);
        InstrumentCandle::query()->create([
            'instrument' => $trade->instrument,
            'timeframe' => 1,
            'time' => $bucketTs,
            'open' => 99.5,
            'high' => 100.2,
            'low' => 99.1,
            'close' => 99.4,
            'volume' => 1,
        ]);

        $res = app(BotSettlementService::class)->settleDueTrades($bot->id);
        $this->assertSame(1, $res['settled']);

        $trade->refresh();
        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        $this->assertSame(MirrorPoResultService::SOURCE_ASSUME_NOT_OPENED, $meta['mirror_po_result_source'] ?? null);
        $this->assertSame('assumed_not_opened', $meta['mirror_status'] ?? null);

        $step = (int) \App\Models\UserTradingSetting::query()
            ->where('user_id', $user->id)
            ->value('martingale_step');
        $this->assertSame(1, $step);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeOpenTrade(array $overrides = []): BotTrade
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Bot',
            'type' => 'three_candles_in_row',
            'config' => [],
            'is_active' => true,
        ]);

        $signal = BotSignal::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURJPY_otc',
            'side' => $overrides['side'] ?? 'CALL',
            'signal_at' => now()->subMinutes(3),
            'expiry_time' => $overrides['expiry_time'] ?? now()->subMinute(),
            'dedupe_key' => uniqid('test:', true),
        ]);

        return BotTrade::query()->create(array_merge([
            'bot_id' => $bot->id,
            'bot_signal_id' => $signal->id,
            'instrument' => 'EURJPY_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => now()->subMinutes(3),
            'expiry_time' => now()->subMinute(),
            'entry_price' => 100.00000,
            'payout_snapshot' => 92.0,
        ], $overrides));
    }
}

