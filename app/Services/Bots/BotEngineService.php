<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotAnalysisRun;
use App\Models\BotLlmLog;
use App\Models\BotSignal;
use App\Models\IndicatorSetting;
use App\Services\Mirror\MirrorTradeDispatcher;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Support\BotScanProgress;
use App\Support\SmcFlowLlmSnapshot;
use App\Services\Bots\Strategies\AkimTrade1MStrategy;
use App\Services\Bots\Strategies\BotStrategyInterface;
use App\Services\Bots\Strategies\CandleReversalMtfConfirmStrategy;
use App\Services\Bots\Strategies\FeshanStrategy;
use App\Services\Bots\Strategies\OpenRouterLlmAnalystStrategy;
use App\Services\Bots\Strategies\OpenRouterLlmStrategy;
use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BotEngineService
{
    public function __construct(
        private readonly BotDataService $botDataService,
        private readonly OpenRouterClient $openRouterClient,
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
            // Feshan: подмешиваем агрегаты из M1 (CandleService), ключи — минуты ТФ для FeshanStrategy.
            $mtf = [];
            if ($bot->type === 'feshan') {
                $mtf = $this->botDataService->mtfSeries($instrument, [
                    60 => 56,
                    30 => 72,
                    15 => 96,
                    5 => 160,
                ]);
            } elseif ($bot->type === 'candle_reversal_confirm') {
                // Для MTF-разворота обязательно подмешиваем M5: направление определяется сначала на M5,
                // а вход подтверждается блоком из 3 закрытых M1 свечей в ту же сторону.
                $mtf = $this->botDataService->mtfSeries($instrument, [
                    5 => 60,
                ]);
            } elseif ($bot->type === 'openrouter_llm' || $bot->type === 'openrouter_llm_analyst') {
                // OpenRouter: MTF-контекст из настроек (для analyst — M1/M5/M15/H1).
                $mtf = $this->openRouterMtfSeries($instrument, $config, $bot->type === 'openrouter_llm_analyst');
            }
            $decision = $strategy->analyze($closed, $partial, [
                'bot_id' => $bot->id,
                'analysis_run_id' => $analysisRun?->id,
                'instrument' => $instrument,
                'payout' => $currentPayout,
                'config' => $config,
                'mtf' => $mtf,
            ]);
            if (! $decision) {
                $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'strategy_no_signal', [
                    'market' => [
                        'payout' => $currentPayout,
                        'closed_bars' => count($closed),
                    ],
                ]);
                continue;
            }

            // Базово используем expiry из runtime-настроек.
            // Для Feshan разрешаем стратегии вернуть адаптивную экспирацию и
            // безопасно ограничиваем её диапазоном 2..5 минут.
            $decisionExpirySec = $expirySec;
            if ($bot->type === 'feshan' && isset($decision['expiry_sec'])) {
                $decisionExpirySec = max(120, min(300, (int) $decision['expiry_sec']));
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
            $signalDedupeBucketSec = $bot->type === 'feshan'
                ? max(60, min(900, $decisionExpirySec))
                : $dedupeBucketSec;
            $bucket = intdiv(time(), $signalDedupeBucketSec) * $signalDedupeBucketSec;
            $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $side);

            if (BotSignal::query()->where('dedupe_key', $dedupeKey)->exists()) {
                $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'dedupe', [
                    'dedupe_key' => $dedupeKey,
                    'decision' => $decision,
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
                $analysisRun,
                &$mirrorTradeIds
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
            $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_OK, null, [
                'decision' => $decision,
                'entry_price' => $entryPrice,
                'entry_time' => $entryAt->toIso8601String(),
                'expiry_time' => $expiryAt->toIso8601String(),
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
     * Ручной анализ графика для бота openrouter_llm_analyst: без фильтров payout/confidence/stale,
     * свечи отправляются в LLM в любом объёме, который удалось загрузить.
     *
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
    public function runManualChartAnalysis(Bot $bot, string $instrument, string $userQuestion = ''): array
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
        $smcFlowPack = $this->buildAnalystSmcFlowPack($bot, $instrument, $count);

        $decision = $strategy->analyze($closed, $partial, [
            'bot_id' => $bot->id,
            'instrument' => $instrument,
            'payout' => $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null,
            'config' => $config,
            'mtf' => $mtf,
            's5_closed' => $s5Series['closed'],
            's5_partial' => $s5Series['partial'],
            'smc_flow_summary' => $smcFlowPack['snapshot'],
            'user_question' => mb_substr(trim($userQuestion), 0, 2000),
        ]);

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
            'smc_flow' => $smcFlowPack['overlay'],
            'smc_flow_modules' => $smcFlowPack['overlay']['modules'] ?? [],
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
        if ($bot->type === 'feshan') {
            // Передаём те же MTF-агрегаты, что и в реальном scan, чтобы объяснение совпадало с боевой логикой.
            $mtf = $this->botDataService->mtfSeries($instrument, [
                60 => 56,
                30 => 72,
                15 => 96,
                5 => 160,
            ]);
        } elseif ($bot->type === 'candle_reversal_confirm') {
            // В explain используем тот же M5-контекст, что и в scan, иначе диагностика не совпадет с боевым входом.
            $mtf = $this->botDataService->mtfSeries($instrument, [
                5 => 60,
            ]);
        } elseif ($bot->type === 'openrouter_llm' || $bot->type === 'openrouter_llm_analyst') {
            $mtf = $this->openRouterMtfSeries($instrument, $runtime['config'], $bot->type === 'openrouter_llm_analyst');
        }

        $decision = $strategy->analyze($closed, $series['partial'], [
            'instrument' => $instrument,
            'payout' => $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null,
            'config' => $runtime['config'],
            'mtf' => $mtf,
        ]);
        if (! $decision) {
            return [
                'instrument' => $instrument,
                'can_enter' => false,
                'summary' => 'Стратегия не видит валидного сетапа на текущем баре.',
                'analysis_text' => $bot->type === 'feshan'
                    ? 'Feshan: активный режим liquidity sweep. На текущем баре не найдено валидного снятия ликвидности с возвратом в диапазон.'
                    : '',
                'reasons_short' => [],
                'blockers' => ['Нет триггера входа по условиям стратегии.'],
                'features' => [],
            ];
        }

        $side = (string) ($decision['side'] ?? '');
        $confidence = (float) ($decision['confidence'] ?? 0);
        $bucket = intdiv(time(), $runtime['dedupe_bucket_sec']) * $runtime['dedupe_bucket_sec'];
        $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $side);
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
            'feshan' => 2500,
            'candle_reversal_confirm' => 180,
            'openrouter_llm_analyst' => 120,
            default => 120,
        };
        $count = match ($bot->type) {
            // LLM-боты: count = сколько M1 загрузить и отправить в модель (см. OpenRouterLlmConfig).
            'openrouter_llm' => \App\Support\OpenRouterLlmConfig::m1BarCount($config, 120, 20),
            'openrouter_llm_analyst' => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            default => max(10, min(5000, (int) ($config['count'] ?? $defaultCount))),
        };
        $expirySec = match ($bot->type) {
            'three_candles_in_row' => (int) ($config['expiry_sec'] ?? 60),
            'akim_trade_1m' => (int) ($config['expiry_sec'] ?? 60),
            // По ТЗ для MTF-разворота вход идёт на 5 минут независимо от рыночной фазы.
            'candle_reversal_confirm' => (int) ($config['expiry_sec'] ?? 300),
            'feshan' => (int) ($config['expiry_sec'] ?? 300),
            default => (int) ($config['expiry_sec'] ?? 300),
        };
        // Разные типы ботов имеют разные дефолтные пороги confidence:
        // - feshan: умеренно строгий вход;
        // - openrouter_llm: чуть выше порог по умолчанию, чтобы отсеять "неуверенные" ответы модели;
        // - остальные: базовый порог.
        $minConfidence = (float) ($config['min_confidence'] ?? match ($bot->type) {
            'feshan' => 0.6,
            'openrouter_llm' => 0.65,
            'akim_trade_1m' => 0.58,
            'candle_reversal_confirm' => 0.56,
            default => 0.55,
        });
        // Минимальный payout как экономический фильтр качества входа.
        // Для LLM-бота ставим умеренный дефолтный порог, чтобы не брать пары с совсем слабой выплатой.
        $minPayout = (float) ($config['min_payout'] ?? match ($bot->type) {
            'feshan' => 80.0,
            'openrouter_llm' => 70.0,
            'candle_reversal_confirm' => 80.0,
            'three_candles_in_row' => 80.0,
            'akim_trade_1m' => 80.0,
            default => 80.0,
        });
        $staleAfterSec = (int) ($config['stale_after_sec'] ?? 30);
        $minClosedBars = match ($bot->type) {
            'feshan' => 40,
            'akim_trade_1m' => 90,
            // Для MTF-разворота нужен запас истории M1 и стабильная M5-агрегация.
            'candle_reversal_confirm' => 60,
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
            'feshan', 'openrouter_llm', 'candle_reversal_confirm' => max(60, min(900, $expirySec)),
            default => 60,
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

    /**
     * Подробная диагностика Feshan без открытия сделки.
     * Возвращает, какие условия CALL/PUT выполнены и какие именно блокируют вход сейчас.
     *
     * @param  list<array<string,mixed>>  $closed
     * @param  array<string, list<array<string,mixed>>>  $mtf
     * @return array{passed:list<string>,unmet:list<string>,features:array<string,mixed>}
     */
    private function feshanTriggerDiagnostics(array $closed, array $mtf, array $config): array
    {
        if ($closed === []) {
            return [
                'passed' => [],
                'unmet' => ['Нет закрытых M1 свечей для диагностики Feshan.'],
                'features' => [],
            ];
        }

        $lastM1 = $closed[array_key_last($closed)];
        $bars60 = $mtf['60'] ?? [];
        $bars5 = $mtf['5'] ?? [];

        $bias60 = $this->directionBiasByClose($bars60, 6);
        $h1Last = $bars60 !== [] ? $bars60[array_key_last($bars60)] : null;
        $h1Bull = is_array($h1Last) ? $this->isBullCandle($h1Last) : false;
        $h1Bear = is_array($h1Last) ? $this->isBearCandle($h1Last) : false;
        $m5AlignUp = $this->alignedCountByDirection($bars5, 3, true);
        $m5AlignDown = $this->alignedCountByDirection($bars5, 3, false);
        $m1Bull = $this->isBullCandle($lastM1);
        $m1Bear = $this->isBearCandle($lastM1);
        $prevM1 = count($closed) >= 2 ? $closed[array_key_last($closed) - 1] : $lastM1;
        $absorbBull = $m1Bull && $this->lowerWickRatioByCandle($lastM1) >= 0.45;
        $absorbBear = $m1Bear && $this->upperWickRatioByCandle($lastM1) >= 0.45;
        $imb5Bull = $this->lastBullishImbalanceByBars($bars5);
        $imb5Bear = $this->lastBearishImbalanceByBars($bars5);
        $m1BodyRatio = $this->bodyRatioByCandle($lastM1);
        $m1IsDoji = $m1BodyRatio < 0.2;
        $m1MomentumBull = $m1Bull && $this->isBullCandle($prevM1);
        $m1MomentumBear = $m1Bear && $this->isBearCandle($prevM1);

        // Учитываем включатели из настроек бота, чтобы диагностика строго совпадала с рабочим входом.
        $useBias60 = (bool) ($config['trigger_bias_60'] ?? true);
        $useH1Candle = (bool) ($config['trigger_h1_candle'] ?? true);
        $useM5Align = (bool) ($config['trigger_m5_align'] ?? true);
        $useM1Candle = (bool) ($config['trigger_m1_candle'] ?? true);
        $useAbsorb = (bool) ($config['trigger_absorption'] ?? false);
        $useImbalance = (bool) ($config['trigger_imbalance'] ?? false);
        $strictMode = (bool) ($config['strict_mode'] ?? true);

        // Явно считаем оба триггера, чтобы пользователь видел, какой сценарий почти собран.
        $callChecks = [
            '1H bias = up' => ! $useBias60 || $bias60 === 'up',
            'последняя 1H свеча bullish' => ! $useH1Candle || $h1Bull,
            '5M aligned up >= '.($strictMode ? '3 из 3' : '2 из 3') => ! $useM5Align || $m5AlignUp >= ($strictMode ? 3 : 2),
            'последняя M1 bullish' => ! $useM1Candle || $m1Bull,
            'M1 absorption bullish' => ! $useAbsorb || $absorbBull,
            '5M imbalance bullish' => ! $useImbalance || $imb5Bull,
            'Strict M1 quality bullish' => ! $strictMode || (! $m1IsDoji && $m1BodyRatio >= 0.55 && $m1MomentumBull),
        ];
        $putChecks = [
            '1H bias = down' => ! $useBias60 || $bias60 === 'down',
            'последняя 1H свеча bearish' => ! $useH1Candle || $h1Bear,
            '5M aligned down >= '.($strictMode ? '3 из 3' : '2 из 3') => ! $useM5Align || $m5AlignDown >= ($strictMode ? 3 : 2),
            'последняя M1 bearish' => ! $useM1Candle || $m1Bear,
            'M1 absorption bearish' => ! $useAbsorb || $absorbBear,
            '5M imbalance bearish' => ! $useImbalance || $imb5Bear,
            'Strict M1 quality bearish' => ! $strictMode || (! $m1IsDoji && $m1BodyRatio >= 0.55 && $m1MomentumBear),
        ];
        $callCoreOk = ! in_array(false, $callChecks, true);
        $putCoreOk = ! in_array(false, $putChecks, true);

        $passed = [];
        foreach ($callChecks as $name => $ok) {
            if ($ok) {
                $passed[] = "CALL: {$name}";
            }
        }
        foreach ($putChecks as $name => $ok) {
            if ($ok) {
                $passed[] = "PUT: {$name}";
            }
        }
        if ($absorbBull) {
            $passed[] = 'CALL: M1 поглощение продаж (нижняя тень >= 45%)';
        }
        if ($absorbBear) {
            $passed[] = 'PUT: M1 поглощение покупок (верхняя тень >= 45%)';
        }
        if ($imb5Bull) {
            $passed[] = 'CALL: 5M бычий имбаланс присутствует';
        }
        if ($imb5Bear) {
            $passed[] = 'PUT: 5M медвежий имбаланс присутствует';
        }

        $unmet = [];
        foreach ($callChecks as $name => $ok) {
            if (! $ok) {
                $unmet[] = "CALL не выполнен: {$name}";
            }
        }
        foreach ($putChecks as $name => $ok) {
            if (! $ok) {
                $unmet[] = "PUT не выполнен: {$name}";
            }
        }
        if (! $callCoreOk && ! $putCoreOk && ! $absorbBull && ! $absorbBear && ! $imb5Bull && ! $imb5Bear) {
            $unmet[] = 'Нет подтверждающих доп.триггеров (поглощение/имбаланс) на текущем баре.';
        }

        return [
            'passed' => $passed,
            'unmet' => $unmet,
            'features' => [
                'bias_60' => $bias60,
                'h1_bull' => $h1Bull,
                'h1_bear' => $h1Bear,
                'm5_align_up' => $m5AlignUp,
                'm5_align_down' => $m5AlignDown,
                'm1_bull' => $m1Bull,
                'm1_bear' => $m1Bear,
                'absorb_bull' => $absorbBull,
                'absorb_bear' => $absorbBear,
                'imb5_bull' => $imb5Bull,
                'imb5_bear' => $imb5Bear,
                'm1_body_ratio' => $m1BodyRatio,
                'm1_is_doji' => $m1IsDoji,
                'm1_momentum_bull' => $m1MomentumBull,
                'm1_momentum_bear' => $m1MomentumBear,
                'trigger_bias_60' => $useBias60,
                'trigger_h1_candle' => $useH1Candle,
                'trigger_m5_align' => $useM5Align,
                'trigger_m1_candle' => $useM1Candle,
                'trigger_absorption' => $useAbsorb,
                'trigger_imbalance' => $useImbalance,
                'strict_mode' => $strictMode,
                'call_core_ok' => $callCoreOk,
                'put_core_ok' => $putCoreOk,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function directionBiasByClose(array $bars, int $n): ?string
    {
        if (count($bars) < $n) {
            return null;
        }
        $slice = array_slice($bars, -$n);
        $first = (float) $slice[0]['close'];
        $last = (float) $slice[array_key_last($slice)]['close'];
        $eps = max($first * 0.00015, 1e-7);

        if ($last > $first + $eps) {
            return 'up';
        }
        if ($last < $first - $eps) {
            return 'down';
        }

        return 'flat';
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function alignedCountByDirection(array $bars, int $take, bool $bull): int
    {
        if (count($bars) < $take) {
            return 0;
        }
        $slice = array_slice($bars, -$take);
        $n = 0;
        foreach ($slice as $c) {
            if ($bull && $this->isBullCandle($c)) {
                $n++;
            }
            if (! $bull && $this->isBearCandle($c)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function isBullCandle(array $c): bool
    {
        return (float) $c['close'] > (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function isBearCandle(array $c): bool
    {
        return (float) $c['close'] < (float) $c['open'];
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function lowerWickRatioByCandle(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyTop = max((float) $c['open'], (float) $c['close']);
        $lower = $bodyTop - (float) $c['low'];

        return min(1.0, $lower / $range);
    }

    /**
     * @param  array<string,mixed>  $c
     */
    private function upperWickRatioByCandle(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $bodyBot = min((float) $c['open'], (float) $c['close']);
        $upper = (float) $c['high'] - $bodyBot;

        return min(1.0, $upper / $range);
    }

    /**
     * Возвращает долю тела свечи в полном диапазоне (0..1).
     * Используем для строгого фильтра импульса, чтобы отсеять "рваные" doji-бары.
     *
     * @param  array<string,mixed>  $c
     */
    private function bodyRatioByCandle(array $c): float
    {
        $range = max(1e-8, (float) $c['high'] - (float) $c['low']);
        $body = abs((float) $c['close'] - (float) $c['open']);

        return min(1.0, $body / $range);
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function lastBullishImbalanceByBars(array $bars): bool
    {
        $n = count($bars);
        if ($n < 3) {
            return false;
        }
        $a = $bars[$n - 3];
        $c = $bars[$n - 1];

        return (float) $c['low'] > (float) $a['high'];
    }

    /**
     * @param  list<array<string,mixed>>  $bars
     */
    private function lastBearishImbalanceByBars(array $bars): bool
    {
        $n = count($bars);
        if ($n < 3) {
            return false;
        }
        $a = $bars[$n - 3];
        $c = $bars[$n - 1];

        return (float) $c['high'] < (float) $a['low'];
    }

    private function resolveStrategy(string $type): ?BotStrategyInterface
    {
        return match ($type) {
            'three_candles_in_row' => new ThreeCandlesInRowStrategy,
            'akim_trade_1m' => new AkimTrade1MStrategy,
            'candle_reversal_confirm' => new CandleReversalMtfConfirmStrategy,
            'feshan' => new FeshanStrategy,
            // Новый бот полностью изолирован от старых эвристик:
            // торговое решение принимает только LLM через OpenRouter.
            'openrouter_llm' => new OpenRouterLlmStrategy($this->openRouterClient),
            'openrouter_llm_analyst' => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
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
     * @param array<string,mixed>|null $decisionJson
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
