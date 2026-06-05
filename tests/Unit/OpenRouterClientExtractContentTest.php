<?php

namespace Tests\Unit;

use App\Services\Bots\OpenRouterClient;
use Tests\TestCase;

class OpenRouterClientExtractContentTest extends TestCase
{
    public function test_extracts_string_content(): void
    {
        $text = OpenRouterClient::extractAssistantContent([
            'choices' => [
                ['message' => ['content' => '  Анализ готов.  ']],
            ],
        ]);

        $this->assertSame('Анализ готов.', $text);
    }

    public function test_extracts_reasoning_when_content_null(): void
    {
        $text = OpenRouterClient::extractAssistantContent([
            'choices' => [
                [
                    'finish_reason' => 'length',
                    'message' => [
                        'content' => null,
                        'reasoning' => 'H1: down; M15: support test.',
                    ],
                ],
            ],
        ]);

        $this->assertSame('H1: down; M15: support test.', $text);
        $this->assertSame('length', OpenRouterClient::extractFinishReason([
            'choices' => [['finish_reason' => 'length']],
        ]));
    }

    public function test_resolve_chat_timeout_from_bot_config(): void
    {
        $this->assertSame(240, OpenRouterClient::resolveChatTimeoutSec(['openrouter_timeout_sec' => 240]));
        $this->assertSame(600, OpenRouterClient::resolveChatTimeoutSec(['openrouter_timeout_sec' => 9999]));
    }
}
