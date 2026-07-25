<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Services\Bots\BotDataService;
use App\Services\Bots\BotEngineService;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramAnalystReplyKeyboard;
use App\Services\Telegram\TelegramAnalystStateStore;
use App\Services\Telegram\TelegramChartImageRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Асинхронный OpenRouter-анализ для Telegram: 1–3 мин, затем текст + billing + PNG уровней.
 */
class RunTelegramAnalystAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Отдельная очередь — job не перехватывает mirror/default worker с timeout 180. */
    public const QUEUE_NAME = 'telegram-analyst';

    public int $timeout = 600;

    public function __construct(
        public int $botId,
        public string $chatId,
        public string $instrument,
        public string $userQuestion = '',
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(
        BotEngineService $engine,
        BotDataService $dataService,
        TelegramAlertService $telegram,
        TelegramChartImageRenderer $chartRenderer,
        TelegramAnalystStateStore $stateStore,
    ): void {
        $bot = Bot::query()->find($this->botId);
        if (! $bot || $bot->type !== 'openrouter_llm_analyst') {
            return;
        }

        $creds = $telegram->analystTelegramCredentials($bot);
        $token = $creds['token'];
        if ($token === '') {
            return;
        }

        $analysis = $engine->runManualChartAnalysis($bot, $this->instrument, $this->userQuestion);

        if (($analysis['error'] ?? null) !== null || trim((string) ($analysis['analysis_text'] ?? '')) === '') {
            $summary = (string) ($analysis['summary'] ?? 'Не удалось выполнить анализ.');
            $detail = (string) ($analysis['error_detail'] ?? $analysis['error'] ?? '');
            $msg = "❌ <b>Ошибка анализа {$this->instrument}</b>\n\n".htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($detail !== '') {
                $msg .= "\n".htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $telegram->sendMessageWithMarkup($token, $this->chatId, $msg, TelegramAnalystReplyKeyboard::mainMarkup());
            $stateStore->put($bot->id, $this->chatId, ['step' => TelegramAnalystStateStore::STEP_MENU]);

            return;
        }

        $chartLevels = is_array($analysis['chart_levels'] ?? null) ? $analysis['chart_levels'] : [];
        $pngPath = null;
        // Картинку шлём всегда, когда есть свечи и GD — не ждём chart_levels_ok от LLM.
        if (extension_loaded('gd')) {
            $series = $dataService->series($this->instrument, 120, true, 31536000, 1);
            $candles = $series['series'];
            if ($candles !== []) {
                $pngPath = $chartRenderer->render($this->instrument, $candles, $chartLevels);
            } else {
                Log::warning('telegram_analyst_photo_skipped', [
                    'bot_id' => $bot->id,
                    'instrument' => $this->instrument,
                    'reason' => 'no_candles',
                ]);
            }
        } else {
            Log::warning('telegram_analyst_photo_skipped', [
                'bot_id' => $bot->id,
                'instrument' => $this->instrument,
                'reason' => 'gd_extension_missing',
            ]);
        }

        if ($pngPath === null && extension_loaded('gd')) {
            Log::warning('telegram_analyst_photo_skipped', [
                'bot_id' => $bot->id,
                'instrument' => $this->instrument,
                'reason' => 'render_returned_null',
                'chart_levels_ok' => (bool) ($analysis['chart_levels_ok'] ?? false),
            ]);
        }

        if ($pngPath !== null) {
            $caption = '📊 <b>'.htmlspecialchars($this->instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b> · M1';
            $photoResult = $telegram->sendPhoto($token, $this->chatId, $pngPath, $caption);
            if (! ($photoResult['ok'] ?? false)) {
                Log::warning('telegram_analyst_photo_failed', [
                    'bot_id' => $bot->id,
                    'instrument' => $this->instrument,
                    'error' => $photoResult['error'] ?? null,
                ]);
            }
            @unlink($pngPath);
        }

        $header = '📈 <b>Анализ '.htmlspecialchars($this->instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n\n";
        $body = htmlspecialchars((string) $analysis['analysis_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $telegram->sendLongMessage($token, $this->chatId, $header.$body);

        $billingLabel = trim((string) ($analysis['billing_label'] ?? ''));
        $levelsText = $telegram->formatChartLevelsText($chartLevels);
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        // Та же inline-кнопка, что у гибрид-алерта: повторный LLM без hybrid_source_signal.
        $llmMarkup = StrongLevelTelegramLlmButton::inlineKeyboardMarkupForAnalystSelf($bot, $this->instrument, $cfg);
        $footerParts = array_filter([
            $billingLabel !== '' ? '💰 '.htmlspecialchars($billingLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '',
            $levelsText,
            "\n".TelegramAnalystReplyKeyboard::menuHint().' — новый анализ',
        ]);
        if ($footerParts !== []) {
            if ($llmMarkup !== null) {
                $telegram->sendMessageWithMarkup($token, $this->chatId, implode("\n\n", $footerParts), $llmMarkup);
            } else {
                $telegram->sendMessageWithMarkup(
                    $token,
                    $this->chatId,
                    implode("\n\n", $footerParts),
                    TelegramAnalystReplyKeyboard::mainMarkup()
                );
            }
        } elseif ($llmMarkup !== null) {
            $telegram->sendMessageWithMarkup(
                $token,
                $this->chatId,
                '📊 Повторить анализ по этой паре:',
                $llmMarkup
            );
        }

        $stateStore->put($bot->id, $this->chatId, [
            'step' => TelegramAnalystStateStore::STEP_MENU,
            'instrument' => null,
        ]);
    }
}
