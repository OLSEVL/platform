<?php

namespace Tests\Unit;

use App\Models\PocketInstrument;
use App\Support\TelegramInstrumentLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramInstrumentLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TelegramInstrumentLabel::clearCache();
    }

    public function test_format_uses_display_name_with_code_in_parentheses(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'AUDNZD_otc',
            'display_name' => 'AUD/NZD OTC',
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->assertSame('AUD/NZD OTC (AUDNZD_otc)', TelegramInstrumentLabel::format('AUDNZD_otc'));
        $this->assertSame(
            'AUD/NZD OTC (AUDNZD_otc)',
            htmlspecialchars_decode(TelegramInstrumentLabel::html('AUDNZD_otc'), ENT_QUOTES)
        );
    }

    public function test_format_falls_back_to_code_when_display_name_missing(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'EURUSD_otc',
            'display_name' => null,
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->assertSame('EURUSD_otc', TelegramInstrumentLabel::format('EURUSD_otc'));
        $this->assertSame('UNKNOWN_pair', TelegramInstrumentLabel::format('UNKNOWN_pair'));
    }

    public function test_format_does_not_duplicate_identical_names(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'BTCUSD',
            'display_name' => 'BTCUSD',
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->assertSame('BTCUSD', TelegramInstrumentLabel::format('BTCUSD'));
    }

    public function test_format_is_cached_per_process(): void
    {
        PocketInstrument::query()->create([
            'instrument' => 'GBPUSD_otc',
            'display_name' => 'GBP/USD OTC',
            'is_active' => true,
            'priority' => 1,
        ]);

        $t0 = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            TelegramInstrumentLabel::format('GBPUSD_otc');
        }
        $cachedMs = (hrtime(true) - $t0) / 1e6;

        // 100 вызовов с кэшем должны быть заметно быстрее серии «холодных» lookup'ов.
        $this->assertLessThan(50.0, $cachedMs, 'cached format loop should stay under 50ms');
        $this->assertSame('GBP/USD OTC (GBPUSD_otc)', TelegramInstrumentLabel::format('GBPUSD_otc'));
    }
}
