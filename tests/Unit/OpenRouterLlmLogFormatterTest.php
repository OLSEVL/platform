<?php

namespace Tests\Unit;

use App\Support\OpenRouterLlmLogFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterLlmLogFormatterTest extends TestCase
{
    #[Test]
    public function format_request_text_includes_system_and_user_roles(): void
    {
        $text = OpenRouterLlmLogFormatter::formatRequestText([
            ['role' => 'system', 'content' => 'Ты торговый AI-аналитик'],
            ['role' => 'user', 'content' => '{"instrument":"EURUSD"}'],
        ]);

        $this->assertStringContainsString('--- system ---', $text);
        $this->assertStringContainsString('Ты торговый AI-аналитик', $text);
        $this->assertStringContainsString('--- user ---', $text);
        $this->assertStringContainsString('EURUSD', $text);
    }

    #[Test]
    public function request_text_for_display_reads_messages_from_request_json(): void
    {
        $text = OpenRouterLlmLogFormatter::requestTextForDisplay(
            ['messages' => [['role' => 'system', 'content' => 'Промпт из JSON']]],
            'только user из старого поля',
        );

        $this->assertStringContainsString('Промпт из JSON', $text);
        $this->assertStringNotContainsString('только user из старого поля', $text);
    }
}
