<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Services\Bots\BotEngineService;
use App\Support\CardSmcFlowAnalysisStore;
use App\Support\ChartAnalystBotTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LLM-анализ Chart Analyst для кнопки на веб-карточках.
 * Та же очередь и timeout, что у Telegram «Доп. анализ нейросетью».
 */
class RunCardSmcFlowAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public int $analystBotId,
        public string $instrument,
        public string $userQuestion,
        public string $token,
    ) {
        $this->onQueue(RunTelegramAnalystAnalysisJob::QUEUE_NAME);
    }

    public function handle(BotEngineService $engine, CardSmcFlowAnalysisStore $store): void
    {
        try {
            $this->runAnalysis($engine, $store);
        } catch (Throwable $e) {
            Log::error('card_smc_flow_analysis_failed', [
                'analyst_bot_id' => $this->analystBotId,
                'instrument' => $this->instrument,
                'token' => $this->token,
                'error' => $e->getMessage(),
            ]);
            try {
                $store->patch($this->token, [
                    'status' => CardSmcFlowAnalysisStore::STATUS_ERROR,
                    'error' => 'exception',
                    'error_detail' => $e->getMessage(),
                    'summary' => 'Не удалось выполнить анализ.',
                    'finished_at' => time(),
                ]);
            } catch (Throwable $inner) {
                Log::error('card_smc_flow_analysis_store_failed', [
                    'token' => $this->token,
                    'error' => $inner->getMessage(),
                ]);
            }
        }
    }

    private function runAnalysis(BotEngineService $engine, CardSmcFlowAnalysisStore $store): void
    {
        $bot = Bot::query()->find($this->analystBotId);
        if (! $bot || ! ChartAnalystBotTypes::isAnalyst($bot->type)) {
            $store->patch($this->token, [
                'status' => CardSmcFlowAnalysisStore::STATUS_ERROR,
                'error' => 'analyst_missing',
                'summary' => 'Бот Chart Analyst не найден.',
                'finished_at' => time(),
            ]);

            return;
        }

        $analysis = $engine->runManualChartAnalysis($bot, $this->instrument, $this->userQuestion);

        $text = trim((string) ($analysis['analysis_text'] ?? ''));
        if (($analysis['error'] ?? null) !== null || $text === '') {
            $store->patch($this->token, [
                'status' => CardSmcFlowAnalysisStore::STATUS_ERROR,
                'error' => (string) ($analysis['error'] ?? 'llm_empty_or_failed'),
                'error_detail' => (string) ($analysis['error_detail'] ?? ''),
                'summary' => (string) ($analysis['summary'] ?? 'Не удалось выполнить анализ.'),
                'model' => isset($analysis['model']) ? (string) $analysis['model'] : null,
                'billing_label' => isset($analysis['billing_label']) ? (string) $analysis['billing_label'] : null,
                'finished_at' => time(),
            ]);

            return;
        }

        $store->patch($this->token, [
            'status' => CardSmcFlowAnalysisStore::STATUS_OK,
            'error' => null,
            'error_detail' => null,
            'analysis_text' => $text,
            'summary' => (string) ($analysis['summary'] ?? 'Анализ готов.'),
            'model' => isset($analysis['model']) ? (string) $analysis['model'] : null,
            'billing_label' => isset($analysis['billing_label']) ? (string) $analysis['billing_label'] : null,
            'finished_at' => time(),
        ]);
    }
}
