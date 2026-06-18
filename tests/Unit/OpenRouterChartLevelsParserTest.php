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

    #[Test]
    public function splits_plain_chart_levels_block_without_fence(): void
    {
        $raw = <<<'TXT'
H1: bullish. Рекомендация: NONE.

chart_levels:
{
  "levels": [{"price": 1.40129, "kind": "support", "label": "FVG"}],
  "zones": [],
  "entry": null
}
TXT;
        $split = OpenRouterChartLevelsParser::splitAssistantResponse($raw);

        $this->assertStringNotContainsString('chart_levels', $split['analysis_text']);
        $this->assertTrue($split['fence_found']);
        $parsed = OpenRouterChartLevelsParser::normalize($split['chart_levels_raw'], true);
        $this->assertTrue($parsed['ok']);
        $this->assertSame(1.40129, $parsed['data']['levels'][0]['price']);
    }

    #[Test]
    public function repairs_llama_array_wrapper_and_truncated_levels(): void
    {
        $raw = <<<'JSON'
[
  "levels": [
    {
      "price": 1.40129,
      "kind": "support",
      "timeframe": "M1",
      "label": "FVG zone",
      "style": "dashed"
    },
    {
      "price": 1.40058,
      "kind": "resistance",
JSON;

        $parsed = OpenRouterChartLevelsParser::normalize($raw, true);

        $this->assertTrue($parsed['ok'], $parsed['message'] ?? '');
        $this->assertCount(2, $parsed['data']['levels']);
        $this->assertSame(1.40129, $parsed['data']['levels'][0]['price']);
        $this->assertSame('support', $parsed['data']['levels'][0]['kind']);
        $this->assertSame(1.40058, $parsed['data']['levels'][1]['price']);
        $this->assertSame('resistance', $parsed['data']['levels'][1]['kind']);
    }

    #[Test]
    public function splits_truncated_open_fence_from_assistant(): void
    {
        $raw = "Анализ рынка.\n\n```chart_levels\n{\"levels\":[{\"price\":1.2,\"kind\":\"pivot\"}]";
        $split = OpenRouterChartLevelsParser::splitAssistantResponse($raw);

        $this->assertSame('Анализ рынка.', $split['analysis_text']);
        $this->assertTrue($split['fence_found']);
        $parsed = OpenRouterChartLevelsParser::normalize($split['chart_levels_raw'], true);
        $this->assertTrue($parsed['ok']);
        $this->assertSame(1.2, $parsed['data']['levels'][0]['price']);
    }
}
