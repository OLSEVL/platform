<?php

namespace Tests\Unit;

use App\Support\ChartAnalystBotTypes;
use App\Support\ImpulseLvl1minConfig;
use App\Support\ImpulseLvl1minLevels;
use App\Support\ImpulseLvlLlmSnapshot;
use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImpulseLvlLlmSnapshotTest extends TestCase
{
    /** @return array{time:int,open:float,high:float,low:float,close:float} */
    private function bar(int $i, float $open, float $high, float $low, float $close): array
    {
        return [
            'time' => 1_700_000_000 + $i * 60,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
        ];
    }

    #[Test]
    public function pack_includes_unbroken_zones_and_chart_levels(): void
    {
        $closed = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 1.1000 + ($i * 0.0001);
            $closed[] = $this->bar($i, $base, $base + 0.0002, $base - 0.0002, $base + 0.0001);
        }
        $peak = 20;
        $closed[$peak] = $this->bar($peak, 1.1020, 1.1080, 1.1018, 1.1022);
        $eval = $this->bar(40, 1.1040, 1.1050, 1.1035, 1.1045);

        $pack = ImpulseLvlLlmSnapshot::pack($closed, $eval, [], ImpulseLvl1minConfig::defaultConfig());

        $this->assertSame(60, $pack['snapshot']['expiry_focus_sec']);
        $this->assertNotEmpty($pack['snapshot']['active_zones']);
        $this->assertNotEmpty($pack['chart_levels']['levels']);
        $this->assertArrayHasKey('filter_status', $pack['snapshot']);
        $this->assertNotEmpty($pack['telegram']['overlay_lines']);
        $this->assertStringContainsString('фильтр', $pack['snapshot']['filter_status']['reason']);
        $this->assertSame($pack['snapshot']['filter_status']['reason'], $pack['chart_levels']['subtitle']);
        foreach ($pack['snapshot']['active_zones'] as $i => $zone) {
            $label = (string) ($pack['telegram']['price_levels'][$i]['label'] ?? '');
            if (($zone['kind'] ?? '') === 'resistance') {
                $this->assertStringContainsString('↑', $label, 'сопротивление continuation: CALL вверх');
            } else {
                $this->assertStringContainsString('↓', $label, 'поддержка continuation: PUT вниз');
            }
        }
        $this->assertArrayHasKey('rule_reminder', $pack['snapshot']);
        $this->assertArrayHasKey('live_setups', $pack['snapshot']);
    }

    #[Test]
    public function merge_keeps_seed_zones_and_llm_entry(): void
    {
        $merged = ImpulseLvlLlmSnapshot::mergeChartLevels(
            [
                'levels' => [['price' => 2.0, 'kind' => 'entry', 'label' => 'Вход CALL · 2']],
                'zones' => [],
                'entry' => ['price' => 2.0, 'side' => 'CALL', 'label' => 'Вход CALL · 2'],
            ],
            [
                'levels' => [['price' => 1.0, 'kind' => 'support', 'label' => 'M1 поддержка · 1']],
                'zones' => [['price_low' => 0.9, 'price_high' => 1.1, 'label' => 'M1 зона · 0.9–1.1']],
                'entry' => null,
            ]
        );

        $this->assertCount(2, $merged['levels']);
        $this->assertCount(1, $merged['zones']);
        $this->assertSame('CALL', $merged['entry']['side'] ?? null);
    }

    #[Test]
    public function analyst_prompts_and_flags_bind_to_impulse_type(): void
    {
        $cfg = ['_bot_type' => ChartAnalystBotTypes::IMPULSE, 'analyst_smc_flow_enabled' => true];

        $this->assertTrue(OpenRouterLlmConfig::analystImpulseLvlFlowEnabled($cfg));
        $this->assertFalse(OpenRouterLlmConfig::analystSmcFlowEnabled($cfg));
        $this->assertStringContainsString('Impulse LVL', OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfg));
        $this->assertStringContainsString('60 секунд', OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg));
        $this->assertSame('OpenRouter Chart Analyst Impulse LVL', ChartAnalystBotTypes::label(ChartAnalystBotTypes::IMPULSE));
        $this->assertTrue(ChartAnalystBotTypes::isAnalyst(ChartAnalystBotTypes::IMPULSE));
        $this->assertNotEmpty(ImpulseLvl1minLevels::tfLabel(1800));
        $this->assertSame('CALL', ImpulseLvl1minConfig::bounceSideFromImpulseColor('green'));
        $this->assertSame('PUT', ImpulseLvl1minConfig::bounceSideFromImpulseColor('red'));
        $this->assertSame('PUT', ImpulseLvl1minConfig::sideFromImpulseColor('green', [
            'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE,
        ]));
        $this->assertSame('CALL', ImpulseLvl1minConfig::sideFromImpulseColor('red', [
            'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE,
        ]));
        $this->assertSame('↓', ImpulseLvl1minConfig::lvlZoneEntryVisual('resistance', [
            'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE,
        ])['glyph']);
        $this->assertStringContainsString('entry_side_mode', OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg));
    }

    #[Test]
    public function continuation_mode_flips_zone_arrows(): void
    {
        $closed = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 1.1000 + ($i * 0.0001);
            $closed[] = $this->bar($i, $base, $base + 0.0002, $base - 0.0002, $base + 0.0001);
        }
        $peak = 20;
        $closed[$peak] = $this->bar($peak, 1.1020, 1.1080, 1.1018, 1.1022);
        $eval = $this->bar(40, 1.1040, 1.1050, 1.1035, 1.1045);
        $cfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), [
            'entry_side_mode' => ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION,
        ]);
        $pack = ImpulseLvlLlmSnapshot::pack($closed, $eval, [], $cfg);

        $this->assertSame(ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION, $pack['snapshot']['entry_side_mode']);
        foreach ($pack['snapshot']['active_zones'] as $i => $zone) {
            $label = (string) ($pack['telegram']['price_levels'][$i]['label'] ?? '');
            if (($zone['kind'] ?? '') === 'resistance') {
                $this->assertStringContainsString('↑', $label, 'сопротивление continuation: CALL вверх');
            } else {
                $this->assertStringContainsString('↓', $label, 'поддержка continuation: PUT вниз');
            }
        }
    }

    #[Test]
    public function telegram_payload_renders_png_with_lvl_zones(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('gd required');
        }
        $closed = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 1.1000 + ($i * 0.0001);
            $closed[] = $this->bar($i, $base, $base + 0.0002, $base - 0.0002, $base + 0.0001);
        }
        $closed[20] = $this->bar(20, 1.1020, 1.1080, 1.1018, 1.1022);
        $eval = $this->bar(40, 1.1040, 1.1050, 1.1035, 1.1045);
        $pack = ImpulseLvlLlmSnapshot::pack($closed, $eval, [], ImpulseLvl1minConfig::defaultConfig());
        $series = array_merge($closed, [$eval]);

        $path = app(\App\Services\Telegram\ImpulseLvl1minTelegramChartRenderer::class)
            ->renderFromTelegramPayload($series, $pack['telegram']);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertIsArray($info);
        $this->assertSame(960, $info[0]);
        @unlink($path);
    }
}
