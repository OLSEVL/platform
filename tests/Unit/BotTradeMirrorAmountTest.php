<?php

namespace Tests\Unit;

use App\Models\BotTrade;
use Tests\TestCase;

class BotTradeMirrorAmountTest extends TestCase
{
    public function test_mirror_amount_label_from_meta(): void
    {
        $trade = new BotTrade([
            'meta_json' => ['mirror_amount' => 4.8],
        ]);
        $this->assertSame(4.8, $trade->mirrorEntryAmount());
        $this->assertSame('$4.8', $trade->mirrorEntryAmountLabel());
    }

    public function test_missing_amount_returns_dash(): void
    {
        $trade = new BotTrade(['meta_json' => ['mirror_status' => 'skipped_disabled']]);
        $this->assertNull($trade->mirrorEntryAmount());
        $this->assertSame('—', $trade->mirrorEntryAmountLabel());
    }
}
