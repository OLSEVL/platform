<?php

namespace App\Support;

/**
 * Парсит блок ```chart_levels``` в конце ответа LLM-аналитика и нормализует уровни для отрисовки на графике.
 */
final class OpenRouterChartLevelsParser
{
    public const FENCE_TAG = 'chart_levels';

    /** Полный system prompt бота openrouter_llm_analyst (текст + chart_levels). */
    public static function defaultAnalystSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты торговый AI-аналитик для бинарных опционов.
Работаешь по MTF-логике: H1 -> M15 -> M5 -> M1, плюс секундный поток S5 для тайминга.

Главная задача — разбор графика и рыночной структуры.

Данные в запросе:
- closed_candles_m1, partial_candle_m1 — минутки M1
- closed_candles_s5, partial_candle_s5 — секундные S5 (5 сек), самый свежий поток ingest; используй для микроструктуры и тайминга внутри текущей M1
- mtf.m1_closed, m5_closed, m15_closed, h1_closed — старшие ТФ (если mtf.enabled)
- user_question — дополнительный вопрос трейдера (может быть null)
PROMPT
            .self::promptInstructions();
    }

    /**
     * Инструкция для system prompt: текст анализа + JSON-уровни в конце.
     */
    public static function promptInstructions(): string
    {
        return <<<'TXT'

Формат ответа (обязательно):
1) Связный текст на русском: H1 → M15 → M5 → M1 → уровни → сценарии.
   Торговая рекомендация (CALL/PUT) опциональна — только при явном согласованном сетапе.
2) В самом конце ответа — отдельный блок для отрисовки на графике (после текста, без markdown вокруг JSON внутри блока):

```chart_levels
{
  "levels": [
    {
      "price": 1.08542,
      "kind": "support",
      "timeframe": "H1",
      "label": "H1 support",
      "style": "solid"
    }
  ],
  "zones": [
    {
      "price_high": 1.08610,
      "price_low": 1.08520,
      "timeframe": "M15",
      "label": "M15 range"
    }
  ],
  "entry": {
    "price": 1.08550,
    "side": "CALL",
    "label": "optional entry"
  }
}
```

