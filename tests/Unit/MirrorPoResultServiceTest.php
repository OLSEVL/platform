<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\BotTrade;
use App\Models\ProxyEndpoint;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\Mirror\MirrorPoResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MirrorPoResultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_po_win_from_proxy(): void
    {
        Http::fake([
            '*/api/order-result' => Http::response([
                'ok' => true,
                'found' => true,
                'completed' => true,
                'result' => 'win',
                'profit' => 0.8,
                'deal_id' => 'deal-1',
                'order_id' => 'deal-1',
                'source' => 'proxy_cache',
            ]),
        ]);

        $trade = $this->makeMirroredTrade();
        $resolved = app(MirrorPoResultService::class)->resolveForTrade($trade, BotTrade::RESULT_LOSS);

        $this->assertSame(BotTrade::RESULT_WIN, $resolved['result']);
        $this->assertSame('po', $resolved['source']);
        $this->assertSame(0.8, $resolved['profit']);
        $this->assertSame('deal-1', $resolved['deal_id']);
    }

    public function test_unresolved_order_result_marks_assume_not_opened_after_three_retries(): void
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

        $trade = $this->makeMirroredTrade();
        $resolved = app(MirrorPoResultService::class)->resolveForTrade($trade, BotTrade::RESULT_LOSS);

        $this->assertNull($resolved['result']);
        $this->assertSame(MirrorPoResultService::SOURCE_ASSUME_NOT_OPENED, $resolved['source']);
        // 1 первичный + 3 retry
        Http::assertSentCount(MirrorPoResultService::MAX_FETCH_ATTEMPTS);
    }

    public function test_connect_fail_increments_failure_and_retries_other_proxy(): void
    {
        $proxyA = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.1:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);
        $proxyB = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.2:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            // Первая попытка — connect fail; дальше — win (на alternate proxy).
            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'found' => false,
                    'completed' => false,
                    'error' => 'Failed to connect to Pocket Option (connect returned false)',
                ], 502);
            }

            return Http::response([
                'ok' => true,
                'found' => true,
                'completed' => true,
                'result' => 'win',
                'profit' => 0.5,
                'deal_id' => 'deal-x',
                'order_id' => 'deal-x',
                'source' => 'proxy_cache',
            ]);
        });

        $trade = $this->makeMirroredTrade([
            'mirror_proxy_endpoint_id' => $proxyA->id,
            'mirror_proxy_url' => $proxyA->url,
        ], mirrorProxyAuto: true, poolIds: [$proxyA->id, $proxyB->id]);

        $resolved = app(MirrorPoResultService::class)->resolveForTrade($trade, BotTrade::RESULT_LOSS);

        $this->assertSame(BotTrade::RESULT_WIN, $resolved['result']);
        $this->assertSame('po_retry', $resolved['source']);

        $proxyA->refresh();
        $this->assertGreaterThanOrEqual(1, (int) $proxyA->failure_count);
        $this->assertStringContainsString('connect returned false', (string) $proxyA->last_error);
    }

    public function test_assume_not_opened_increments_proxy_failure_count(): void
    {
        $proxy = ProxyEndpoint::query()->create([
            'url' => 'http://user:pass@10.0.0.9:1000',
            'status' => ProxyEndpoint::STATUS_ACTIVE,
            'failure_count' => 0,
        ]);

        Http::fake([
            '*/api/order-result' => Http::response([
                'ok' => true,
                'found' => true,
                'completed' => false,
                'result' => null,
                'source' => 'proxy_cache_pending',
            ]),
        ]);

        $trade = $this->makeMirroredTrade([
            'mirror_proxy_endpoint_id' => $proxy->id,
            'mirror_proxy_url' => $proxy->url,
        ]);

        $resolved = app(MirrorPoResultService::class)->resolveForTrade($trade, BotTrade::RESULT_WIN);
        $this->assertSame(MirrorPoResultService::SOURCE_ASSUME_NOT_OPENED, $resolved['source']);

        $proxy->refresh();
        $this->assertGreaterThanOrEqual(1, (int) $proxy->failure_count);
        $this->assertStringContainsString('assume_not_opened', (string) $proxy->last_error);
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     * @param  list<int>  $poolIds
     */
    private function makeMirroredTrade(
        array $extraMeta = [],
        bool $mirrorProxyAuto = false,
        array $poolIds = [],
    ): BotTrade {
        $user = User::factory()->create();
        UserTradingSetting::query()->create([
            'user_id' => $user->id,
            'pocket_ssid' => '42["auth",{"session":"abc","uid":1,"isDemo":1,"platform":2}]',
            'is_demo' => true,
            'order_amount' => 1,
            'order_amounts' => [1.0],
            'mirror_trades_enabled' => true,
            'mirror_proxy_auto' => $mirrorProxyAuto,
            'mirror_proxy_endpoint_ids' => $poolIds === [] ? null : $poolIds,
        ]);
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'T',
            'type' => 'three_candles_in_row',
            'is_active' => true,
            'config' => ['mirror_trades_enabled' => true],
        ]);
        $entry = now()->subMinutes(2);

        return BotTrade::query()->create([
            'bot_id' => $bot->id,
            'instrument' => 'EURUSD_otc',
            'side' => 'CALL',
            'status' => BotTrade::STATUS_OPEN,
            'result' => BotTrade::RESULT_PENDING,
            'entry_time' => $entry,
            'expiry_time' => $entry->copy()->addMinute(),
            'entry_price' => 1.1,
            'meta_json' => array_merge([
                'mirror_status' => 'executed',
                'pocket_order_id' => 'req-uuid-1',
            ], $extraMeta),
        ]);
    }
}
