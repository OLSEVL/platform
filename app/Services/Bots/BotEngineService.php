<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotAnalysisRun;
use App\Models\BotLlmLog;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
use App\Models\PocketInstrument;
use App\Services\Bots\Strategies\BotStrategyInterface;
use App\Services\Bots\Strategies\CandleReversalLevelStrategy;
use App\Services\Bots\Strategies\BollingerContrtrendStrategy;
use App\Services\Bots\Strategies\CciTradeStrategy;
use App\Services\Bots\Strategies\PinBarTradeStrategy;
use App\Services\Bots\Strategies\RsiContrtrendStrategy;
use App\Services\Bots\Strategies\RsiTradeStrategy;
use App\Services\Bots\Strategies\RviTradeStrategy;
use App\Services\Bots\Strategies\ThreeSoldierStrategy;
use App\Services\Bots\Strategies\WilliamsRangeTradeStrategy;
use App\Services\Bots\Strategies\OpenRouterLlmAnalystStrategy;
use App\Services\Bots\Strategies\OpenRouterLlmStrategy;
use App\Services\Bots\Strategies\SmcFlowStrategy;
use App\Services\Bots\Strategies\StrongLevelStrategy;
use App\Services\Bots\Strategies\StrongTrendTelegramStrategy;
use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use App\Services\Bots\Strategies\EnvelopTrendStrategy;
use App\Services\Bots\Strategies\PriceChannelTradeStrategy;
use App\Services\Bots\Strategies\ParabolicSarTradeStrategy;
use App\Services\Bots\Strategies\BreakLinRegChannelStrategy;
use App\Services\Bots\Strategies\PivotPointsStrategy;
use App\Services\Bots\Strategies\SmaStochasticStrategy;
use App\Services\Bots\Strategies\MomentumMacdStrategy;
use App\Services\Bots\Strategies\ParabolicBollingerStrategy;
use App\Services\Bots\Strategies\ParabolicPriceChannelStrategy;
use App\Services\Bots\Strategies\VolAdaptiveCandlesStrategy;
use App\Services\Bots\Strategies\ThreeSoldierVolAdaptiveStrategy;
use App\Services\Bots\Strategies\CustomCandlesImpulseStrategy;
use App\Services\CandleService;
use App\Services\Indicators\SmcFlowBotSignalAnalyzer;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Services\Mirror\MirrorTradeDispatcher;
use App\Services\Telegram\TelegramAlertService;
use App\Support\BotScanProgress;
use App\Support\LevelBounceBotTypes;
use App\Support\SmcFlowLlmSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BotEngineService
{
    public function __construct(
        private readonly BotDataService $botDataService,
        private readonly OpenRouterClient $openRouterClient,
        private readonly SmcFlowBotSignalAnalyzer $smcFlowBotSignalAnalyzer,
    ) {}

    public function scanBot(Bot $bot): array
    {
        // Бот-аналитик: только ручной запрос с графика, плановый scan не выполняем никогда.
        if ($bot->type === 'openrouter_llm_analyst') {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        if (! $bot->is_active) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        $runtime = $this->runtimeSettings($bot);
        $config = $runtime['config'];
        $count = $runtime['count'];
        $expirySec = $runtime['expiry_sec'];
        $minConfidence = $runtime['min_confidence'];
        $minPayout = $runtime['min_payout'];
        $staleAfterSec = $runtime['stale_after_sec'];
        $minClosedBars = $runtime['min_closed_bars'];
        $dedupeBucketSec = $runtime['dedupe_bucket_sec'];
        // Опциональный whitelist инструментов для "pro" режима:
        // если задан в config.allowed_instruments, бот сканирует только их.
        // Это позволяет исключить "шумные" пары и концентрироваться на стабильных инструментах.
        $allowedInstrumentSet = [];
        if (is_array($config['allowed_instruments'] ?? null)) {
            foreach ($config['allowed_instruments'] as $rawInstrument) {
                $instrumentKey = trim((string) $rawInstrument);
                if ($instrumentKey !== '') {
                    $allowedInstrumentSet[$instrumentKey] = true;
                }
            }
        }
        // Опциональный blacklist инструментов для отсечения слабых пар.
        $blockedInstrumentSet = [];
        if (is_array($config['blocked_instruments'] ?? null)) {
            foreach ($config['blocked_instruments'] as $rawInstrument) {
                $instrumentKey = trim((string) $rawInstrument);
                if ($instrumentKey !== '') {
                    $blockedInstrumentSet[$instrumentKey] = true;
                }
            }
        }

        $strategy = $this->resolveStrategy($bot->type);
        if (! $strategy) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        $instruments = PocketInstrument::active()
            ->orderByDesc('current_payout')
            ->orderByDesc('priority')
            ->orderBy('instrument')
            ->get(['instrument', 'current_payout']);

        $processed = 0;
        $signals = 0;
        $trades = 0;
        // Логи analysis-runs включаются только при одновременном выполнении двух условий:
        // 1) таблица bot_analysis_runs существует (миграции применены),
        // 2) у конкретного бота флаг analysis_runs_logging_enabled не выключен в config.
        // Такой подход даёт персональную настройку шумности/нагрузки по каждому боту.
        $analysisLogsEnabled = Schema::hasTable('bot_analysis_runs') && (bool) ($config['analysis_runs_logging_enabled'] ?? true);
        // Считаем пары для sidebar-счётчика: whitelist/blacklist + payout не ниже min_payout.
        $scanInstrumentTotal = 0;
        foreach ($instruments as $instrumentRow) {
            $instrumentKey = (string) $instrumentRow->instrument;
            if ($allowedInstrumentSet !== [] && ! isset($allowedInstrumentSet[$instrumentKey])) {
                continue;
            }
            if (isset($blockedInstrumentSet[$instrumentKey])) {
                continue;
            }
            $rowPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
            if ($rowPayout === null || $rowPayout < $minPayout) {
                continue;
            }
            $scanInstrumentTotal++;
        }
        // Sidebar: серые точки → red на текущей паре → green после проверки; между scan снова все серые.
        $scanRunId = BotScanProgress::beginScan($bot->id, $scanInstrumentTotal);
        try {
            foreach ($instruments as $instrumentRow) {
                $instrument = (string) $instrumentRow->instrument;
                if ($allowedInstrumentSet !== [] && ! isset($allowedInstrumentSet[$instrument])) {
                    continue;
                }
                if (isset($blockedInstrumentSet[$instrument])) {
                    continue;
                }
                // Низкий payout: не участвует в scan — без серой/красной/зелёной точки и без счётчика.
                $currentPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
                if ($currentPayout === null || $currentPayout < $minPayout) {
                    continue;
                }
                $processed++;
                // Пишем в cache текущую пару — sidebar бота показывает красный индикатор «идёт анализ».
                BotScanProgress::setCurrentInstrument($bot->id, $instrument);
                try {
                    $analysisRun = $analysisLogsEnabled ? BotAnalysisRun::query()->create([
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'status' => BotAnalysisRun::STATUS_RUNNING,
                        'started_at' => now(),
                        'runtime_json' => [
                            'bot_type' => $bot->type,
                            'count' => $count,
                            'expiry_sec' => $expirySec,
                            'min_confidence' => $minConfidence,
                            'min_payout' => $minPayout,
                            'stale_after_sec' => $staleAfterSec,
                            'min_closed_bars' => $minClosedBars,
                            'dedupe_bucket_sec' => $dedupeBucketSec,
                        ],
                    ]) : null;
                    $seriesTimeframe = (int) ($runtime['series_timeframe'] ?? 1);
                    $series = $this->botDataService->series($instrument, $count, true, $staleAfterSec, $seriesTimeframe);
                    $closed = $series['closed'];
                    if (count($closed) < $minClosedBars) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'not_enough_bars', [
                            'closed_bars' => count($closed),
                            'min_closed_bars' => $minClosedBars,
                        ]);

                        continue;
                    }
                    $partial = $series['partial'];
                    $mtf = [];
                    if ($bot->type === 'candle_reversal_confirm' || $bot->type === 'strong_level') {
                        // Strong Level: M5/M15/H1 для bias и swing-уровней; M1 — тайминг входа.
                        $mtf = $this->botDataService->mtfSeries($instrument, [
                            5 => (int) ($config['m5_take'] ?? 120),
                            15 => (int) ($config['m15_take'] ?? 80),
                            60 => (int) ($config['h1_take'] ?? 48),
                        ]);
                    } elseif ($bot->type === 'openrouter_llm' || $bot->type === 'openrouter_llm_analyst') {
                        // OpenRouter: MTF-контекст из настроек (для analyst — M1/M5/M15/H1).
                        $mtf = $this->openRouterMtfSeries($instrument, $config, $bot->type === 'openrouter_llm_analyst');
                    }
                    $analyzeContext = [
                        'bot_id' => $bot->id,
                        'analysis_run_id' => $analysisRun?->id,
                        'instrument' => $instrument,
                        'payout' => $currentPayout,
                        'config' => $config,
                        'mtf' => $mtf,
                    ];
                    if ($bot->type === 'smc_flow') {
                        $analyzeContext['smc_params'] = IndicatorSetting::mergedSmcFlowParams($bot);
                    }
                    if ($bot->type === 'strong_level' || $bot->type === 'candle_reversal_confirm') {
                        $analyzeContext['pa_params'] = IndicatorSetting::mergedPriceActionDgtParams($bot);
                        $analyzeContext['smc_params'] = IndicatorSetting::mergedSmcFlowParams($bot);
                    }
                    $decision = $strategy->analyze($closed, $partial, $analyzeContext);
                    if (! $decision) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'strategy_no_signal', [
                            'market' => [
                                'payout' => $currentPayout,
                                'closed_bars' => count($closed),
                            ],
                        ]);

                        continue;
                    }

                    // SMC Flow: стратегия может вернуть адаптивную экспирацию (2..10 мин).
                    $decisionExpirySec = $expirySec;
                    if (in_array($bot->type, ['smc_flow', 'strong_level', 'candle_reversal_confirm'], true) && isset($decision['expiry_sec'])) {
                        $decisionExpirySec = max(120, min(600, (int) $decision['expiry_sec']));
                    }

                    $confidence = (float) ($decision['confidence'] ?? 0);
                    if ($confidence < $minConfidence) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'confidence_below_threshold', [
                            'decision' => $decision,
                            'confidence' => $confidence,
                            'min_confidence' => $minConfidence,
                        ]);

                        continue;
                    }

                    $side = (string) ($decision['side'] ?? '');
                    if (! in_array($side, ['CALL', 'PUT'], true)) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'invalid_side', [
                            'decision' => $decision,
                            'side' => $side,
                        ]);

                        continue;
                    }

                    // Dedupe-окно привязываем к фактической экспирации конкретного сигнала,
                    // чтобы логика "один сигнал на окно" корректно работала и для 2/3/4/5 минут.
                    $signalDedupeBucketSec = in_array($bot->type, ['smc_flow', 'strong_level', 'candle_reversal_confirm'], true)
                        ? max(60, min(900, $decisionExpirySec))
                        : $dedupeBucketSec;
                    $bucket = intdiv(time(), $signalDedupeBucketSec) * $signalDedupeBucketSec;
                    $trendKey = (string) ($decision['trend'] ?? ($side === 'CALL' ? 'up' : 'down'));
                    // Telegram-алерт: dedupe по направлению тренда; strong_level — по стороне и цене уровня.
                    $levelPrice = (float) (($decision['features']['level_price'] ?? 0));
                    $dedupeSuffix = match ($bot->type) {
                        'strong_trend_telegram' => $trendKey,
                        'strong_level', 'candle_reversal_confirm' => sprintf('%s:%.5f', $side, $levelPrice),
                        default => $side,
                    };
                    $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $dedupeSuffix);

                    if (BotSignal::query()->where('dedupe_key', $dedupeKey)->exists()) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'dedupe', [
                            'dedupe_key' => $dedupeKey,
                            'decision' => $decision,
                        ]);

                        continue;
                    }

                    // Режим alert-only: пишем сигнал + Telegram, но не создаём paper-сделку.
                    // Для strong_trend_telegram режим берём из config.execution_mode/notify_only.
                    $isAlertOnlyMode = (bool) ($decision['notify_only'] ?? false);
                    if ($bot->type === 'strong_trend_telegram') {
                        $executionMode = (string) (($config['execution_mode'] ?? '') ?: ((bool) ($config['notify_only'] ?? true) ? 'alert_only' : 'signal_trade'));
                        $isAlertOnlyMode = $executionMode === 'alert_only';
                    }
                    if ($isAlertOnlyMode) {
                        $entryAt = now();
                        $payoutSnapshot = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
                        $telegramResult = null;
                        $createdSignalId = 0;

                        DB::transaction(function () use (
                            $bot,
                            $instrument,
                            $side,
                            $confidence,
                            $payoutSnapshot,
                            $entryAt,
                            $decision,
                            $dedupeKey,
                            $signalDedupeBucketSec,
                            &$signals,
                            &$createdSignalId

                        ): void {
                            $signal = BotSignal::query()->create([
                                'bot_id' => $bot->id,
                                'instrument' => $instrument,
                                'side' => $side,
                                'confidence' => $confidence,
                                'payout_snapshot' => $payoutSnapshot,
                                'signal_at' => $entryAt,
                                // expiry_time для алерт-бота = конец dedupe-окна (история/фильтр, не экспирация сделки).
                                'expiry_time' => $entryAt->copy()->addSeconds($signalDedupeBucketSec),
                                'reasons_json' => $decision['reasons_short'] ?? [],
                                'analysis_text' => (string) ($decision['analysis_text'] ?? ''),
                                'features_json' => $decision['features'] ?? [],
                                'decision_version' => (string) ($decision['decision_version'] ?? 'v1'),
                                'dedupe_key' => $dedupeKey,
                            ]);
                            $createdSignalId = (int) $signal->id;
                            $signals++;
                        });

                        // Гибрид Chart Analyst: on_signal (alert-only path, без paper/PO).
                        if ($createdSignalId > 0) {
                            app(HybridAnalystDispatcher::class)->maybeDispatch(
                                $bot,
                                $createdSignalId,
                                $instrument,
                                $side,
                                $confidence,
                                $decision,
                                \App\Support\HybridAnalystConfig::TRIGGER_ON_SIGNAL,
                            );
                        }

                        $telegramResult = app(TelegramAlertService::class)->sendTrendAlert(
                            $bot,
                            $instrument,
                            $decision,
                            $payoutSnapshot,
                            $closed,
                        );
                        if (! ($telegramResult['ok'] ?? false)) {
                            Log::warning('strong_trend_telegram_alert_failed', [
                                'bot_id' => $bot->id,
                                'instrument' => $instrument,
                                'error' => $telegramResult['error'] ?? 'unknown',
                            ]);
                        }

                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_OK, null, [
                            'decision' => $decision,
                            'notify_only' => $isAlertOnlyMode,
                            'telegram' => $telegramResult,
                        ]);

                        continue;
                    }

                    $hasOpenTrade = BotTrade::query()
                        ->where('bot_id', $bot->id)
                        ->where('instrument', $instrument)
                        ->where('status', BotTrade::STATUS_OPEN)
                        ->exists();
                    if ($hasOpenTrade) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'open_trade_exists', [
                            'decision' => $decision,
                        ]);

                        continue;
                    }

                    // Вход = момент принятия решения (scan), не open бара: paper/график/PO с одной длительностью expiry_sec.
                    $entryPrice = (float) (($partial['close'] ?? null) ?? end($closed)['close']);
                    // БД-сессия MySQL в UTC — signal_at/entry_time/expiry_time тоже в UTC.
                    $entryAt = now();
                    $expiryAt = $entryAt->copy()->addSeconds($decisionExpirySec);
                    $payoutSnapshot = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;

                    $mirrorTradeIds = [];
                    $createdSignalId = 0;
                    $createdTradeId = 0;
                    DB::transaction(function () use (
                        $bot,
                        $instrument,
                        $side,
                        $confidence,
                        $payoutSnapshot,
                        $entryAt,
                        $expiryAt,
                        $decisionExpirySec,
                        $decision,
                        $dedupeKey,
                        $entryPrice,
                        &$signals,
                        &$trades,
                        &$mirrorTradeIds,
                        &$createdSignalId,
                        &$createdTradeId
                    ): void {
                        $signal = BotSignal::query()->create([
                            'bot_id' => $bot->id,
                            'instrument' => $instrument,
                            'side' => $side,
                            'confidence' => $confidence,
                            'payout_snapshot' => $payoutSnapshot,
                            'signal_at' => $entryAt,
                            'expiry_time' => $expiryAt,
                            'reasons_json' => $decision['reasons_short'] ?? [],
                            'analysis_text' => (string) ($decision['analysis_text'] ?? ''),
                            'features_json' => $decision['features'] ?? [],
                            'decision_version' => (string) ($decision['decision_version'] ?? 'v1'),
                            'dedupe_key' => $dedupeKey,
                        ]);
                        $createdSignalId = (int) $signal->id;

                        $trade = BotTrade::query()->create([
                            'bot_id' => $bot->id,
                            'bot_signal_id' => $signal->id,
                            'instrument' => $instrument,
                            'side' => $side,
                            'status' => BotTrade::STATUS_OPEN,
                            'result' => BotTrade::RESULT_PENDING,
                            'entry_time' => $entryAt,
                            'expiry_time' => $expiryAt,
                            'entry_price' => $entryPrice,
                            'payout_snapshot' => $payoutSnapshot,
                            'meta_json' => [
                                'confidence' => $confidence,
                                'decision_version' => $signal->decision_version,
                                // Полная длительность на PO = expiry_sec_used (см. BotTradeMirrorService::resolveDurationSec).
                                'expiry_sec_used' => $decisionExpirySec,
                            ],
                        ]);
                        $createdTradeId = (int) $trade->id;

                        // Если стратегия вернула id LLM-лога, привязываем лог к созданным signal/trade.
                        // Это соединяет "сырые request/response модели" с реальной сделкой.
                        if (Schema::hasTable('bot_llm_logs') && isset($decision['llm_log_id']) && is_numeric($decision['llm_log_id'])) {
                            BotLlmLog::query()
                                ->whereKey((int) $decision['llm_log_id'])
                                ->where('bot_id', $bot->id)
                                ->update([
                                    'bot_signal_id' => $signal->id,
                                    'bot_trade_id' => $trade->id,
                                ]);
                        }

                        $signals++;
                        $trades++;
                        $mirrorTradeIds[] = $trade->id;

                        // Зеркало PO — сразу после commit (sync), без очереди Laravel.
                        foreach ($mirrorTradeIds as $mirrorTradeId) {
                            DB::afterCommit(function () use ($mirrorTradeId): void {
                                app(MirrorTradeDispatcher::class)->dispatch($mirrorTradeId);
                            });
                        }
                        $mirrorTradeIds = [];
                    });

                    // Гибрид Chart Analyst после paper-сигнала:
                    // on_signal — любой CALL/PUT с записью сигнала;
                    // on_po_mirror — только если у source включено зеркало (dispatch уже поставлен).
                    if ($createdSignalId > 0) {
                        $hybrid = app(HybridAnalystDispatcher::class);
                        $hybrid->maybeDispatch(
                            $bot,
                            $createdSignalId,
                            $instrument,
                            $side,
                            $confidence,
                            $decision,
                            \App\Support\HybridAnalystConfig::TRIGGER_ON_SIGNAL,
                            $createdTradeId > 0 ? $createdTradeId : null,
                        );
                        $hybrid->maybeDispatch(
                            $bot,
                            $createdSignalId,
                            $instrument,
                            $side,
                            $confidence,
                            $decision,
                            \App\Support\HybridAnalystConfig::TRIGGER_ON_PO_MIRROR,
                            $createdTradeId > 0 ? $createdTradeId : null,
                        );
                    }

                    // Strong Level / Candle Reversal / RSI Contrtrend: push в Telegram после paper-сделки.
                    $telegramResult = null;
                    if (LevelBounceBotTypes::supportsCards($bot->type)) {
                        $telegramResult = app(TelegramAlertService::class)->sendStrongLevelAlert(
                            $bot,
                            $instrument,
                            $decision,
                            $payoutSnapshot,
                            $decisionExpirySec,
                            $entryPrice,
                            $closed
                        );
                        if (! ($telegramResult['ok'] ?? false)
                            && ! in_array($telegramResult['error'] ?? '', ['telegram_disabled', 'telegram_notify_disabled'], true)) {
                            Log::warning('level_bounce_telegram_alert_failed', [
                                'bot_id' => $bot->id,
                                'bot_type' => $bot->type,
                                'instrument' => $instrument,
                                'error' => $telegramResult['error'] ?? 'unknown',
                            ]);
                        }
                    } elseif ($bot->type === 'strong_trend_telegram') {
                        // Режим signal_trade: сделка уже создана выше — шлём тот же trend-алерт, что и в alert_only.
                        $telegramResult = app(TelegramAlertService::class)->sendTrendAlert(
                            $bot,
                            $instrument,
                            $decision,
                            $payoutSnapshot,
                            $closed,
                        );
                        if (! ($telegramResult['ok'] ?? false)
                            && ! in_array($telegramResult['error'] ?? '', ['telegram_disabled'], true)) {
                            Log::warning('strong_trend_telegram_alert_failed', [
                                'bot_id' => $bot->id,
                                'instrument' => $instrument,
                                'error' => $telegramResult['error'] ?? 'unknown',
                            ]);
                        }
                    }

                    $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_OK, null, [
                        'decision' => $decision,
                        'entry_price' => $entryPrice,
                        'entry_time' => $entryAt->toIso8601String(),
                        'expiry_time' => $expiryAt->toIso8601String(),
                        'telegram' => $telegramResult,
                    ]);
                } finally {
                    // Пара проверена: red → green в sidebar текущего scan.
                    BotScanProgress::finishInstrument($bot->id, $instrument);
                }
            }

            return [
                'processed' => $processed,
                'signals' => $signals,
                'trades' => $trades,
            ];
        } finally {
            BotScanProgress::endScan($bot->id, $scanRunId);
        }
    }

    /**
     * Диагностика по одному инструменту: показывает, почему бот войдёт/не войдёт сейчас.
     * Метод не создаёт сигнал/сделку и не меняет состояние БД.
     *
     * @return array<string,mixed>
     */
    /**
     * Ручной / гибридный анализ графика для бота openrouter_llm_analyst: без фильтров payout/confidence/stale,
     * свечи отправляются в LLM в любом объёме, который удалось загрузить.
     *
     * @param  array<string, mixed>|null  $hybridSourceSignal  полный decision source (гибрид); null = обычный анализ
     * @return array{
     *   instrument:string,
     *   summary:string,
     *   analysis_text:string,
     *   user_question:string,
     *   model:?string,
     *   llm_log_id:?int,
     *   error:?string
     * }
     */
    public function runManualChartAnalysis(
        Bot $bot,
        string $instrument,
        string $userQuestion = '',
        ?array $hybridSourceSignal = null,
    ): array
    {
        if ($bot->type !== 'openrouter_llm_analyst') {
            return [
                'instrument' => $instrument,
                'summary' => 'Ручной анализ доступен только для бота-аналитика.',
                'analysis_text' => '',
                'user_question' => $userQuestion,
                'model' => null,
                'llm_log_id' => null,
                'error' => 'unsupported_bot_type',
            ];
        }

        $strategy = $this->resolveStrategy($bot->type);
        if (! $strategy) {
            return [
                'instrument' => $instrument,
                'summary' => 'Стратегия аналитика не зарегистрирована.',
                'analysis_text' => '',
                'user_question' => $userQuestion,
                'model' => null,
                'llm_log_id' => null,
                'error' => 'strategy_missing',
            ];
        }

        $instrumentRow = PocketInstrument::query()
            ->where('instrument', $instrument)
            ->first(['instrument', 'current_payout']);
        if (! $instrumentRow) {
            return [
                'instrument' => $instrument,
                'summary' => 'Инструмент не найден.',
                'analysis_text' => '',
                'user_question' => $userQuestion,
                'model' => null,
                'llm_log_id' => null,
                'error' => 'instrument_not_found',
            ];
        }

        $config = is_array($bot->config) ? $bot->config : [];
        $count = \App\Support\OpenRouterLlmConfig::m1BarCount($config);
        $s5Count = \App\Support\OpenRouterLlmConfig::s5BarCount($config);
        // stale_after_sec не проверяем: передаём очень большой порог «свежести».
        $series = $this->botDataService->series($instrument, $count, true, 31536000, 1);
        $closed = $series['closed'];
        $partial = $series['partial'];
        // S5 — сырой секундный поток ingest (4005), для тайминга внутри текущей M1.
        $s5Series = $this->botDataService->s5Series($instrument, $s5Count, true, 31536000);
        $mtf = $this->openRouterMtfSeries($instrument, $config, true);
        $analystSmcEnabled = \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($config);
        $smcFlowPack = $analystSmcEnabled
            ? $this->buildAnalystSmcFlowPack($bot, $instrument, $count)
            : null;

        $analyzeContext = [
            'bot_id' => $bot->id,
            'instrument' => $instrument,
            'payout' => $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null,
            'config' => $config,
            'mtf' => $mtf,
            's5_closed' => $s5Series['closed'],
            's5_partial' => $s5Series['partial'],
            'user_question' => mb_substr(trim($userQuestion), 0, 2000),
            'analyst_smc_flow_enabled' => $analystSmcEnabled,
        ];
        if ($analystSmcEnabled && is_array($smcFlowPack)) {
            $analyzeContext['smc_flow_summary'] = $smcFlowPack['snapshot'];
        }
        // Гибрид: полный прогноз source-бота уходит в payload LLM (+ секция в system prompt).
        if (is_array($hybridSourceSignal) && $hybridSourceSignal !== []) {
            $analyzeContext['hybrid_source_signal'] = $hybridSourceSignal;
        }

        $decision = $strategy->analyze($closed, $partial, $analyzeContext);

        $billing = $this->buildOpenRouterBillingPayload($decision);

        if (! is_array($decision) || trim((string) ($decision['analysis_text'] ?? '')) === '') {
            $llmLogId = isset($decision['llm_log_id']) ? (int) $decision['llm_log_id'] : null;
            $errorDetail = $this->lastLlmLogErrorMessage($bot->id, $llmLogId);

            return [
                'instrument' => $instrument,
                'summary' => $this->manualAnalysisFailureSummary($errorDetail, (string) ($config['model'] ?? '')),
                'analysis_text' => '',
                'user_question' => $userQuestion,
                'model' => (string) ($config['model'] ?? null),
                'llm_log_id' => $llmLogId,
                'error' => 'llm_empty_or_failed',
                'error_detail' => $errorDetail,
                'billing' => $billing,
                'billing_label' => OpenRouterClient::formatBillingLabel($billing),
            ];
        }

        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];

        return [
            'instrument' => $instrument,
            'summary' => 'Анализ готов.',
            'analysis_text' => (string) $decision['analysis_text'],
            'chart_levels' => is_array($decision['chart_levels'] ?? null) ? $decision['chart_levels'] : [
                'levels' => [],
                'zones' => [],
                'entry' => null,
            ],
            'chart_levels_ok' => (bool) ($decision['chart_levels_ok'] ?? false),
            'chart_levels_message' => isset($decision['chart_levels_message']) ? (string) $decision['chart_levels_message'] : null,
            'user_question' => $userQuestion,
            'model' => (string) ($features['model'] ?? ($config['model'] ?? '')),
            'llm_log_id' => isset($decision['llm_log_id']) ? (int) $decision['llm_log_id'] : null,
            'error' => null,
            'billing' => $billing,
            'billing_label' => OpenRouterClient::formatBillingLabel($billing),
            'analyst_smc_flow_enabled' => $analystSmcEnabled,
            'analyst_prompt_mode' => (string) ($features['analyst_prompt_mode'] ?? \App\Support\OpenRouterLlmConfig::analystPromptMode($config)),
            'smc_flow' => $analystSmcEnabled && is_array($smcFlowPack) ? $smcFlowPack['overlay'] : null,
            'smc_flow_modules' => $analystSmcEnabled && is_array($smcFlowPack)
                ? ($smcFlowPack['overlay']['modules'] ?? [])
                : [],
            'hybrid_mode' => is_array($hybridSourceSignal) && $hybridSourceSignal !== [],
        ];
    }

    /**
     * SMC Flow на M1 для аналитика: полный overlay для графика + компактный snapshot для LLM.
     * Параметры — из indicator_settings бота (как на графике).
     *
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,bar_count:int}
     */
    private function buildAnalystSmcFlowPack(Bot $bot, string $instrument, int $m1BarCount): array
    {
        $emptyOverlay = [
            'lines' => [],
            'areas' => [],
            'labels' => [],
            'modules' => [],
            'label_size' => 'tiny',
        ];

        $candleService = app(CandleService::class);
        $smcFlow = app(SmcFlowIndicatorService::class);

        // Для структуры Mxwll нужен длинный M1-ряд; в LLM свечей меньше, SMC считаем отдельно.
        $smcBars = min(5000, max($m1BarCount, 1440));
        $since = time() - max(86400, $smcBars * 60);
        $rows = $candleService->seriesForDashboard($instrument, 1, $since, null, $smcBars);
        if ($rows === []) {
            return [
                'snapshot' => SmcFlowLlmSnapshot::build($emptyOverlay, []),
                'overlay' => $emptyOverlay,
                'bar_count' => 0,
            ];
        }

        $params = IndicatorSetting::mergedSmcFlowParams($bot);
        $overlay = $smcFlow->compute($rows, $params);

        return [
            'snapshot' => SmcFlowLlmSnapshot::build($overlay, $rows),
            'overlay' => $overlay,
            'bar_count' => count($rows),
        ];
    }

    /**
     * Собирает блок billing для ответа аналитика: стоимость запроса + остаток на счёте OpenRouter.
     *
     * @param  array<string,mixed>|null  $decision
     * @return array{
     *   request_cost_usd:float|null,
     *   total_cost_usd:float|null,
     *   prompt_cost_usd:float|null,
     *   completion_cost_usd:float|null,
     *   balance_remaining_usd:float|null,
     *   total_credits_usd:float|null,
     *   total_usage_usd:float|null,
     *   prompt_tokens:int|null,
     *   completion_tokens:int|null,
     *   total_tokens:int|null
     * }
     */
    private function buildOpenRouterBillingPayload(?array $decision): array
    {
        $billing = [
            'request_cost_usd' => null,
            'total_cost_usd' => null,
            'prompt_cost_usd' => null,
            'completion_cost_usd' => null,
            'balance_remaining_usd' => null,
            'total_credits_usd' => null,
            'total_usage_usd' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
        ];

        if (is_array($decision)) {
            if (is_array($decision['usage'] ?? null)) {
                $usage = $decision['usage'];
                $costs = OpenRouterClient::parseUsageCosts($usage);
                $billing['total_cost_usd'] = $costs['total_cost_usd'];
                $billing['prompt_cost_usd'] = $costs['prompt_cost_usd'];
                $billing['completion_cost_usd'] = $costs['completion_cost_usd'];
                $billing['request_cost_usd'] = $costs['total_cost_usd'];
                $tokens = OpenRouterClient::parseRequestTokens($usage);
                $billing['prompt_tokens'] = $tokens['prompt_tokens'];
                $billing['completion_tokens'] = $tokens['completion_tokens'];
                $billing['total_tokens'] = $tokens['total_tokens'];
            } elseif (isset($decision['request_cost_usd']) && is_numeric($decision['request_cost_usd'])) {
                $billing['request_cost_usd'] = (float) $decision['request_cost_usd'];
                $billing['total_cost_usd'] = (float) $decision['request_cost_usd'];
            }
        }

        $credits = $this->openRouterClient->fetchAccountCredits();
        if (is_array($credits)) {
            $billing['total_credits_usd'] = $credits['total_credits_usd'];
            $billing['total_usage_usd'] = $credits['total_usage_usd'];
            $billing['balance_remaining_usd'] = $credits['balance_remaining_usd'];
        }

        return $billing;
    }

    public function explainInstrumentDecision(Bot $bot, string $instrument): array
    {
        if ($bot->type === 'openrouter_llm_analyst') {
            return $this->runManualChartAnalysis($bot, $instrument, '');
        }

        $runtime = $this->runtimeSettings($bot);
        $strategy = $this->resolveStrategy($bot->type);
        if (! $strategy) {
            return [
                'instrument' => $instrument,
                'can_enter' => false,
                'summary' => 'Стратегия для этого типа бота не поддерживается.',
                'blockers' => ['Не удалось определить стратегию бота.'],
            ];
        }

        $instrumentRow = PocketInstrument::query()
            ->where('instrument', $instrument)
            ->first(['instrument', 'current_payout']);
        if (! $instrumentRow) {
            return [
                'instrument' => $instrument,
                'can_enter' => false,
                'summary' => 'Инструмент не найден среди доступных в системе.',
                'blockers' => ['Выбранная пара отсутствует в таблице инструментов.'],
            ];
        }

        $series = $this->botDataService->series(
            $instrument,
            $runtime['count'],
            true,
            $runtime['stale_after_sec']
        );
        $closed = $series['closed'];
        if (count($closed) < $runtime['min_closed_bars']) {
            return [
                'instrument' => $instrument,
                'can_enter' => false,
                'summary' => 'Пока недостаточно данных для анализа.',
                'blockers' => [sprintf(
                    'Закрытых свечей: %d, требуется минимум: %d.',
                    count($closed),
                    $runtime['min_closed_bars']
                )],
                'checks' => [
                    'min_closed_bars' => $runtime['min_closed_bars'],
                    'closed_bars' => count($closed),
                ],
            ];
        }

        $mtf = [];
        if ($bot->type === 'candle_reversal_confirm' || $bot->type === 'strong_level') {
            $cfg = $runtime['config'];
            $mtf = $this->botDataService->mtfSeries($instrument, [
                5 => (int) ($cfg['m5_take'] ?? 120),
                15 => (int) ($cfg['m15_take'] ?? 80),
                60 => (int) ($cfg['h1_take'] ?? 48),
            ]);
        } elseif ($bot->type === 'openrouter_llm' || $bot->type === 'openrouter_llm_analyst') {
            $mtf = $this->openRouterMtfSeries($instrument, $runtime['config'], $bot->type === 'openrouter_llm_analyst');
        }

        $explainContext = [
            'instrument' => $instrument,
            'payout' => $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null,
            'config' => $runtime['config'],
            'mtf' => $mtf,
        ];
        if ($bot->type === 'smc_flow') {
            $explainContext['smc_params'] = IndicatorSetting::mergedSmcFlowParams($bot);
        }
        if ($bot->type === 'strong_level' || $bot->type === 'candle_reversal_confirm') {
            $explainContext['pa_params'] = IndicatorSetting::mergedPriceActionDgtParams($bot);
            $explainContext['smc_params'] = IndicatorSetting::mergedSmcFlowParams($bot);
        }
        $decision = $strategy->analyze($closed, $series['partial'], $explainContext);
        if (! $decision) {
            $smcFlowBlockers = [];
            $smcFlowFeatures = [];
            if ($bot->type === 'smc_flow') {
                $cfg = $runtime['config'];
                $confirmMaxBars = max(1, min(120, (int) ($cfg['smc_three_br_confirm_max_bars'] ?? 30)));
                $entryOptions = [
                    'smc_require_ob' => (bool) ($cfg['smc_require_ob'] ?? true),
                    'smc_three_br_allow_pattern' => (bool) ($cfg['smc_three_br_allow_pattern'] ?? true),
                    'smc_ob_touch_max_bars' => max(0, min(12, (int) ($cfg['smc_ob_touch_max_bars'] ?? 3))),
                ];
                $smcParams = IndicatorSetting::mergedSmcFlowParams($bot);
                $smcParams['ut_enabled'] = true;
                $smcParams['three_br_enabled'] = true;
                $smcParams['lux_ob_enabled'] = true;
                $signalContext = $this->smcFlowBotSignalAnalyzer->analyze($closed, $smcParams);
                $utSide = $signalContext['ut']['side'] ?? null;
                $smcFlowFeatures = ['signal_context' => $signalContext, 'entry_options' => $entryOptions];
                if (! is_string($utSide) || ! in_array($utSide, ['CALL', 'PUT'], true)) {
                    $smcFlowBlockers[] = 'UT: нет свежего Buy/Sell на последней закрытой свече.';
                } else {
                    $diag = $this->smcFlowBotSignalAnalyzer->diagnoseEntry(
                        $signalContext,
                        $utSide,
                        $confirmMaxBars,
                        $entryOptions
                    );
                    $smcFlowFeatures['entry_diagnosis'] = $diag;
                    if (! $diag['three_br_ok']) {
                        $smcFlowBlockers[] = sprintf(
                            '3BR: нет confirm/pattern %s в окне %d баров.',
                            $utSide === 'CALL' ? 'bull' : 'bear',
                            $confirmMaxBars
                        );
                    }
                    if (! $diag['ob_ok']) {
                        $smcFlowBlockers[] = 'OB: цена вне активной LuxAlgo зоны нужной стороны.';
                    }
                }
            }

            return [
                'instrument' => $instrument,
                'can_enter' => false,
                'summary' => 'Стратегия не видит валидного сетапа на текущем баре.',
                'analysis_text' => match ($bot->type) {
                    'smc_flow' => 'SMC Flow: нет совпадения UT + 3BR + OB на последней закрытой свече.',
                    'strong_level', 'candle_reversal_confirm' => 'Нет сильного уровня у цены или нет подтверждения отскока (M5/M1).',
                    'rsi_contrtrend' => 'Нет сетапа RSI Contrtrend: нужен аптренд+RSI перепроданность (CALL) или даунтренд+RSI перекупленность (PUT).',
                    'bollinger_contrtrend' => 'Нет сетапа Bollinger Contrtrend: цена должна закрыться за нижней (CALL) или верхней (PUT) полосой BB.',
                    'pinbar_trade' => 'Нет PinBar: нужна свеча с телом в верхней/нижней трети диапазона и SMA по тренду.',
                    'williams_range_trade' => 'Нет сетапа Williams %R: значение вне зон перепроданности/перекупленности.',
                    'three_soldier' => 'Нет паттерна Three Soldiers: нужны 3 свечи одного цвета достаточной высоты.',
                    'cci_trade' => 'Нет сетапа CCI: индикатор не в зоне ниже Down / выше Up.',
                    'rsi_trade' => 'Нет кросса RSI через уровни перепроданности/перекупленности.',
                    'rvi_trade' => 'Нет сетапа RVI: нет бычьего/медвежьего пересечения линий.',
                    'envelop_trend' => 'Нет пробоя Envelops: high≥up (CALL) или low≤down (PUT).',
                    'price_channel_trade' => 'Нет пробоя PriceChannel (канал предыдущего бара).',
                    'parabolic_sar_trade' => 'Нет сигнала SAR (кросс/положение цены относительно SAR).',
                    'break_linreg_channel' => 'Нет пробоя LinReg Channel Fast.',
                    'pivot_points' => 'Нет кросса Pivot R1/S1 на текущем баре.',
                    'sma_stochastic' => 'Нет совпадения SMA±step и кросса Stochastic %K.',
                    'momentum_macd' => 'Нет совпадения MACD/Signal и Momentum ±100.',
                    'parabolic_bollinger' => 'Нет пробоя BB при SAR внутри канала.',
                    'parabolic_price_channel' => 'Нет пробоя PC при SAR внутри канала.',
                    'vol_adaptive_candles' => 'Нет сигнальной свечи: тело меньше порога (vola-adaptive).',
                    'three_soldier_vol_adaptive' => 'Нет Three Soldiers (vola-adaptive): 3 свечи одного цвета и высоты.',
                    'custom_candles_impulse' => 'Нет импульса: N свечей подряд в одну сторону за TimeSpan.',
                    default => '',
                },
                'reasons_short' => [],
                'blockers' => $smcFlowBlockers !== []
                    ? $smcFlowBlockers
                    : ['Нет триггера входа по условиям стратегии.'],
                'features' => $smcFlowFeatures,
            ];
        }

        $side = (string) ($decision['side'] ?? '');
        $confidence = (float) ($decision['confidence'] ?? 0);
        $bucket = intdiv(time(), $runtime['dedupe_bucket_sec']) * $runtime['dedupe_bucket_sec'];
        $levelPrice = (float) (($decision['features']['level_price'] ?? 0));
        $dedupeSuffix = in_array($bot->type, ['strong_level', 'candle_reversal_confirm'], true)
            ? sprintf('%s:%.5f', $side, $levelPrice)
            : $side;
        $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $dedupeSuffix);
        $dedupeExists = BotSignal::query()->where('dedupe_key', $dedupeKey)->exists();
        $hasOpenTrade = BotTrade::query()
            ->where('bot_id', $bot->id)
            ->where('instrument', $instrument)
            ->where('status', BotTrade::STATUS_OPEN)
            ->exists();

        $blockers = [];
        if (! in_array($side, ['CALL', 'PUT'], true)) {
            $blockers[] = 'Стратегия вернула некорректную сторону сделки.';
        }
        if ($confidence < $runtime['min_confidence']) {
            $blockers[] = sprintf(
                'Confidence %.2f ниже порога %.2f.',
                $confidence,
                $runtime['min_confidence']
            );
        }
        $currentPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
        if ($currentPayout === null || $currentPayout < $runtime['min_payout']) {
            $blockers[] = sprintf(
                'Payout %.2f%% ниже порога %.2f%%.',
                $currentPayout ?? 0.0,
                $runtime['min_payout']
            );
        }
        if ($dedupeExists) {
            $blockers[] = 'Сработал dedupe: похожий сигнал уже был в текущем окне экспирации.';
        }
        if ($hasOpenTrade) {
            $blockers[] = 'По этой паре уже есть открытая сделка у данного бота.';
        }

        $canEnter = $blockers === [];

        return [
            'instrument' => $instrument,
            'can_enter' => $canEnter,
            'summary' => $canEnter
                ? sprintf('Есть сигнал %s и ограничений на вход не найдено.', $side)
                : 'Сигнал есть, но вход сейчас заблокирован фильтрами.',
            'side' => $side,
            'confidence' => round($confidence, 4),
            'min_confidence' => $runtime['min_confidence'],
            'analysis_text' => (string) ($decision['analysis_text'] ?? ''),
            'reasons_short' => is_array($decision['reasons_short'] ?? null) ? array_values($decision['reasons_short']) : [],
            'features' => is_array($decision['features'] ?? null) ? $decision['features'] : [],
            'blockers' => $blockers,
            'checks' => [
                'dedupe_bucket_sec' => $runtime['dedupe_bucket_sec'],
                'dedupe_exists' => $dedupeExists,
                'has_open_trade' => $hasOpenTrade,
                'payout' => $currentPayout,
                'min_payout' => $runtime['min_payout'],
                'closed_bars' => count($closed),
                'min_closed_bars' => $runtime['min_closed_bars'],
            ],
        ];
    }

    /**
     * Единый расчёт runtime-параметров, чтобы scan и объяснение работали по одинаковым правилам.
     *
     * @return array{config:array<string,mixed>,count:int,expiry_sec:int,min_confidence:float,min_payout:float,stale_after_sec:int,min_closed_bars:int,dedupe_bucket_sec:int,series_timeframe:int}
     */
    public function runtimeSettings(Bot $bot): array
    {
        $config = is_array($bot->config) ? $bot->config : [];
        $defaultCount = match ($bot->type) {
            'smc_flow' => 500,
            'strong_level' => 500,
            'candle_reversal_confirm' => 500,
            'openrouter_llm_analyst' => 120,
            'strong_trend_telegram' => 120,
            // Wave B vola-adaptive: нужна ~1 день M1 для AdaptSoldiersHeight / AdaptSignalCandleHeight.
            'vol_adaptive_candles', 'three_soldier_vol_adaptive', 'pivot_points' => 2000,
            default => LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 150 : 120,
        };
        $count = match ($bot->type) {
            // LLM-боты: count = сколько M1 загрузить и отправить в модель (см. OpenRouterLlmConfig).
            'openrouter_llm' => \App\Support\OpenRouterLlmConfig::m1BarCount($config, 120, 20),
            'openrouter_llm_analyst' => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            default => max(10, min(5000, (int) ($config['count'] ?? $defaultCount))),
        };
        $expirySec = match ($bot->type) {
            'three_candles_in_row' => (int) ($config['expiry_sec'] ?? 60),
            'smc_flow' => (int) ($config['expiry_sec'] ?? 180),
            'strong_trend_telegram' => (int) ($config['expiry_sec'] ?? 60),
            'strong_level' => (int) ($config['expiry_sec_bounce'] ?? 180),
            // По ТЗ для MTF-разворота вход идёт на 5 минут независимо от рыночной фазы.
            'candle_reversal_confirm' => (int) ($config['expiry_sec'] ?? 300),
            default => (int) ($config['expiry_sec'] ?? (LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 180 : 300)),
        };
        // 0 = не резать по уверенности: все сетапы стратегии проходят этот фильтр.
        // Порог можно поднять в настройках бота, если снова понадобится отсев.
        $minConfidence = (float) ($config['min_confidence'] ?? 0.0);
        // Минимальный payout как экономический фильтр качества входа.
        // Для LLM-бота ставим умеренный дефолтный порог, чтобы не брать пары с совсем слабой выплатой.
        $minPayout = (float) ($config['min_payout'] ?? match ($bot->type) {
            'openrouter_llm' => 70.0,
            'candle_reversal_confirm' => 82.0,
            'three_candles_in_row' => 80.0,
            'strong_trend_telegram' => 80.0,
            default => LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 75.0 : 80.0,
        });
        $staleAfterSec = (int) ($config['stale_after_sec'] ?? (LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 60 : 30));
        $streakLen = max(2, min(30, (int) ($config['streak_len'] ?? 6)));
        // +1 бар, если ждём контр-свечу после серии (wait_opposite_then_continue).
        $trendPatternBars = $streakLen + ((bool) ($config['wait_opposite_then_continue'] ?? false) ? 1 : 0);
        // RSI Contrtrend / PinBar: SMA length.
        $smaLen = max(2, (int) ($config['sma_length'] ?? match ($bot->type) {
            'rsi_contrtrend' => 10,
            'pinbar_trade' => 50,
            default => 50,
        }));
        $rsiLen = max(2, (int) ($config['rsi_length'] ?? ($bot->type === 'rsi_contrtrend' || $bot->type === 'rsi_trade' ? 14 : 20)));
        // Bollinger Contrtrend: OsEngine Length=12 → первое значение на length+1.
        $bbLen = max(2, (int) ($config['bb_length'] ?? 12));
        $wrLen = max(2, (int) ($config['wr_length'] ?? 14));
        $cciLen = max(2, (int) ($config['cci_length'] ?? 20));
        $rviLen = max(2, (int) ($config['rvi_length'] ?? 10));
        $minClosedBars = match ($bot->type) {
            'smc_flow' => 80,
            'strong_level' => max(80, (int) ($config['min_closed_bars'] ?? 200)),
            'strong_trend_telegram' => $trendPatternBars,
            // Для MTF-разворота нужен запас истории M1 и стабильная M5-агрегация.
            'candle_reversal_confirm' => max(80, (int) ($config['min_closed_bars'] ?? 200)),
            // OsEngine: SMA length+1 и RSI length+5.
            'rsi_contrtrend' => max(
                $smaLen + 1,
                $rsiLen + 5,
                (int) ($config['min_closed_bars'] ?? 0)
            ),
            'bollinger_contrtrend' => max(
                $bbLen + 2,
                (int) ($config['min_closed_bars'] ?? 0)
            ),
            'pinbar_trade' => max($smaLen + 2, (int) ($config['min_closed_bars'] ?? 0)),
            'williams_range_trade' => max($wrLen + 2, (int) ($config['min_closed_bars'] ?? 0)),
            'three_soldier' => max(5, (int) ($config['min_closed_bars'] ?? 0)),
            'cci_trade' => max($cciLen + 5, (int) ($config['min_closed_bars'] ?? 0)),
            'rsi_trade' => max($rsiLen + 5, (int) ($config['min_closed_bars'] ?? 0)),
            'rvi_trade' => max($rviLen + 7, (int) ($config['min_closed_bars'] ?? 0)),
            'envelop_trend' => max((int) ($config['length'] ?? 14) + 2, (int) ($config['min_closed_bars'] ?? 0)),
            'price_channel_trade' => max(max((int) ($config['length_up'] ?? 14), (int) ($config['length_down'] ?? 14)) + 3, (int) ($config['min_closed_bars'] ?? 0)),
            'parabolic_sar_trade' => max(10, (int) ($config['min_closed_bars'] ?? 0)),
            'break_linreg_channel' => max((int) ($config['period'] ?? 40) + 2, (int) ($config['min_closed_bars'] ?? 0)),
            'pivot_points' => max(200, (int) ($config['min_closed_bars'] ?? 0)),
            'sma_stochastic' => max((int) ($config['sma_length'] ?? 14) + 10, (int) ($config['min_closed_bars'] ?? 0)),
            'momentum_macd' => max((int) ($config['macd_slow'] ?? 26) + (int) ($config['macd_signal'] ?? 9) + 5, (int) ($config['min_closed_bars'] ?? 0)),
            'parabolic_bollinger' => max((int) ($config['length'] ?? 21) + (int) ($config['averaging_period'] ?? 15) + 5, (int) ($config['min_closed_bars'] ?? 0)),
            'parabolic_price_channel' => max(max((int) ($config['length_up'] ?? 21), (int) ($config['length_down'] ?? 21)) + (int) ($config['averaging_period'] ?? 15) + 5, (int) ($config['min_closed_bars'] ?? 0)),
            // Vola-adaptive: минимум 5 баров логики + запас под дневную историю (fallback без адаптации тоже ок).
            'vol_adaptive_candles' => max(5, (int) ($config['min_closed_bars'] ?? 0)),
            'three_soldier_vol_adaptive' => max(5, (int) ($config['min_closed_bars'] ?? 0)),
            'custom_candles_impulse' => max(
                (int) ($config['candles_count_to_entry'] ?? 2) + 2,
                (int) ($config['min_closed_bars'] ?? 0)
            ),
            // LLM-боту нужен достаточно широкий контекст последних свечей,
            // иначе модель чаще даёт шумные и нестабильные решения.
            'openrouter_llm' => 20,
            // Аналитик: ручной запрос без порога «минимум баров».
            'openrouter_llm_analyst' => 0,
            default => 3,
        };
        // Указываем таймфрейм входного потока для стратегии.
        // Сейчас все поддерживаемые типы работают на M1 (timeframe=1).
        $seriesTimeframe = match ($bot->type) {
            default => 1,
        };
        $dedupeBucketSec = match ($bot->type) {
            'strong_trend_telegram' => max(60, min(3600, (int) ($config['dedupe_bucket_sec'] ?? 300))),
            'smc_flow', 'strong_level', 'openrouter_llm', 'candle_reversal_confirm' => max(60, min(900, $expirySec)),
            default => LevelBounceBotTypes::isOsEngineBinary($bot->type)
                ? max(60, min(900, $expirySec))
                : max(60, min(3600, (int) ($config['dedupe_bucket_sec'] ?? 60))),
        };

        return [
            'config' => $config,
            'count' => $count,
            'expiry_sec' => $expirySec,
            'min_confidence' => $minConfidence,
            'min_payout' => $minPayout,
            'stale_after_sec' => $staleAfterSec,
            'min_closed_bars' => $minClosedBars,
            'dedupe_bucket_sec' => $dedupeBucketSec,
            'series_timeframe' => $seriesTimeframe,
        ];
    }

    /**
     * Собирает MTF-ряд для OpenRouter LLM-бота из настроек бота.
     * Возвращает пустой массив, если MTF отключен в конфиге.
     *
     * @param  array<string,mixed>  $config
     * @return array<string, list<array{time:int,open:float,high:float,low:float,close:float,volume:float}>>
     */
    private function openRouterMtfSeries(string $instrument, array $config, bool $includeM1 = false): array
    {
        // Флаг позволяет быстро отключить MTF без правок кода — удобно для A/B сравнения качества сигналов.
        $mtfEnabled = (bool) ($config['mtf_enabled'] ?? true);
        if (! $mtfEnabled) {
            return [];
        }

        // Один лимит на ТФ: m5_take / m15_take / h1_take / count (M1) = загрузка из БД = отправка в LLM.
        $m1Min = $includeM1 ? 1 : 20;
        $tfMap = \App\Support\OpenRouterLlmConfig::mtfBarMap($config, $includeM1, $m1Min);

        return $this->botDataService->mtfSeries($instrument, $tfMap);
    }

    private function resolveStrategy(string $type): ?BotStrategyInterface
    {
        return match ($type) {
            'three_candles_in_row' => new ThreeCandlesInRowStrategy,
            'candle_reversal_confirm' => new CandleReversalLevelStrategy,
            'rsi_contrtrend' => new RsiContrtrendStrategy,
            'bollinger_contrtrend' => new BollingerContrtrendStrategy,
            'pinbar_trade' => new PinBarTradeStrategy,
            'williams_range_trade' => new WilliamsRangeTradeStrategy,
            'three_soldier' => new ThreeSoldierStrategy,
            'cci_trade' => new CciTradeStrategy,
            'rsi_trade' => new RsiTradeStrategy,
            'rvi_trade' => new RviTradeStrategy,
            'envelop_trend' => new EnvelopTrendStrategy,
            'price_channel_trade' => new PriceChannelTradeStrategy,
            'parabolic_sar_trade' => new ParabolicSarTradeStrategy,
            'break_linreg_channel' => new BreakLinRegChannelStrategy,
            'pivot_points' => new PivotPointsStrategy,
            'sma_stochastic' => new SmaStochasticStrategy,
            'momentum_macd' => new MomentumMacdStrategy,
            'parabolic_bollinger' => new ParabolicBollingerStrategy,
            'parabolic_price_channel' => new ParabolicPriceChannelStrategy,
            'vol_adaptive_candles' => new VolAdaptiveCandlesStrategy,
            'three_soldier_vol_adaptive' => new ThreeSoldierVolAdaptiveStrategy,
            'custom_candles_impulse' => new CustomCandlesImpulseStrategy,
            // Новый бот полностью изолирован от старых эвристик:
            // торговое решение принимает только LLM через OpenRouter.
            'openrouter_llm' => new OpenRouterLlmStrategy($this->openRouterClient),
            'openrouter_llm_analyst' => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
            'smc_flow' => new SmcFlowStrategy($this->smcFlowBotSignalAnalyzer),
            'strong_level' => new StrongLevelStrategy,
            'strong_trend_telegram' => new StrongTrendTelegramStrategy,
            default => null,
        };
    }

    private function lastLlmLogErrorMessage(int $botId, ?int $llmLogId): ?string
    {
        if (! Schema::hasTable('bot_llm_logs')) {
            return null;
        }

        $q = BotLlmLog::query()->where('bot_id', $botId)->orderByDesc('id');
        if ($llmLogId !== null && $llmLogId > 0) {
            $q->whereKey($llmLogId);
        }

        $log = $q->first(['error_message', 'status']);
        if ($log === null) {
            return null;
        }

        $msg = trim((string) ($log->error_message ?? ''));
        if ($msg !== '') {
            return $msg;
        }

        return $log->status === 'error' ? 'unknown_llm_error' : null;
    }

    private function manualAnalysisFailureSummary(?string $errorDetail, string $model): string
    {
        if ($errorDetail === null || $errorDetail === '') {
            return 'Не удалось получить ответ от модели. Проверьте OPENROUTER_API_KEY и раздел «Логи».';
        }

        if (str_contains($errorDetail, 'OPENROUTER_API_KEY')) {
            return $errorDetail;
        }

        if (str_contains($errorDetail, 'max_tokens_exhausted')) {
            return 'Лимит max tokens исчерпан (модель не успела выдать итог). Увеличьте Max tokens в настройках (например 2000–4000) или выберите модель без reasoning.';
        }

        if (str_contains($errorDetail, 'cURL error 28') || str_contains($errorDetail, 'timed out')) {
            return 'Таймаут ожидания OpenRouter. Увеличьте «Таймаут ожидания ответа OpenRouter» в настройках бота (например 180–300 с) или уменьшите объём свечей.';
        }

        if (str_starts_with($errorDetail, 'HTTP request returned status code')) {
            return 'Ошибка OpenRouter: '.$errorDetail;
        }

        return 'Ошибка OpenRouter ('.$model.'): '.$errorDetail;
    }

    /**
     * Фиксирует итог анализа инструмента (успех/пропуск/ошибка) для метрик воронки.
     *
     * @param  array<string,mixed>|null  $decisionJson
     */
    private function finishAnalysisRun(?BotAnalysisRun $run, string $status, ?string $skipReason, ?array $decisionJson = null): void
    {
        if (! $run) {
            return;
        }
        $startedMs = $run->started_at ? $run->started_at->getTimestampMs() : (int) round(microtime(true) * 1000);
        $run->status = $status;
        $run->skip_reason = $skipReason;
        $run->finished_at = Carbon::now();
        $run->latency_ms = max(0, (int) round(microtime(true) * 1000 - $startedMs));
        if ($decisionJson !== null) {
            $run->decision_json = $decisionJson;
        }
        $run->save();
    }
}
