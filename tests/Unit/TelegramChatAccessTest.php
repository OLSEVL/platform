<?php

namespace Tests\Unit;

use App\Models\Bot;
use App\Models\User;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramChatAccess;
use App\Services\Telegram\StrongLevelTelegramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramChatAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_subscribe_defaults_to_true(): void
    {
        $this->assertTrue(TelegramChatAccess::autoSubscribeEnabled([]));
        $this->assertTrue(TelegramChatAccess::autoSubscribeEnabled(['telegram_auto_subscribe' => true]));
        $this->assertFalse(TelegramChatAccess::autoSubscribeEnabled(['telegram_auto_subscribe' => false]));
    }

    public function test_recipients_merge_primary_and_subscribers(): void
    {
        $ids = TelegramChatAccess::recipientChatIds([
            'telegram_chat_id' => '111',
            'telegram_subscribers' => ['222', '111', ' 333 '],
        ]);
        $this->assertSame(['111', '222', '333'], $ids);
    }

    public function test_authorize_incoming_subscribes_when_auto_enabled(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'SL',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [
                'telegram_chat_id' => '100',
                'telegram_auto_subscribe' => true,
            ],
        ]);

        $this->assertTrue(TelegramChatAccess::authorizeIncoming($bot, '200'));
        $bot->refresh();
        $this->assertSame(['100', '200'], TelegramChatAccess::recipientChatIds($bot->config));
        $this->assertContains('200', $bot->config['telegram_subscribers']);
    }

    public function test_authorize_incoming_denies_new_chat_when_auto_disabled(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'SL',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [
                'telegram_chat_id' => '100',
                'telegram_subscribers' => ['150'],
                'telegram_auto_subscribe' => false,
            ],
        ]);

        $this->assertTrue(TelegramChatAccess::authorizeIncoming($bot, '100'));
        $this->assertTrue(TelegramChatAccess::authorizeIncoming($bot, '150'));
        $this->assertFalse(TelegramChatAccess::authorizeIncoming($bot, '999'));
        $bot->refresh();
        $this->assertSame(['100', '150'], TelegramChatAccess::recipientChatIds($bot->config));
    }

    public function test_unsubscribe_removes_subscriber_but_keeps_primary(): void
    {
        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'SL',
            'type' => 'strong_level',
            'is_active' => true,
            'config' => [
                'telegram_chat_id' => '100',
                'telegram_subscribers' => ['100', '200'],
            ],
        ]);

        TelegramChatAccess::unsubscribe($bot, '100');
        $bot->refresh();
        // primary остаётся в recipients через telegram_chat_id
        $this->assertSame(['100', '200'], TelegramChatAccess::recipientChatIds($bot->config));
        $this->assertSame(['200'], $bot->config['telegram_subscribers']);

        TelegramChatAccess::unsubscribe($bot, '200');
        $bot->refresh();
        $this->assertSame(['100'], TelegramChatAccess::recipientChatIds($bot->config));
    }

    public function test_start_message_subscribes_via_strong_level_handler(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $user = User::factory()->create();
        $bot = Bot::query()->create([
            'user_id' => $user->id,
            'name' => 'Trend',
            'type' => 'strong_trend_telegram',
            'is_active' => true,
            'config' => [
                'telegram_bot_token' => 'tok',
                'telegram_auto_subscribe' => true,
            ],
        ]);

        app(StrongLevelTelegramHandler::class)->handleUpdate($bot, [
            'message' => [
                'chat' => ['id' => 555],
                'text' => '/start',
            ],
        ]);

        $bot->refresh();
        $this->assertContains('555', TelegramChatAccess::recipientChatIds($bot->config));
    }

    public function test_broadcast_sends_to_all_recipients(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $service = app(TelegramAlertService::class);
        $result = $service->broadcastToRecipients('token', ['1', '2'], function (string $chatId) use ($service): array {
            return $service->sendMessage('token', $chatId, 'hi');
        });

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['ok_count']);
        $this->assertSame(2, $result['recipients_count']);
        Http::assertSentCount(2);
    }
}
