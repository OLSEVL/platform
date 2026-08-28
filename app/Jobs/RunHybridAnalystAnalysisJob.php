<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Support\ChartAnalystBotTypes;
use App\Services\Bots\BotDataService;
use App\Services\Bots\BotEngineService;
use App\Services\Bots\HybridAnalystTradeService;
use App\Services\Telegram\TelegramAlertService;
use App\Services\Telegram\TelegramChartImageRenderer;
use App\Services\Telegram\StrongLevelTelegramLlmButton;
use App\Support\HybridAnalystConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Гибридный LLM-анализ: те же данные Chart Analyst + полный decision source-бота.
 * Результат — в bot_llm_logs; при прохождении фильтров — Telegram и опционально paper/PO.
 */
class RunHybridAnalystAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /** Уникальность: один прогон на пару analyst + source_signal. */
    public int $uniqueFor = 600;

    /**
     * @param  array<string, mixed>  $sourceSignal  hybrid_source_signal payload
     */
    public function __construct(
        public int $analystBotId,
        public string $instrument,
        public array $sourceSignal,
    ) {
        $this->onQueue(RunTelegramAnalystAnalysisJob::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        $signalId = (int) ($this->sourceSignal['source_signal_id'] ?? 0);

        return 'hybrid-analyst:'.$this->analystBotId.':'.$signalId;
    }

    public function handle(
        BotEngineService $engine,
        BotDataService $dataService,
        TelegramAlertService $telegram,
        TelegramChartImageRenderer $chartRenderer,
        HybridAnalystTradeService $tradeService,
    ): void {
        $bot = Bot::query()->find($this->analystBotId);
        if (! $bot || ! ChartAnalystBotTypes::isAnalyst($bot->type)) {
            return;
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (! HybridAnalystConfig::isEnabled($cfg)) {
            return;
        }

        $sourceSide = strtoupper((string) ($this->sourceSignal['side'] ?? ''));
        $sourceName = (string) ($this->sourceSignal['source_bot_name'] ?? 'source');
        $sourceType = (string) ($this->sourceSignal['source_bot_type'] ?? '');
        // Side source намеренно не пишем в вопрос — иначе модель якорится до независимого вердикта.
        // Side/decision лежат только в hybrid_source_signal (фаза B промпта).
        $question = sprintf(
            'Гибридный анализ: сначала независимый вердикт по рынку, затем сверка с ботом «%s» (%s). Не подглядывай side source до независимого вердикта.',
            $sourceName,
            $sourceType !== '' ? $sourceType : 'bot'
        );

        $analysis = $engine->runManualChartAnalysis(
            $bot,
            $this->instrument,
            $question,
            $this->sourceSignal,
        );

        if (($analysis['error'] ?? null) !== null || trim((string) ($analysis['analysis_text'] ?? '')) === '') {
            Log::warning('hybrid_analyst_empty', [
                'analyst_bot_id' => $bot->id,
                'instrument' => $this->instrument,
                'source_signal_id' => $this->sourceSignal['source_signal_id'] ?? null,
                'error' => $analysis['error'] ?? null,
                'summary' => $analysis['summary'] ?? null,
            ]);

            return;
        }

        $analysisText = (string) $analysis['analysis_text'];
        $llmLogId = isset($analysis['llm_log_id']) ? (int) $analysis['llm_log_id'] : null;
        $filtersOk = HybridAnalystConfig::passesHybridFilters($cfg, $analysisText, $sourceSide);

        // Фильтры общие для Telegram и торговли. Лог на странице уже сохранён стратегией.
        if (! $filtersOk) {
            Log::info('hybrid_analyst_skipped_publish_filter', [
                'analyst_bot_id' => $bot->id,
                'instrument' => $this->instrument,
                'publish' => HybridAnalystConfig::publishMode($cfg),
                'min_success_percent' => HybridAnalystConfig::minSuccessPercent($cfg),
                'llm_side' => HybridAnalystConfig::extractRecommendationSide($analysisText),
                'llm_success_percent' => HybridAnalystConfig::extractSuccessPercent($analysisText),
                'source_side' => $sourceSide,
            ]);

            return;
        }

        // Paper (+ PO): side только из LLM; expiry из source; сумма PO из профиля.
        $tradeResult = $tradeService->maybeOpenTrade(
            $bot,
            $this->instrument,
            $analysisText,
            $cfg,
            $this->sourceSignal,
            $llmLogId,
        );
        if (! ($tradeResult['ok'] ?? false) && ($tradeResult['skipped'] ?? '') !== 'filters_or_paper_disabled') {
            Log::info('hybrid_analyst_trade_skipped', [
                'analyst_bot_id' => $bot->id,
                'instrument' => $this->instrument,
                'skipped' => $tradeResult['skipped'] ?? null,
            ]);
        }

        $creds = $telegram->analystTelegramCredentials($bot);
        $token = $creds['token'];
        /** @var array<string, mixed> $botCfg */
        $botCfg = is_array($bot->config) ? $bot->config : [];
        // Гибрид-алерты — всем подписчикам Chart Analyst (primary + telegram_subscribers).
        $recipients = \App\Services\Telegram\TelegramChatAccess::recipientChatIds($botCfg);
        if ($token === '' || $recipients === []) {
            Log::info('hybrid_analyst_telegram_skipped_no_creds', [
                'analyst_bot_id' => $bot->id,
                'instrument' => $this->instrument,
            ]);

            return;
        }

        $llmSide = HybridAnalystConfig::extractRecommendationSide($analysisText) ?? '?';
        $chartLevels = is_array($analysis['chart_levels'] ?? null) ? $analysis['chart_levels'] : [];
        $pngPath = null;
        if (extension_loaded('gd')) {
            $series = $dataService->series($this->instrument, 120, true, 31536000, 1);
            $candles = $series['series'];
            if ($candles !== []) {
                $pngPath = $chartRenderer->render($this->instrument, $candles, $chartLevels);
            }
        }

        $tradeNote = '';
        if ($tradeResult['ok'] ?? false) {
            $tradeNote = "\n📈 Paper #".(int) ($tradeResult['trade_id'] ?? 0)
                .(($tradeResult['mirrored'] ?? false) ? ' · PO mirror' : '');
        }

        $header = '🔀 <b>Гибридный анализ '.htmlspecialchars($this->instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n"
            .'LLM: '.$llmSide
            .' · Source: '.htmlspecialchars($sourceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .' ('.htmlspecialchars($sourceType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').') → '.$sourceSide
            .$tradeNote."\n\n";
        $body = htmlspecialchars($analysisText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // После LLM — исходный ответ/decision source (уже был в payload гибрида).
        $sourceReply = HybridAnalystConfig::formatSourceReplyForTelegram($this->sourceSignal);
        $sourceBlock = "\n\n————\n".htmlspecialchars($sourceReply, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $billingLabel = trim((string) ($analysis['billing_label'] ?? ''));
        $levelsText = $telegram->formatChartLevelsText($chartLevels);
        $footerParts = array_filter([
            $billingLabel !== '' ? '💰 '.htmlspecialchars($billingLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '',
            $levelsText,
        ]);
        // Inline-кнопка: независимый LLM без hybrid_source_signal (как у source-ботов).
        $llmMarkup = StrongLevelTelegramLlmButton::inlineKeyboardMarkupForAnalystSelf($bot, $this->instrument, $cfg);

        $telegram->broadcastToRecipients($token, $recipients, function (string $chatId) use (
            $telegram,
            $token,
            $pngPath,
            $llmSide,
            $sourceName,
            $sourceSide,
            $header,
            $body,
            $sourceBlock,
            $footerParts,
            $llmMarkup,
        ): array {
            $instrumentEsc = htmlspecialchars($this->instrument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($pngPath !== null) {
                $caption = '🔀 <b>Гибрид</b> · '.$instrumentEsc
                    .' · LLM '.$llmSide
                    .' · source: '.htmlspecialchars($sourceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .' '.$sourceSide;
                $telegram->sendPhoto($token, $chatId, $pngPath, $caption);
            }

            $telegram->sendLongMessage($token, $chatId, $header.$body.$sourceBlock);

            if ($footerParts !== []) {
                if ($llmMarkup !== null) {
                    return $telegram->sendMessageWithMarkup($token, $chatId, implode("\n\n", $footerParts), $llmMarkup);
                }

                return $telegram->sendMessage($token, $chatId, implode("\n\n", $footerParts));
            }

            if ($llmMarkup !== null) {
                return $telegram->sendMessageWithMarkup(
                    $token,
                    $chatId,
                    '📊 Независимый анализ по этой паре (без гибрида):',
                    $llmMarkup
                );
            }

            return ['ok' => true];
        });

        if ($pngPath !== null) {
            @unlink($pngPath);
        }
    }
}
