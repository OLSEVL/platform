<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\OpenRouterClient;
use Throwable;

class OpenRouterLlmStrategy implements BotStrategyInterface
{
    public function __construct(
        private readonly OpenRouterClient $openRouterClient,
    ) {}

    public function analyze(array $closedCandles, ?array $partialCandle, array $context): ?array
    {
        /** @var array<string,mixed> $cfg */
        $cfg = is_array($context['config'] ?? null) ? $context['config'] : [];
        $instrument = (string) ($context['instrument'] ?? '');
        $payout = isset($context['payout']) ? (float) $context['payout'] : null;

        // В LLM-боте все параметры решения (модель, тон ответа, лимит токенов, системный промпт)
        // задаются через конфигурацию бота, чтобы не было "зашитых" правил в коде.
        $model = (string) ($cfg['model'] ?? 'openai/gpt-5.2');
        $temperature = (float) ($cfg['temperature'] ?? 0.0);
        $maxTokens = (int) ($cfg['max_tokens'] ?? 350);
        $systemPrompt = (string) ($cfg['system_prompt'] ?? $this->defaultSystemPrompt());
        $candlesTake = max(20, min(500, (int) ($cfg['candles_take'] ?? 120)));
        $decisionVersion = (string) ($cfg['decision_version'] ?? ($cfg['version'] ?? 'openrouter_llm_v1'));

        // Передаём в модель только последние N закрытых свечей:
        // это уменьшает размер запроса и удерживает контекст в "актуальной зоне" рынка.
        $closedTail = array_slice($closedCandles, -$candlesTake);
        $payload = [
            'instrument' => $instrument,
            'timeframe' => 'M1',
            'payout' => $payout,
            'closed_candles' => $closedTail,
            'partial_candle' => $partialCandle,
            // Явно фиксируем контракт ответа, чтобы интеграция была предсказуемой.
            'response_contract' => [
                'side' => 'CALL|PUT|NONE',
                'confidence' => 'float 0..1',
                'analysis_text' => 'short plain text',
                'reasons_short' => 'array of short strings',
                'features' => 'object',
            ],
        ];

        try {
            $response = $this->openRouterClient->chatCompletion(
                $model,
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    [
                        'role' => 'user',
                        'content' => "Верни решение строго в JSON по контракту.\n".json_encode(
                            $payload,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                    ],
                ],
                $temperature,
                $maxTokens
            );
        } catch (Throwable $e) {
            // Любая ошибка провайдера/сети не должна падать в scan-цикле:
            // просто пропускаем инструмент и продолжаем обработку остальных.
            return null;
        }

        $content = $this->extractAssistantContent($response);
        if ($content === null || $content === '') {
            return null;
        }

        $parsed = $this->parseStrictJson($content);
        if (! is_array($parsed)) {
            return null;
        }

        $side = strtoupper((string) ($parsed['side'] ?? 'NONE'));
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            return null;
        }

        $confidence = (float) ($parsed['confidence'] ?? 0);
        $confidence = max(0.0, min(1.0, $confidence));
        $analysisText = (string) ($parsed['analysis_text'] ?? '');
        $reasons = $this->normalizeReasons($parsed['reasons_short'] ?? []);
        $features = is_array($parsed['features'] ?? null) ? $parsed['features'] : [];

        return [
            'side' => $side,
            'confidence' => $confidence,
            'reasons_short' => $reasons,
            'analysis_text' => $analysisText,
            'features' => array_merge($features, [
                'provider' => 'openrouter',
                'model' => $model,
                'candles_sent' => count($closedTail),
                'temperature' => $temperature,
            ]),
            'decision_version' => $decisionVersion,
        ];
    }

    /**
     * Достаём текст ответа ассистента из OpenRouter/OpenAI-совместимого формата.
     */
    private function extractAssistantContent(array $response): ?string
    {
        $choices = $response['choices'] ?? null;
        if (! is_array($choices) || $choices === []) {
            return null;
        }
        $first = $choices[0];
        if (! is_array($first)) {
            return null;
        }
        $message = $first['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }
        $content = $message['content'] ?? null;

        return is_string($content) ? trim($content) : null;
    }

    /**
     * Разбираем строго JSON-строку. Дополнительно поддерживаем случай, когда модель
     * всё же обернула JSON в markdown-блок ```json ... ```.
     *
     * @return array<string,mixed>|null
     */
    private function parseStrictJson(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/su', $content, $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $reasonsRaw
     * @return list<string>
     */
    private function normalizeReasons(mixed $reasonsRaw): array
    {
        if (! is_array($reasonsRaw)) {
            return [];
        }

        $out = [];
        foreach ($reasonsRaw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
                continue;
            }
            if (is_scalar($item)) {
                $asString = (string) $item;
                if ($asString !== '') {
                    $out[] = $asString;
                }
            }
        }

        return array_values(array_slice($out, 0, 8));
    }

    private function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты торговый AI-ассистент для бинарных опционов (M1).
Твоя задача: на основании массива свечей вернуть только JSON-объект.
Никакого markdown, никакого текста вне JSON.

Формат ответа:
{
  "side": "CALL" | "PUT" | "NONE",
  "confidence": 0.0,
  "analysis_text": "кратко, 1-2 предложения",
  "reasons_short": ["короткая причина 1", "короткая причина 2"],
  "features": {"key":"value"}
}

Правила:
- если сигнал слабый или неоднозначный: side = "NONE", confidence <= 0.5;
- confidence всегда в диапазоне 0..1;
- reasons_short максимум 5 коротких пунктов.
PROMPT;
    }
}
