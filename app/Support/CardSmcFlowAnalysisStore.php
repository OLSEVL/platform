<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Состояние текущего прогона «Доп. анализ нейросетью» с веб-карточек.
 * Только для поллинга ЭТОГО запроса (job пишет результат по token).
 * Готовый текст LLM не переиспользуется: каждый begin() — новый token и новый вызов модели.
 */
final class CardSmcFlowAnalysisStore
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    /** Token живёт, пока фронт поллит этот прогон (LLM 1–3 мин + запас). */
    private const TTL_SEC = 900;

    /**
     * Всегда новый прогон: рынок на M1 меняется за секунды, старый ответ модели не годится.
     *
     * @return array{token:string,state:array<string,mixed>}
     */
    public function begin(int $botId, int $tradeId): array
    {
        $token = (string) Str::uuid();
        $now = time();
        $state = [
            'status' => self::STATUS_PENDING,
            'bot_id' => $botId,
            'trade_id' => $tradeId,
            'analyst_bot_id' => null,
            'instrument' => null,
            'question' => null,
            'analysis_text' => null,
            'summary' => '⏳ Запускаю LLM… Обычно 1–3 мин.',
            'billing_label' => null,
            'model' => null,
            'error' => null,
            'error_detail' => null,
            'started_at' => $now,
            'finished_at' => null,
        ];
        $this->putState($token, $state);
        $this->cache()->put($this->tradeKey($botId, $tradeId), $token, self::TTL_SEC);

        return [
            'token' => $token,
            'state' => $state,
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function patch(string $token, array $patch): void
    {
        $current = $this->get($token);
        if ($current === null) {
            return;
        }
        $this->putState($token, array_merge($current, $patch));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stored = $this->cache()->get($this->runKey($token));

        return is_array($stored) ? $stored : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function publicPayload(array $state): array
    {
        return [
            'status' => (string) ($state['status'] ?? self::STATUS_PENDING),
            'instrument' => isset($state['instrument']) ? (string) $state['instrument'] : null,
            'summary' => isset($state['summary']) ? (string) $state['summary'] : null,
            'analysis_text' => isset($state['analysis_text']) ? (string) $state['analysis_text'] : null,
            'billing_label' => isset($state['billing_label']) ? (string) $state['billing_label'] : null,
            'model' => isset($state['model']) ? (string) $state['model'] : null,
            'error' => isset($state['error']) ? (string) $state['error'] : null,
            'error_detail' => isset($state['error_detail']) ? (string) $state['error_detail'] : null,
            'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : null,
            'finished_at' => isset($state['finished_at']) ? (int) $state['finished_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function putState(string $token, array $state): void
    {
        $this->cache()->put($this->runKey($token), $state, self::TTL_SEC);
    }

    /**
     * file-кэш на этой машине пишут разные uid (php-fpm / queue www-data / artisan owner) —
     * job падает с Permission denied. Таблица cache общая для веб и воркера.
     */
    private function cache(): Repository
    {
        $default = (string) config('cache.default');
        if ($default === 'file') {
            return Cache::store('database');
        }

        return Cache::store($default);
    }

    private function runKey(string $token): string
    {
        return 'card_smc_flow:run:'.$token;
    }

    private function tradeKey(int $botId, int $tradeId): string
    {
        return 'card_smc_flow:trade:'.$botId.':'.$tradeId;
    }
}
