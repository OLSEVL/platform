<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\OpenRouterClient;
use App\Support\OpenRouterLlmConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $timeoutSec = OpenRouterClient::resolveChatTimeoutSec($cfg);
        $systemPrompt = (string) ($cfg['system_prompt'] ?? $this->defaultSystemPrompt());
        $decisionVersion = (string) ($cfg['decision_version'] ?? ($cfg['version'] ?? 'openrouter_llm_v1'));
        $mtfEnabled = (bool) ($cfg['mtf_enabled'] ?? true);
        $botId = isset($context['bot_id']) ? (int) $context['bot_id'] : null;
        $analysisRunId = isset($context['analysis_run_id']) ? (int) $context['analysis_run_id'] : null;

        /** @var array<string, list<array<string,mixed>>> $mtf */
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];
        $m5 = is_array($mtf['5'] ?? null) ? array_values($mtf['5']) : [];
        $m15 = is_array($mtf['15'] ?? null) ? array_values($mtf['15']) : [];
        $h1 = is_array($mtf['60'] ?? null) ? array_values($mtf['60']) : [];

        // В LLM уходит весь M1-ряд, загруженный scan по count (одно поле в настройках).
        $closedForLlm = array_values($closedCandles);
        $payload = [
            'instrument' => $instrument,
            'timeframe' => 'M1',
            'payout' => $payout,
            'closed_candles' => $closedForLlm,
            'partial_candle' => $partialCandle,
            // MTF-контекст для модели:
            // H1 задаёт старший bias, M15/M5 дают структуру и подтверждение сетапа.
            'mtf' => [
                'enabled' => $mtfEnabled,
                'h1_closed' => $h1,
                'm15_closed' => $m15,
                'm5_closed' => $m5,
            ],
            'mtf_sizes' => [
                'h1' => count($h1),
                'm15' => count($m15),
                'm5' => count($m5),
            ],
            // Явно фиксируем контракт ответа, чтобы интеграция была предсказуемой.
            'response_contract' => [
                'side' => 'CALL|PUT|NONE',
                'confidence' => 'float 0..1',
                'analysis_text' => 'short plain text',
                'reasons_short' => 'array of short strings',
                'features' => 'object',
            ],
        ];
        $requestMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => "Верни решение строго в JSON по контракту.\n".json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
        ];
        $startedAt = microtime(true);

        try {
            $response = $this->openRouterClient->chatCompletion(
                $model,
                $requestMessages,
                $temperature,
                $maxTokens,
                $timeoutSec
            );
        } catch (Throwable $e) {
            $this->persistLlmLog([
                'bot_id' => $botId,
                'analysis_run_id' => $analysisRunId,
                'instrument' => $instrument,
                'model' => $model,
                'request_payload' => [
                    'messages' => $requestMessages,
                    'payload' => $payload,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                ],
                'request_text' => \App\Support\OpenRouterLlmLogFormatter::formatRequestText($requestMessages),
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            // Любая ошибка провайдера/сети не должна падать в scan-цикле:
            // просто пропускаем инструмент и продолжаем обработку остальных.
            return null;
        }

        $llmLogId = $this->persistLlmLog([
            'bot_id' => $botId,
            'analysis_run_id' => $analysisRunId,
            'instrument' => $instrument,
            'model' => $model,
            'request_payload' => [
                'messages' => $requestMessages,
                'payload' => $payload,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ],
            'request_text' => \App\Support\OpenRouterLlmLogFormatter::formatRequestText($requestMessages),
            'response_payload' => $response,
            'response_text' => (string) ($response['choices'][0]['message']['content'] ?? ''),
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : null,
            'status' => 'ok',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $content = OpenRouterClient::extractAssistantContent($response);
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
                'candles_sent' => count($closedForLlm),
                'temperature' => $temperature,
                // Дублируем MTF-метрики в features для последующего аудита в истории сигналов/сделок.
                'mtf_enabled' => $mtfEnabled,
                'h1_bars_sent' => count($h1),
                'm15_bars_sent' => count($m15),
                'm5_bars_sent' => count($m5),
            ]),
            'decision_version' => $decisionVersion,
            'llm_log_id' => $llmLogId,
        ];
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
        return OpenRouterLlmConfig::defaultTradingSystemPrompt();
    }

    /**
     * @param array{
     *   bot_id:int|null,
     *   analysis_run_id:int|null,
     *   instrument:string,
     *   model:string,
     *   request_payload:array<string,mixed>|null,
     *   request_text:string|null,
     *   response_payload?:array<string,mixed>|null,
     *   response_text?:string|null,
     *   usage?:array<string,mixed>|null,
     *   status:string,
     *   error_message?:string|null,
     *   latency_ms:int|null
     * } $data
     */
    private function persistLlmLog(array $data): ?int
    {
        if (($data['bot_id'] ?? null) === null) {
            return null;
        }
        if (! Schema::hasTable('bot_llm_logs')) {
            return null;
        }

        $id = DB::table('bot_llm_logs')->insertGetId([
            'bot_id' => (int) $data['bot_id'],
            'bot_analysis_run_id' => $data['analysis_run_id'] ?: null,
            'instrument' => $data['instrument'],
            'provider' => 'openrouter',
            'model' => $data['model'],
            'status' => $data['status'],
            'latency_ms' => $data['latency_ms'],
            'request_json' => isset($data['request_payload']) ? json_encode($data['request_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'response_json' => isset($data['response_payload']) ? json_encode($data['response_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'request_text' => $data['request_text'] ?? null,
            'response_text' => $data['response_text'] ?? null,
            'usage_json' => isset($data['usage']) ? json_encode($data['usage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error_message' => $data['error_message'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return is_numeric($id) ? (int) $id : null;
    }
}
