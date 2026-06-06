<?php

namespace App\Services\Bots;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenRouterClient
{
    /**
     * Возвращает список моделей OpenRouter для UI/валидации настроек.
     *
     * @return list<array{
     *   id:string,
     *   name:string,
     *   pricing:?array{prompt:float|null,completion:float|null},
     *   pricing_label:string
     * }>
     */
    public function listModels(): array
    {
        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        // Каталог /models большой (300+ записей) — даём запас по таймауту относительно chat/completions.
        $timeoutSec = max(30, min(120, (int) config('services.openrouter.timeout_sec', 25) * 2));

        $response = Http::timeout($timeoutSec)
            ->connectTimeout(15)
            ->get("{$baseUrl}/models");
        $response->throw();

        $json = $response->json();
        $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $pricing = self::normalizePricing(is_array($row['pricing'] ?? null) ? $row['pricing'] : null);
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'pricing' => $pricing,
                'pricing_label' => self::formatPricingLabel($pricing),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Читает цены prompt/completion из ответа OpenRouter /models.
     * Значения в API — USD за один токен (строка или число).
     *
     * @param  array<string,mixed>|null  $raw
     * @return array{prompt:float|null,completion:float|null}|null
     */
    public static function normalizePricing(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $prompt = self::pricingFieldToFloat($raw['prompt'] ?? null);
        $completion = self::pricingFieldToFloat($raw['completion'] ?? null);
        if ($prompt === null && $completion === null) {
            return null;
        }

        return [
            'prompt' => $prompt,
            'completion' => $completion,
        ];
    }

    /**
     * Форматирует цену для подписи в select: USD за 1M токенов (in/out).
     *
     * @param  array{prompt:float|null,completion:float|null}|null  $pricing
     */
    public static function formatPricingLabel(?array $pricing): string
    {
        if ($pricing === null) {
            return '';
        }

        $prompt = $pricing['prompt'] ?? null;
        $completion = $pricing['completion'] ?? null;
        if ($prompt === null && $completion === null) {
            return '';
        }

        if (($prompt ?? 0.0) === 0.0 && ($completion ?? 0.0) === 0.0) {
            return 'бесплатно';
        }

        $inLabel = self::formatUsdPerMillionTokens($prompt);
        $outLabel = self::formatUsdPerMillionTokens($completion);

        return "in {$inLabel}/M · out {$outLabel}/M";
    }

    /**
     * Подпись option: название, id и цена (если есть в каталоге).
     *
     * @param  array{id?:string,name?:string,pricing_label?:string}  $model
     */
    public static function modelSelectLabel(array $model): string
    {
        $modelId = (string) ($model['id'] ?? '');
        $modelName = (string) ($model['name'] ?? $modelId);
        $pricingLabel = trim((string) ($model['pricing_label'] ?? ''));
        if ($pricingLabel === '' && is_array($model['pricing'] ?? null)) {
            $pricingLabel = self::formatPricingLabel(self::normalizePricing($model['pricing']));
        }

        $label = "{$modelName} — {$modelId}";
        if ($pricingLabel !== '') {
            $label .= " · {$pricingLabel}";
        }

        return $label;
    }

    private static function pricingFieldToFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function formatUsdPerMillionTokens(?float $usdPerToken): string
    {
        if ($usdPerToken === null) {
            return '—';
        }

        $perMillion = $usdPerToken * 1_000_000;
        if ($perMillion === 0.0) {
            return '$0';
        }
        if ($perMillion >= 1) {
            return '$'.rtrim(rtrim(number_format($perMillion, 2, '.', ''), '0'), '.');
        }
        if ($perMillion >= 0.01) {
            return '$'.rtrim(rtrim(number_format($perMillion, 3, '.', ''), '0'), '.');
        }

        return '$'.rtrim(rtrim(number_format($perMillion, 4, '.', ''), '0'), '.');
    }

    /**
     * Отправляет запрос в OpenRouter Chat Completions API и возвращает сырой JSON-ответ провайдера.
     *
     * Мы оставляем этот слой максимально "тонким": никакой торговой логики внутри, только HTTP-вызов.
     * Это упрощает тестирование и позволяет переиспользовать клиент для любых LLM-стратегий.
     *
     * @param  array<int, array{role:string,content:string}>  $messages
     * @return array<string,mixed>
     */
    /**
     * Таймаут chat/completions: из config бота (openrouter_timeout_sec) или .env fallback.
     *
     * @param  array<string,mixed>|null  $botConfig
     */
    public static function resolveChatTimeoutSec(?array $botConfig = null): int
    {
        $max = max(60, (int) config('services.openrouter.timeout_sec_max', 600));
        $fromBot = is_array($botConfig) ? (int) ($botConfig['openrouter_timeout_sec'] ?? 0) : 0;
        if ($fromBot > 0) {
            return max(30, min($max, $fromBot));
        }

        $fromEnv = (int) config('services.openrouter.timeout_sec', 120);

        return max(60, min($max, $fromEnv));
    }

    public function chatCompletion(
        string $model,
        array $messages,
        float $temperature = 0.0,
        int $maxTokens = 350,
        ?int $timeoutSec = null
    ): array {
        $apiKey = (string) config('services.openrouter.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY не задан. Добавьте ключ в .env и config/services.php.');
        }

        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $referer = (string) config('services.openrouter.referer', '');
        $title = (string) config('services.openrouter.title', '');
        $timeoutSec = $timeoutSec !== null && $timeoutSec > 0
            ? max(30, min((int) config('services.openrouter.timeout_sec_max', 600), $timeoutSec))
            : self::resolveChatTimeoutSec(null);
        $connectTimeout = min(30, max(10, (int) ceil($timeoutSec / 4)));

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ];
        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }
        if ($title !== '') {
            $headers['X-OpenRouter-Title'] = $title;
        }

        $response = Http::connectTimeout($connectTimeout)
            ->timeout($timeoutSec)
            ->withHeaders($headers)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'temperature' => max(0.0, min(2.0, $temperature)),
                // В настройках аналитика допускается до 8192; reasoning-модели часто тратят лимит на reasoning.
                'max_tokens' => max(32, min(8192, $maxTokens)),
                'stream' => false,
            ]);

        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('OpenRouter вернул некорректный JSON-ответ.');
        }

        return $json;
    }

    /**
     * Извлекает текст ответа ассистента из OpenRouter/OpenAI-совместимого JSON.
     * Поддерживает reasoning-модели (minimax, o-series): content может быть null,
     * а текст лежит в message.reasoning или reasoning_details.
     */
    public static function extractAssistantContent(array $response): ?string
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

        $fromContent = self::normalizeMessageContent($message['content'] ?? null);
        if ($fromContent !== '') {
            return $fromContent;
        }

        $fromReasoning = trim((string) ($message['reasoning'] ?? ''));
        if ($fromReasoning !== '') {
            return $fromReasoning;
        }

        $details = $message['reasoning_details'] ?? null;
        if (is_array($details)) {
            $parts = [];
            foreach ($details as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                $text = trim((string) ($detail['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            if ($parts !== []) {
                return trim(implode("\n", $parts));
            }
        }

        return null;
    }

    public static function extractFinishReason(array $response): ?string
    {
        $choices = $response['choices'] ?? null;
        if (! is_array($choices) || ! is_array($choices[0] ?? null)) {
            return null;
        }

        $reason = $choices[0]['finish_reason'] ?? $choices[0]['native_finish_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @param  mixed  $content
     */
    private static function normalizeMessageContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = trim($part);
                continue;
            }
            if (! is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if ($type !== '' && $type !== 'text') {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * Разбор стоимости из usage OpenRouter: итого + вход (prompt) + выход (completion).
     * Поле usage.cost — полная цена вызова (не только prompt).
     *
     * @param  array<string,mixed>|null  $usage
     * @return array{
     *   total_cost_usd:float|null,
     *   prompt_cost_usd:float|null,
     *   completion_cost_usd:float|null
     * }
     */
    public static function parseUsageCosts(?array $usage): array
    {
        $empty = [
            'total_cost_usd' => null,
            'prompt_cost_usd' => null,
            'completion_cost_usd' => null,
        ];
        if (! is_array($usage)) {
            return $empty;
        }

        $total = isset($usage['cost']) && is_numeric($usage['cost'])
            ? (float) $usage['cost']
            : null;

        $details = is_array($usage['cost_details'] ?? null) ? $usage['cost_details'] : null;
        $prompt = null;
        $completion = null;
        if ($details !== null) {
            if (isset($details['upstream_inference_prompt_cost']) && is_numeric($details['upstream_inference_prompt_cost'])) {
                $prompt = (float) $details['upstream_inference_prompt_cost'];
            }
            // В API OpenRouter поле называется completions (с «s»).
            if (isset($details['upstream_inference_completions_cost']) && is_numeric($details['upstream_inference_completions_cost'])) {
                $completion = (float) $details['upstream_inference_completions_cost'];
            }
            if ($total === null && isset($details['upstream_inference_cost']) && is_numeric($details['upstream_inference_cost'])) {
                $total = (float) $details['upstream_inference_cost'];
            }
        }

        if ($total === null && $prompt !== null && $completion !== null) {
            $total = $prompt + $completion;
        }

        return [
            'total_cost_usd' => $total,
            'prompt_cost_usd' => $prompt,
            'completion_cost_usd' => $completion,
        ];
    }

    /**
     * Полная стоимость одного chat/completions-вызова (USD) — alias для total_cost_usd.
     *
     * @param  array<string,mixed>|null  $usage
     */
    public static function parseRequestCostUsd(?array $usage): ?float
    {
        return self::parseUsageCosts($usage)['total_cost_usd'];
    }

    /**
     * Токены одного запроса из блока usage ответа OpenRouter (prompt/completion/total).
     *
     * @param  array<string,mixed>|null  $usage
     * @return array{prompt_tokens:int|null,completion_tokens:int|null,total_tokens:int|null}
     */
    public static function parseRequestTokens(?array $usage): array
    {
        $empty = [
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
        ];
        if (! is_array($usage)) {
            return $empty;
        }

        $prompt = isset($usage['prompt_tokens']) && is_numeric($usage['prompt_tokens'])
            ? (int) $usage['prompt_tokens']
            : null;
        $completion = isset($usage['completion_tokens']) && is_numeric($usage['completion_tokens'])
            ? (int) $usage['completion_tokens']
            : null;
        $total = isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])
            ? (int) $usage['total_tokens']
            : null;
        if ($total === null && $prompt !== null && $completion !== null) {
            $total = $prompt + $completion;
        }

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }

    /**
     * Баланс OpenRouter: GET /credits → total_credits, total_usage, остаток = credits − usage.
     *
     * @return array{
     *   total_credits_usd:float,
     *   total_usage_usd:float,
     *   balance_remaining_usd:float
     * }|null
     */
    public function fetchAccountCredits(): ?array
    {
        $apiKey = (string) config('services.openrouter.api_key', '');
        if ($apiKey === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->withToken($apiKey)
                ->get("{$baseUrl}/credits");
            $response->throw();
            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : null;
            if ($data === null) {
                return null;
            }

            $totalCredits = (float) ($data['total_credits'] ?? 0);
            $totalUsage = (float) ($data['total_usage'] ?? 0);

            return [
                'total_credits_usd' => $totalCredits,
                'total_usage_usd' => $totalUsage,
                'balance_remaining_usd' => max(0.0, $totalCredits - $totalUsage),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Подпись стоимости: вход (prompt) + выход (completion) + итого за анализ.
     *
     * @param  array{
     *   total_cost_usd?:float|null,
     *   prompt_cost_usd?:float|null,
     *   completion_cost_usd?:float|null,
     *   request_cost_usd?:float|null
     * }  $billing
     */
    public static function formatCostUsageLabel(array $billing): string
    {
        $total = $billing['total_cost_usd'] ?? $billing['request_cost_usd'] ?? null;
        $prompt = $billing['prompt_cost_usd'] ?? null;
        $completion = $billing['completion_cost_usd'] ?? null;

        if ($prompt !== null && $completion !== null) {
            $parts = [
                'Вход: '.self::formatUsd($prompt),
                'Выход: '.self::formatUsd($completion),
                'Итого: '.self::formatUsd($total ?? ($prompt + $completion)),
            ];

            return implode(' · ', $parts);
        }

        if ($total !== null && $total >= 0) {
            return 'Итого анализа: '.self::formatUsd($total);
        }

        return '';
    }

    /**
     * Человекочитаемая подпись для UI: стоимость анализа, токены и остаток баланса.
     *
     * @param  array{
     *   request_cost_usd?:float|null,
     *   total_cost_usd?:float|null,
     *   prompt_cost_usd?:float|null,
     *   completion_cost_usd?:float|null,
     *   balance_remaining_usd?:float|null,
     *   total_credits_usd?:float|null,
     *   total_usage_usd?:float|null,
     *   prompt_tokens?:int|null,
     *   completion_tokens?:int|null,
     *   total_tokens?:int|null
     * }  $billing
     */
    public static function formatBillingLabel(array $billing): string
    {
        $parts = [];
        $costLabel = self::formatCostUsageLabel($billing);
        if ($costLabel !== '') {
            $parts[] = $costLabel;
        }
        $tokenLabel = self::formatTokenUsageLabel($billing);
        if ($tokenLabel !== '') {
            $parts[] = $tokenLabel;
        }
        $balance = $billing['balance_remaining_usd'] ?? null;
        if ($balance !== null && $balance >= 0) {
            $parts[] = 'Баланс: '.self::formatUsd($balance);
        }

        return $parts !== [] ? implode(' · ', $parts) : '';
    }

    /**
     * Подпись расхода токенов для строки метаданных под анализом.
     *
     * @param  array<string, mixed>  $billing
     */
    public static function formatTokenUsageLabel(array $billing): string
    {
        $total = isset($billing['total_tokens']) && is_numeric($billing['total_tokens'])
            ? (int) $billing['total_tokens']
            : null;
        $prompt = isset($billing['prompt_tokens']) && is_numeric($billing['prompt_tokens'])
            ? (int) $billing['prompt_tokens']
            : null;
        $completion = isset($billing['completion_tokens']) && is_numeric($billing['completion_tokens'])
            ? (int) $billing['completion_tokens']
            : null;

        if ($total === null && $prompt === null && $completion === null) {
            return '';
        }

        if ($total !== null) {
            $label = 'Токены: '.self::formatTokenCount($total);
            if ($prompt !== null && $completion !== null) {
                $label .= self::formatPromptCompletionBreakdown($prompt, $completion);
            }

            return $label;
        }

        if ($prompt !== null && $completion !== null) {
            return 'Токены: '.self::formatTokenCount($prompt + $completion)
                .self::formatPromptCompletionBreakdown($prompt, $completion);
        }

        $single = $prompt ?? $completion;
        if ($single === null) {
            return '';
        }

        $kind = $prompt !== null ? 'prompt tokens' : 'completion tokens';

        return 'Токены: '.self::formatTokenCount($single).' ( '.$kind.': '.self::formatTokenCount($single).' )';
    }

    /** Скобки с подписанными prompt/completion для строки метаданных. */
    public static function formatPromptCompletionBreakdown(int $prompt, int $completion): string
    {
        return ' ( prompt tokens: '.self::formatTokenCount($prompt)
            .' + completion tokens: '.self::formatTokenCount($completion).' )';
    }

    public static function formatTokenCount(int $count): string
    {
        return number_format(max(0, $count), 0, '', ' ');
    }

    public static function formatUsd(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }
        $abs = abs($amount);
        if ($abs > 0 && $abs < 0.01) {
            return '$'.rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.');
        }

        return '$'.rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.');
    }
}
