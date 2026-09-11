<?php

namespace Tests\Unit;

use App\Support\CardSmcFlowAnalysisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardSmcFlowAnalysisStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_begin_always_creates_new_token(): void
    {
        $store = new CardSmcFlowAnalysisStore;
        $first = $store->begin(10, 20);
        $second = $store->begin(10, 20);

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(CardSmcFlowAnalysisStore::STATUS_PENDING, $second['state']['status']);
        $this->assertSame(CardSmcFlowAnalysisStore::STATUS_PENDING, $store->get($first['token'])['status']);
    }

    public function test_public_payload_hides_internal_ids(): void
    {
        $store = new CardSmcFlowAnalysisStore;
        $started = $store->begin(3, 4);
        $store->patch($started['token'], [
            'status' => CardSmcFlowAnalysisStore::STATUS_OK,
            'analysis_text' => 'PUT 64%',
            'summary' => 'Анализ готов.',
            'instrument' => 'GBPUSD_otc',
            'analyst_bot_id' => 99,
        ]);
        $state = $store->get($started['token']);
        $payload = $store->publicPayload($state);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('PUT 64%', $payload['analysis_text']);
        $this->assertArrayNotHasKey('analyst_bot_id', $payload);
        $this->assertArrayNotHasKey('bot_id', $payload);
    }
}
