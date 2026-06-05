<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\InstrumentCandle;
use App\Models\User;
use App\Services\Bots\BotSettlementService;
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

