<?php

namespace Tests\Unit;

use App\Support\OpenRouterChartLevelsParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterChartLevelsParserTest extends TestCase
{
    #[Test]
    public function splits_text_and_chart_levels_fence(): void
    {
        $raw = "H1: flat\n\n```chart_levels\n{\"levels\":[{\"price\":1.1,\"kind\":\"support\"}]}\n```";
        $split = OpenRouterChartLevelsParser::splitAssistantResponse($raw);

        $this->assertSame('H1: flat', $split['analysis_text']);
        $this->assertTrue($split['fence_found']);
        $this->assertStringContainsString('"levels"', (string) $split['chart_levels_raw']);
    }

    #[Test]
    public function normalizes_levels_zones_and_entry_without_limits(): void
    {
        $json = json_encode([
            'levels' => [
                ['price' => 1.1, 'kind' => 'support', 'timeframe' => 'H1', 'label' => 'S1'],
                ['price' => 1.2, 'kind' => 'resistance', 'style' => 'dashed'],
            ],
            'zones' => [
                ['price_high' => 1.15, 'price_low' => 1.05, 'label' => 'range'],
            ],
            'entry' => ['price' => 1.08, 'side' => 'CALL', 'label' => 'entry'],
        ], JSON_THROW_ON_ERROR);

        $parsed = OpenRouterChartLevelsParser::normalize($json, true);

        $this->assertTrue($parsed['ok']);
        $this->assertNull($parsed['message']);
        $this->assertCount(2, $parsed['data']['levels']);
        $this->assertCount(1, $parsed['data']['zones']);
        $this->assertSame('CALL', $parsed['data']['entry']['side']);
    }

    #[Test]
    public function returns_parse_message_when_fence_json_invalid(): void
    {
        $parsed = OpenRouterChartLevelsParser::normalize('{broken', true);

        $this->assertFalse($parsed['ok']);
        $this->assertSame('уровни: не распознаны', $parsed['message']);
    }
}
