<?php

namespace App\Services\Bots;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterClient
{
    /**
     * Возвращает список моделей OpenRouter для UI/валидации настроек.
     *
     * @return list<array{id:string,name:string}>
     */
    public function listModels(): array
    {
        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $timeoutSec = (int) config('services.openrouter.timeout_sec', 25);

        $response = Http::timeout(max(5, $timeoutSec))
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
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });

        return $out;
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
    public function chatCompletion(
        string $model,
        array $messages,
        float $temperature = 0.0,
        int $maxTokens = 350
    ): array {
        $apiKey = (string) config('services.openrouter.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY не задан. Добавьте ключ в .env и config/services.php.');
        }

        $baseUrl = rtrim((string) config('services.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $referer = (string) config('services.openrouter.referer', '');
        $title = (string) config('services.openrouter.title', '');
        $timeoutSec = (int) config('services.openrouter.timeout_sec', 25);

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

        $response = Http::timeout(max(5, $timeoutSec))
            ->withHeaders($headers)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'temperature' => max(0.0, min(2.0, $temperature)),
                'max_tokens' => max(32, min(4096, $maxTokens)),
                'stream' => false,
            ]);

        $response->throw();

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('OpenRouter вернул некорректный JSON-ответ.');
        }

        return $json;
    }
}