Правила chart_levels:
- price / price_high / price_low — числа из данных свечей (не выдумывай далёкие уровни).
- kind: support | resistance | pivot | range_high | range_low | entry
- timeframe: H1 | M15 | M5 | M1 (опционально)
- style: solid | dashed (опционально)
- levels и zones — все значимые уровни, которые упомянул в тексте; лимита по количеству нет.
- entry — только если в тексте есть явная точка входа; иначе null или не указывай поле.
TXT;
    }

    /**
     * Отделяет читаемый текст от блока chart_levels.
     *
     * @return array{
     *   analysis_text:string,
     *   chart_levels_raw:?string,
     *   fence_found:bool
     * }
     */
    public static function splitAssistantResponse(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [
                'analysis_text' => '',
                'chart_levels_raw' => null,
                'fence_found' => false,
            ];
        }

        // ```chart_levels ... ``` или ```json chart_levels ... ```
        $pattern = '/```(?:'.preg_quote(self::FENCE_TAG, '/').'|json\s+'.preg_quote(self::FENCE_TAG, '/').')\s*\r?\n([\s\S]*?)\r?\n```/iu';
        if (! preg_match($pattern, $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'analysis_text' => $trimmed,
                'chart_levels_raw' => null,
                'fence_found' => false,
            ];
        }

        $analysisText = trim(substr($trimmed, 0, (int) $matches[0][1]));
        $jsonRaw = trim((string) ($matches[1][0] ?? ''));

        return [
            'analysis_text' => $analysisText,
            'chart_levels_raw' => $jsonRaw !== '' ? $jsonRaw : null,
            'fence_found' => true,
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   message:?string,
     *   data:array{levels:list<array<string,mixed>>,zones:list<array<string,mixed>>,entry:?array<string,mixed>}
     * }
     */
    public static function normalize(?string $jsonRaw, bool $fenceFound): array
    {
        $empty = [
            'ok' => false,
            'message' => null,
            'data' => [
                'levels' => [],
                'zones' => [],
                'entry' => null,
            ],
        ];

        if (! $fenceFound) {
            return $empty;
        }

        if ($jsonRaw === null || trim($jsonRaw) === '') {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        try {
            $decoded = json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'уровни: не распознаны',
                'data' => $empty['data'],
            ];
        }

        $levels = self::normalizeLevels($decoded['levels'] ?? null);
        $zones = self::normalizeZones($decoded['zones'] ?? null);
        $entry = self::normalizeEntry($decoded['entry'] ?? null);

        return [
            'ok' => true,
            'message' => null,
            'data' => [
                'levels' => $levels,
                'zones' => $zones,
                'entry' => $entry,
            ],
        ];
    }

    /**
     * @return list<array{price:float,kind:string,timeframe:?string,label:string,style:string}>
     */
    private static function normalizeLevels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $price = self::toFloat($item['price'] ?? $item['price_level'] ?? $item['level'] ?? null);
            if ($price === null) {
                continue;
            }
            $kind = self::normalizeKind((string) ($item['kind'] ?? $item['type'] ?? $item['role'] ?? 'level'));
            $out[] = [
                'price' => $price,
                'kind' => $kind,
                'timeframe' => self::normalizeTimeframe($item['timeframe'] ?? null),
                'label' => self::normalizeLabel($item['label'] ?? $item['name'] ?? '', $kind, $price),
                'style' => self::normalizeStyle((string) ($item['style'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{price_high:float,price_low:float,timeframe:?string,label:string}>
     */
    private static function normalizeZones(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $high = self::toFloat($item['price_high'] ?? $item['high'] ?? null);
            $low = self::toFloat($item['price_low'] ?? $item['low'] ?? null);
            if ($high === null || $low === null) {
                continue;
            }
            if ($high < $low) {
                [$high, $low] = [$low, $high];
            }
            $out[] = [
                'price_high' => $high,
                'price_low' => $low,
                'timeframe' => self::normalizeTimeframe($item['timeframe'] ?? null),
                'label' => self::normalizeLabel($item['label'] ?? $item['name'] ?? '', 'zone', ($high + $low) / 2),
            ];
        }

        return $out;
    }

    /**
     * @return array{price:float,side:?string,label:string}|null
     */
    private static function normalizeEntry(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $price = self::toFloat($raw['price'] ?? $raw['price_level'] ?? null);
        if ($price === null) {
            return null;
        }
        $sideRaw = strtoupper(trim((string) ($raw['side'] ?? '')));
        $side = in_array($sideRaw, ['CALL', 'PUT'], true) ? $sideRaw : null;

        return [
            'price' => $price,
            'side' => $side,
            'label' => self::normalizeLabel($raw['label'] ?? $raw['name'] ?? '', 'entry', $price),
        ];
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;

        return is_finite($f) ? $f : null;
    }

    private static function normalizeKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        $allowed = ['support', 'resistance', 'pivot', 'range_high', 'range_low', 'entry', 'level'];

        return in_array($k, $allowed, true) ? $k : 'level';
    }

    private static function normalizeStyle(string $style): string
    {
        $s = strtolower(trim($style));

        return $s === 'dashed' ? 'dashed' : 'solid';
    }

    private static function normalizeTimeframe(mixed $tf): ?string
    {
        if ($tf === null || $tf === '') {
            return null;
        }
        $t = strtoupper(trim((string) $tf));
        $map = [
            'H1' => 'H1',
            '1H' => 'H1',
            '60' => 'H1',
            'M15' => 'M15',
            '15' => 'M15',
            'M5' => 'M5',
            '5' => 'M5',
            'M1' => 'M1',
            '1' => 'M1',
        ];

        return $map[$t] ?? (preg_match('/^(H1|M15|M5|M1)$/i', $t) ? strtoupper($t) : null);
    }

    private static function normalizeLabel(string $label, string $kind, float $price): string
    {
        $label = trim($label);
        if ($label !== '') {
            return mb_substr($label, 0, 80);
        }

        return $kind.' @ '.rtrim(rtrim(number_format($price, 8, '.', ''), '0'), '.');
    }
}
