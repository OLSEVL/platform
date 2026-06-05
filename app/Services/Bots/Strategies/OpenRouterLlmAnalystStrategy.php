<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\OpenRouterClient;
use App\Support\OpenRouterChartLevelsParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Стратегия бота «аналитик по запросу»: отправляет свечи в OpenRouter и возвращает
 * свободный текст разбора графика. Не требует JSON и не формирует торговый side.
 */
class OpenRouterLlmAnalystStrategy implements BotStrategyInterface
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
        $userQuestion = trim((string) ($context['user_question'] ?? ''));

        $model = (string) ($cfg['model'] ?? 'openai/gpt-5.2');
        $temperature = (float) ($cfg['temperature'] ?? 0.2);
        $maxTokens = (int) ($cfg['max_tokens'] ?? 900);
        $timeoutSec = OpenRouterClient::resolveChatTimeoutSec($cfg);
        $systemPrompt = (string) ($cfg['system_prompt'] ?? $this->defaultSystemPrompt());
        $mtfEnabled = (bool) ($cfg['mtf_enabled'] ?? true);
        $botId = isset($context['bot_id']) ? (int) $context['bot_id'] : null;

        /** @var array<string, list<array<string,mixed>>> $mtf */
        $mtf = is_array($context['mtf'] ?? null) ? $context['mtf'] : [];
        $m1 = is_array($mtf['1'] ?? null) ? array_values($mtf['1']) : [];
        $m5 = is_array($mtf['5'] ?? null) ? array_values($mtf['5']) : [];
        $m15 = is_array($mtf['15'] ?? null) ? array_values($mtf['15']) : [];
        $h1 = is_array($mtf['60'] ?? null) ? array_values($mtf['60']) : [];

        // В LLM уходит весь M1-ряд, загруженный движком по count (без второго обрезания).
        $closedForLlm = $closedCandles === [] ? [] : array_values($closedCandles);

        /** @var list<array<string,mixed>> $closedS5 */
        $closedS5 = is_array($context['s5_closed'] ?? null) ? array_values($context['s5_closed']) : [];
        $partialS5 = is_array($context['s5_partial'] ?? null) ? $context['s5_partial'] : null;

        $payload = [
            'instrument' => $instrument,
            'payout' => $payout,
            'user_question' => $userQuestion !== '' ? $userQuestion : null,
            'closed_candles_m1' => $closedForLlm,
            'partial_candle_m1' => $partialCandle,
            'closed_candles_s5' => $closedS5,
            'partial_candle_s5' => $partialS5,
            'mtf' => [
                'enabled' => $mtfEnabled,
                'm1_closed' => $m1,
                'm5_closed' => $m5,
                'm15_closed' => $m15,
                'h1_closed' => $h1,
            ],
            'mtf_sizes' => [
                'm1' => count($m1),
                'm5' => count($m5),
                'm15' => count($m15),
                'h1' => count($h1),
                's5' => count($closedS5) + ($partialS5 ? 1 : 0),
            ],
            'response_format' => 'plain_text_russian',
        ];

        $userContent = "Проанализируй график по данным ниже. Ответ — связный текст на русском.\n";
        if ($userQuestion !== '') {
            $userContent .= "Дополнительный вопрос трейдера: {$userQuestion}\n\n";
        }
        $userContent .= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $requestMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
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
                'instrument' => $instrument,
                'model' => $model,
                'request_payload' => [
                    'messages' => $requestMessages,
                    'payload' => $payload,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'timeout_sec' => $timeoutSec,
                ],
                'request_text' => $userContent,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return null;
        }

        $assistantText = OpenRouterClient::extractAssistantContent($response);
        $finishReason = OpenRouterClient::extractFinishReason($response);
        $rawContent = is_array($response['choices'][0]['message'] ?? null)
            ? ($response['choices'][0]['message']['content'] ?? null)
            : null;
        $usedReasoningField = ($rawContent === null || $rawContent === '') && $assistantText !== null && $assistantText !== '';

        // Текст для UI без блока chart_levels; JSON уровней — отдельно для графика.
        $split = OpenRouterChartLevelsParser::splitAssistantResponse((string) $assistantText);
        $chartParsed = OpenRouterChartLevelsParser::normalize($split['chart_levels_raw'], $split['fence_found']);
        $displayText = $split['analysis_text'];

        $llmLogId = $this->persistLlmLog([
            'bot_id' => $botId,
            'instrument' => $instrument,
            'model' => $model,
            'request_payload' => [
                'messages' => $requestMessages,
                'payload' => $payload,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'timeout_sec' => $timeoutSec,
            ],
            'request_text' => $userContent,
            'response_payload' => $response,
            'response_text' => $displayText,
            'chart_levels_json' => $chartParsed['ok'] ? $chartParsed['data'] : null,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : null,
            'status' => $displayText !== '' ? 'ok' : 'error',
            'error_message' => $displayText === ''
                ? ($finishReason === 'length'
                    ? 'max_tokens_exhausted (ответ обрезан, увеличьте max tokens в настройках)'
                    : 'empty_assistant_content')
                : null,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        if ($displayText === '') {
            return null;
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : null;
        $requestCostUsd = OpenRouterClient::parseRequestCostUsd($usage);

        return [
            'analysis_text' => $displayText,
            'chart_levels' => $chartParsed['data'],
            'chart_levels_ok' => $chartParsed['ok'],
            'chart_levels_message' => $chartParsed['message'],
            'request_cost_usd' => $requestCostUsd,
            'usage' => $usage,
            'features' => [
                'provider' => 'openrouter',
                'model' => $model,
                'candles_sent' => count($closedForLlm),
                's5_bars_sent' => count($closedS5) + ($partialS5 ? 1 : 0),
                'mtf_enabled' => $mtfEnabled,
                'm1_bars_sent' => count($m1),
                'm5_bars_sent' => count($m5),
                'm15_bars_sent' => count($m15),
                'h1_bars_sent' => count($h1),
                'user_question_present' => $userQuestion !== '',
                'finish_reason' => $finishReason,
                'used_reasoning_field' => $usedReasoningField,
                'request_cost_usd' => $requestCostUsd,
                'chart_levels_ok' => $chartParsed['ok'],
            ],
            'llm_log_id' => $llmLogId,
        ];
    }

    private function defaultSystemPrompt(): string
    {
        return OpenRouterChartLevelsParser::defaultAnalystSystemPrompt();
    }

    /**
     * @param array{
     *   bot_id:int|null,
     *   instrument:string,
     *   model:string,
     *   request_payload:array<string,mixed>|null,
     *   request_text:string|null,
     *   response_payload?:array<string,mixed>|null,
     *   response_text?:string|null,
     *   chart_levels_json?:array<string,mixed>|null,
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

        $row = [
            'bot_id' => (int) $data['bot_id'],
            'bot_analysis_run_id' => null,
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
        ];
        if (Schema::hasColumn('bot_llm_logs', 'chart_levels_json')) {
            $row['chart_levels_json'] = isset($data['chart_levels_json'])
                ? json_encode($data['chart_levels_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }

        $id = DB::table('bot_llm_logs')->insertGetId($row);

        return is_numeric($id) ? (int) $id : null;
    }
}
