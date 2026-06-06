<?php

namespace App\Support;

/**
 * Форматирование LLM-запросов для страницы логов бота.
 * В OpenRouter уходит массив messages (system + user), а в request_text раньше писали только user.
 */
final class OpenRouterLlmLogFormatter
{
    /**
     * Полный текст запроса для отображения: все роли из messages, как ушло в API.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function formatRequestText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = trim((string) ($message['role'] ?? 'unknown'));
            $content = (string) ($message['content'] ?? '');
            $parts[] = "--- {$role} ---\n{$content}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Текст запроса для UI: приоритетно messages из request_json (старые логи), иначе request_text.
     *
     * @param  array<string, mixed>|null  $requestJson
     */
    public static function requestTextForDisplay(?array $requestJson, ?string $requestText): string
    {
        $messages = $requestJson['messages'] ?? null;
        if (is_array($messages) && $messages !== []) {
            return self::formatRequestText($messages);
        }

        return trim((string) ($requestText ?? ''));
    }
}
