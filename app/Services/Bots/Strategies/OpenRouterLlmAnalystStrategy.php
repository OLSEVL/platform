<?php

namespace App\Services\Bots\Strategies;

use App\Services\Bots\OpenRouterClient;
use App\Support\HybridAnalystConfig;
use App\Support\OpenRouterChartLevelsParser;
use App\Support\OpenRouterLlmConfig;
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
        $shortPromptMode = OpenRouterLlmConfig::isAnalystShortPromptMode($cfg);
        // В кратком режиме используем отдельный лимит токенов из настроек бота (без жёсткого cap 64..512).
        // Нормализация диапазона выполняется внутри OpenRouterClient::chatCompletion.
        if ($shortPromptMode) {
            $shortCap = (int) ($cfg['max_tokens_short'] ?? OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT);
            $maxTokens = min($maxTokens, $shortCap);
        }
        $timeoutSec = OpenRouterClient::resolveChatTimeoutSec($cfg);
        $systemPrompt = OpenRouterLlmConfig::resolveAnalystSystemPrompt($cfg);
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
        $analystSmcEnabled = (bool) ($context['analyst_smc_flow_enabled'] ?? false);
        /** @var array<string,mixed> $smcFlowSummary */
        $smcFlowSummary = $analystSmcEnabled && is_array($context['smc_flow_summary'] ?? null)
            ? $context['smc_flow_summary']
            : [];
        $analystSrlEnabled = (bool) ($context['analyst_srl_flow_enabled'] ?? false);
        /** @var array<string,mixed> $sweepRaidSummary */
        $sweepRaidSummary = $analystSrlEnabled && is_array($context['sweep_raid_summary'] ?? null)
            ? $context['sweep_raid_summary']
            : [];
        $analystEngulfEnabled = (bool) ($context['analyst_engulf_flow_enabled'] ?? false);
        /** @var array<string,mixed> $engulfingSummary */
        $engulfingSummary = $analystEngulfEnabled && is_array($context['engulfing_summary'] ?? null)
            ? $context['engulfing_summary']
            : [];

        // Гибрид: полный decision source-бота (runtime), без изменения сохранённого system_prompt.
        /** @var array<string,mixed>|null $hybridSourceSignal */
        $hybridSourceSignal = is_array($context['hybrid_source_signal'] ?? null)
            ? $context['hybrid_source_signal']
            : null;
        if ($hybridSourceSignal !== null) {
            $systemPrompt .= HybridAnalystConfig::systemPromptSection();
        }

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
            'response_format' => $shortPromptMode ? 'short_verdict_russian' : 'plain_text_russian',
        ];
        if ($analystSmcEnabled) {
            $payload['smc_flow_summary'] = $smcFlowSummary;
            $payload['expiry_focus_min'] = [3, 6];
        }
        if ($analystSrlEnabled) {
            $payload['sweep_raid_summary'] = $sweepRaidSummary;
            $payload['expiry_focus_sec'] = 60;
        }
        if ($analystEngulfEnabled) {
            $payload['engulfing_summary'] = $engulfingSummary;
            $payload['expiry_focus_sec'] = 60;
        }
        if ($hybridSourceSignal !== null) {
            $payload['hybrid_source_signal'] = $hybridSourceSignal;
        }

        // Гибрид: в user-сообщении тоже фиксируем двухфазный порядок (якорь на source слабее).
        if ($hybridSourceSignal !== null) {
            if ($shortPromptMode) {
                $userContent = "Гибрид, краткий формат: 1) Независимый вердикт: CALL|PUT|NONE (без hybrid_source_signal). "
                    ."2) Одна фраза сверки с source. 3) Рекомендация: CALL|PUT|NONE. 4) Вероятность успеха: NN%.\n";
            } else {
                $userContent = "Гибридный разбор: сначала независимый вердикт по рынку (без hybrid_source_signal), "
                    ."затем сверка с source и итоговая Рекомендация. Ответ — связный текст на русском.\n";
            }
        } elseif ($analystEngulfEnabled && $shortPromptMode) {
            $userContent = "Подтверди или отклони candidates из engulfing_summary: CALL/PUT/NONE и вероятность успеха.\n";
        } elseif ($analystEngulfEnabled) {
            $userContent = "Подтверди или отклони уже найденные цепочки ENGULF 1/2/3 из engulfing_summary. Ответ — связный текст на русском.\n";
        } elseif ($analystSrlEnabled && $shortPromptMode) {
            $userContent = "Подтверди или отклони candidates из sweep_raid_summary: CALL/PUT/NONE и вероятность успеха.\n";
        } elseif ($analystSrlEnabled) {
            $userContent = "Подтверди или отклони уже найденные SWEEP/RAID/PB из sweep_raid_summary. Ответ — связный текст на русском.\n";
        } elseif ($shortPromptMode) {
            $userContent = "Дай краткий вердикт по данным ниже: только направление (CALL/PUT/NONE) и вероятность успеха в процентах.\n";
        } else {
            $userContent = "Проанализируй график по данным ниже. Ответ — связный текст на русском.\n";
        }
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
                    'analyst_prompt_mode' => OpenRouterLlmConfig::analystPromptMode($cfg),
                ],
                'request_text' => \App\Support\OpenRouterLlmLogFormatter::formatRequestText($requestMessages),
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

        // Текст для UI без блока chart_levels; JSON уровней — отдельно для графика (только полный режим).
        $split = OpenRouterChartLevelsParser::splitAssistantResponse((string) $assistantText);
        $chartParsed = $shortPromptMode
            ? ['ok' => false, 'message' => null, 'data' => ['levels' => [], 'zones' => [], 'entry' => null]]
            : OpenRouterChartLevelsParser::normalize($split['chart_levels_raw'], $split['fence_found']);
        $displayText = $shortPromptMode ? trim((string) $assistantText) : $split['analysis_text'];

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
                'analyst_prompt_mode' => OpenRouterLlmConfig::analystPromptMode($cfg),
            ],
            'request_text' => \App\Support\OpenRouterLlmLogFormatter::formatRequestText($requestMessages),
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
                'analyst_smc_flow_enabled' => $analystSmcEnabled,
                'smc_flow_modules' => $analystSmcEnabled ? ($smcFlowSummary['modules'] ?? []) : [],
                'analyst_srl_flow_enabled' => $analystSrlEnabled,
                'srl_candidates_count' => $analystSrlEnabled
                    ? count($sweepRaidSummary['candidates'] ?? [])
                    : 0,
                'analyst_engulf_flow_enabled' => $analystEngulfEnabled,
                'engulf_candidates_count' => $analystEngulfEnabled
                    ? count($engulfingSummary['candidates'] ?? [])
                    : 0,
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
                'analyst_prompt_mode' => OpenRouterLlmConfig::analystPromptMode($cfg),
                // Метка гибрида — чтобы в логах отличать от ручного/Telegram анализа.
                'hybrid_mode' => $hybridSourceSignal !== null,
                'hybrid_source_bot_id' => $hybridSourceSignal['source_bot_id'] ?? null,
                'hybrid_source_signal_id' => $hybridSourceSignal['source_signal_id'] ?? null,
                'hybrid_source_side' => $hybridSourceSignal['side'] ?? null,
            ],
            'llm_log_id' => $llmLogId,
        ];
    }

    private function defaultSystemPrompt(array $cfg): string
    {
        return OpenRouterLlmConfig::resolveAnalystSystemPrompt($cfg);
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
