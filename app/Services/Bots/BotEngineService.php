<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotAnalysisRun;
use App\Models\BotLlmLog;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\BotVirtualTrade;
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
use App\Services\Bots\Strategies\DemarkerDualFastStrategy;
use App\Services\Bots\Strategies\StrongTrendDemarkerStrategy;
use App\Services\Bots\Strategies\StrongTrendTelegramStrategy;
use App\Services\Bots\Strategies\AuthorFlowStrategy;
use App\Services\Bots\Strategies\EngulfingLiquidityStrategy;
use App\Services\Bots\Strategies\SweepRaidLiquidityStrategy;
use App\Support\AuthorFlowConfig;
use App\Support\DemarkerDualFastConfig;
use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use App\Services\Bots\Strategies\EnvelopTrendStrategy;
use App\Services\Bots\Strategies\PriceChannelTradeStrategy;
use App\Services\Bots\Strategies\ParabolicSarTradeStrategy;
use App\Services\Bots\Strategies\BreakLinRegChannelStrategy;
use App\Services\Bots\Strategies\PivotPointsStrategy;
use App\Services\Bots\Strategies\SmaStochasticStrategy;
use App\Services\Bots\Strategies\MomentumMacdStrategy;
use App\Services\Bots\Strategies\MaxBotStrategy;
use App\Services\Bots\Strategies\ImpulseLvl1minStrategy;
use App\Services\Bots\Strategies\ParabolicBollingerStrategy;
use App\Services\Bots\Strategies\ParabolicPriceChannelStrategy;
use App\Services\Bots\Strategies\VolAdaptiveCandlesStrategy;
use App\Services\Bots\Strategies\ThreeSoldierVolAdaptiveStrategy;
use App\Services\Bots\Strategies\CustomCandlesImpulseStrategy;
use App\Services\CandleService;
use App\Services\Indicators\SmcFlowBotSignalAnalyzer;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Services\Mirror\MirrorTradeDispatcher;
use App\Services\Telegram\TelegramAlertService;
use App\Support\BotInstrumentMarketScope;
use App\Support\BotScanCoordinator;
use App\Support\BotScanProgress;
use App\Support\BinaryExpiry;
use App\Support\ChartAnalystBotTypes;
use App\Support\LevelBounceBotTypes;
use App\Support\MaxBotConfig;
use App\Support\ImpulseLvl1minConfig;
use App\Support\ImpulseLvlLlmSnapshot;
use App\Support\AuthorFlowLlmSnapshot;
use App\Support\EngulfingLlmSnapshot;
use App\Support\SmcFlowLlmSnapshot;
use App\Support\SweepRaidLlmSnapshot;
use App\Services\Indicators\EngulfingLiquidityIndicatorService;
use App\Services\Indicators\SweepRaidLiquidityIndicatorService;
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
        private readonly BotVirtualTradeSettlementService $virtualSettlement,
        private readonly BotInstrumentLossStateService $lossState,
    ) {}

    public function scanBot(Bot $bot, array $scanOptions = []): array
    {
        // Бот-аналитик: только ручной запрос с графика, плановый scan не выполняем никогда.
        if (ChartAnalystBotTypes::isAnalyst($bot->type)) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        if (! $bot->is_active) {
            return ['processed' => 0, 'signals' => 0, 'trades' => 0];
        }

        // До нового scan закрываем законченные виртуальные сделки. Иначе следующий
        // сигнал той же пары увидел бы устаревший счётчик «неотработок».
        if ($bot->type === BotInstrumentLossStateService::BOT_TYPE) {
            $this->virtualSettlement->settleDueVirtualTrades($bot->id);
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
        // all | otc | non_otc — для Impulse / Engulfing (и любых ботов с ключом в config).
        $marketScope = BotInstrumentMarketScope::fromConfig($config);

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
        $cacheHits = 0;
        $dbLoads = 0;
        $promotedBars = 0;
        $scanWallStarted = hrtime(true);
        // Логи analysis-runs включаются только при одновременном выполнении двух условий:
        // 1) таблица bot_analysis_runs существует (миграции применены),
        // 2) у конкретного бота флаг analysis_runs_logging_enabled не выключен в config.
        // Такой подход даёт персональную настройку шумности/нагрузки по каждому боту.
        $analysisLogsEnabled = empty($scanOptions['disable_analysis_runs'])
            && Schema::hasTable('bot_analysis_runs')
            && (bool) ($config['analysis_runs_logging_enabled'] ?? true);
        $eligible = [];
        // Impulse live: готовые строки (instrument + payout) без повторного SELECT.
        if (isset($scanOptions['instrument_rows']) && is_array($scanOptions['instrument_rows']) && $scanOptions['instrument_rows'] !== []) {
            foreach ($scanOptions['instrument_rows'] as $row) {
                if (is_object($row) && isset($row->instrument)) {
                    $eligible[] = $row;
                } elseif (is_array($row) && isset($row['instrument'])) {
                    $eligible[] = (object) $row;
                }
            }
        } else {
            foreach ($instruments as $instrumentRow) {
                $instrumentKey = (string) $instrumentRow->instrument;
                if ($allowedInstrumentSet !== [] && ! isset($allowedInstrumentSet[$instrumentKey])) {
                    continue;
                }
                if (isset($blockedInstrumentSet[$instrumentKey])) {
                    continue;
                }
                if (! BotInstrumentMarketScope::allows($instrumentKey, $marketScope)) {
                    continue;
                }
                $rowPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
                if ($rowPayout === null || $rowPayout < $minPayout) {
                    continue;
                }
                $eligible[] = $instrumentRow;
            }
        }
        // Точечный проход: только перечисленные пары (live worker).
        if (isset($scanOptions['only_instruments']) && is_array($scanOptions['only_instruments']) && $scanOptions['only_instruments'] !== []) {
            $want = [];
            foreach ($scanOptions['only_instruments'] as $rawInst) {
                $k = trim((string) $rawInst);
                if ($k !== '') {
                    $want[$k] = true;
                }
            }
            if ($want !== []) {
                $eligible = array_values(array_filter(
                    $eligible,
                    static fn ($row): bool => isset($want[(string) $row->instrument])
                ));
            }
        }
        $shardIndex = isset($scanOptions['shard_index']) ? (int) $scanOptions['shard_index'] : 0;
        $shardCount = isset($scanOptions['shard_count']) ? (int) $scanOptions['shard_count'] : 1;
        if ($shardCount > 1) {
            $eligible = BotScanCoordinator::takeShard($eligible, $shardIndex, $shardCount);
        }
        $scanInstrumentTotal = count($eligible);
        $skipProgressLifecycle = ! empty($scanOptions['skip_progress_begin_end']);
        // Шард пишет в свой ключ: 6 потоков не затирают друг друга в BotScanProgress.
        $scanRunId = $skipProgressLifecycle
            ? null
            : BotScanProgress::beginScan($bot->id, $scanInstrumentTotal, $shardIndex, $shardCount);
        $seriesResolver = isset($scanOptions['series_resolver']) && is_callable($scanOptions['series_resolver'])
            ? $scanOptions['series_resolver']
            : null;
        try {
            foreach ($eligible as $instrumentRow) {
                $instrument = (string) $instrumentRow->instrument;
                $currentPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
                $processed++;
                // Пишем в cache текущую пару — sidebar бота показывает красный индикатор «идёт анализ».
                if (! $skipProgressLifecycle) {
                    BotScanProgress::setCurrentInstrument($bot->id, $instrument, $shardIndex, $shardCount);
                }
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
                    $mtf = [];
                    // Live-cache / тесты: готовый closed+partial+mtf без похода в MySQL.
                    if ($seriesResolver !== null) {
                        $packed = $seriesResolver($instrument, $count, $staleAfterSec, $seriesTimeframe, $config);
                        $series = is_array($packed) ? $packed : ['closed' => [], 'partial' => null, 'series' => [], 'mtf' => []];
                        $mtf = is_array($series['mtf'] ?? null) ? $series['mtf'] : [];
                        if (! empty($series['cache_hit'])) {
                            $cacheHits++;
                        } else {
                            $dbLoads++;
                        }
                        if (! empty($series['promoted'])) {
                            $promotedBars++;
                        }
                    // Strong Level / CRC: M1+M5+M15+H1 из одной выборки S5, а не 4 похода в candles.
                    } elseif ($bot->type === 'candle_reversal_confirm' || $bot->type === 'strong_level') {
                        $packed = $this->botDataService->seriesWithMtf(
                            $instrument,
                            $count,
                            [
                                5 => (int) ($config['m5_take'] ?? 120),
                                15 => (int) ($config['m15_take'] ?? 80),
                                60 => (int) ($config['h1_take'] ?? 12),
                            ],
                            true,
                            $staleAfterSec,
                            $seriesTimeframe,
                        );
                        $series = $packed;
                        $mtf = $packed['mtf'];
                    } elseif (ImpulseLvl1minConfig::is((string) $bot->type)) {
                        // M1 + MTF из Redis-кэша (hit) или seriesBundle (miss); partial — live Redis.
                        $packed = $this->botDataService->impulseSeriesWithMtf(
                            $instrument,
                            $count,
                            ImpulseLvl1minConfig::mtfTakeMap($config),
                            true,
                            $staleAfterSec,
                            $seriesTimeframe,
                            ImpulseLvl1minConfig::flag($config, 'keep_stale_forming_m1', true),
                        );
                        $series = $packed;
                        $mtf = $packed['mtf'];
                        if (! empty($packed['cache_hit'])) {
                            $cacheHits++;
                        } else {
                            $dbLoads++;
                        }
                        if (! empty($packed['promoted'])) {
                            $promotedBars++;
                        }
                    } else {
                        $series = $this->botDataService->series($instrument, $count, true, $staleAfterSec, $seriesTimeframe);
                    }
                    $closed = $series['closed'];
                    if (count($closed) < $minClosedBars) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'not_enough_bars', [
                            'closed_bars' => count($closed),
                            'min_closed_bars' => $minClosedBars,
                        ]);

                        continue;
                    }
                    $partial = $series['partial'];
                    if ($bot->type === 'openrouter_llm' || ChartAnalystBotTypes::isAnalyst($bot->type)) {
                        // OpenRouter: MTF-контекст из настроек (для analyst — M1/M5/M15/H1).
                        $mtf = $this->openRouterMtfSeries($instrument, $config, ChartAnalystBotTypes::isAnalyst($bot->type));
                    }
                    $analyzeContext = [
                        'bot_id' => $bot->id,
                        'analysis_run_id' => $analysisRun?->id,
                        'instrument' => $instrument,
                        'payout' => $currentPayout,
                        'config' => $config,
                        'mtf' => $mtf,
                        'bot_type' => (string) $bot->type,
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
                    } elseif (in_array($bot->type, ['sweep_raid_liquidity', 'engulfing_liquidity', AuthorFlowConfig::BOT_TYPE], true) && isset($decision['expiry_sec'])) {
                        // SRL/Engulf: 15–900с (авторский OTC часто 15с); не поднимаем обратно до 60.
                        $decisionExpirySec = \App\Support\BinaryExpiry::clamp((int) $decision['expiry_sec']);
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

                    if (ImpulseLvl1minConfig::is((string) $bot->type)) {
                        $impulseHits = $this->impulseLvlDecisionHits($decision);
                        $blocksPaper = ImpulseLvl1minConfig::flag($config, 'occupancy_blocks_paper', true);
                        $blocksAlerts = ImpulseLvl1minConfig::flag($config, 'occupancy_blocks_alerts', false);
                        $openQuery = BotTrade::query()
                            ->where('bot_id', $bot->id)
                            ->where('status', BotTrade::STATUS_OPEN);
                        if (ImpulseLvl1minConfig::occupancyScope($config) !== 'bot') {
                            $openQuery->where('instrument', $instrument);
                        }
                        $hasOpenImpulseTrade = $openQuery->exists();
                        if ($blocksAlerts && $hasOpenImpulseTrade) {
                            $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'occupancy', [
                                'decision' => $decision,
                            ]);
                            continue;
                        }
                        $impulseOpen = $blocksPaper && $hasOpenImpulseTrade;
                        $preferLive = ImpulseLvl1minConfig::flag($config, 'paper_prefer_live', true);
                        $hasLiveHit = false;
                        foreach ($impulseHits as $impulseHit) {
                            $hitFeat = is_array($impulseHit['features'] ?? null) ? $impulseHit['features'] : [];
                            if (empty($hitFeat['is_prev'])) {
                                $hasLiveHit = true;
                                break;
                            }
                        }
                        $paperDone = false;
                        $emitted = 0;
                        $lastTelegram = null;
                        $lastSkip = null;
                        $lastDecision = $decision;
                        $entryPrice = (float) (($partial['close'] ?? null) ?? end($closed)['close']);
                        $payoutSnapshot = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
                        foreach ($impulseHits as $impulseHit) {
                            $hitConf = (float) ($impulseHit['confidence'] ?? 0);
                            if ($hitConf < $minConfidence) {
                                $lastSkip = 'confidence_below_threshold';
                                continue;
                            }
                            $hitSide = (string) ($impulseHit['side'] ?? '');
                            if (! in_array($hitSide, ['CALL', 'PUT'], true)) {
                                $lastSkip = 'invalid_side';
                                continue;
                            }
                            $hitFeat = is_array($impulseHit['features'] ?? null) ? $impulseHit['features'] : [];
                            $isPrev = (bool) ($hitFeat['is_prev'] ?? false);
                            $wantPaper = ! $impulseOpen && ! $paperDone && (! $preferLive || ! $hasLiveHit || ! $isPrev);
                            $commit = $this->commitImpulseLvlHit(
                                $bot,
                                $instrument,
                                $impulseHit,
                                $payoutSnapshot,
                                $decisionExpirySec,
                                $entryPrice,
                                $closed,
                                $dedupeBucketSec,
                                $wantPaper,
                                is_array($partial) ? $partial : null,
                            );
                            if (! ($commit['ok'] ?? false)) {
                                $lastSkip = (string) ($commit['skip'] ?? 'dedupe');
                                continue;
                            }
                            $emitted++;
                            $signals++;
                            if ((int) ($commit['trade_id'] ?? 0) > 0) {
                                $trades++;
                            }
                            $lastDecision = $impulseHit;
                            $lastTelegram = $commit['telegram'] ?? null;
                            if ($wantPaper && (int) ($commit['trade_id'] ?? 0) > 0) {
                                $paperDone = true;
                                $impulseOpen = true;
                            }
                        }
                        if ($emitted === 0) {
                            $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, $lastSkip ?? 'dedupe', [
                                'decision' => $lastDecision,
                            ]);
                            continue;
                        }
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_OK, null, [
                            'decision' => $lastDecision,
                            'impulse_alerts' => $emitted,
                            'impulse_paper' => $paperDone,
                            'telegram' => $lastTelegram,
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
                        MaxBotConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => sprintf(
                            '%s:%d',
                            (string) (($decision['features']['strategy_id'] ?? '') ?: 'max'),
                            (int) ($decision['features']['signal_timestamp'] ?? 0)
                        ),
                        default => $side,
                    };
                    $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $dedupeSuffix);

                    $isVirtualDuplicate = $bot->type === BotInstrumentLossStateService::BOT_TYPE
                        && BotVirtualTrade::query()->where('dedupe_key', $dedupeKey)->exists();
                    if (BotSignal::query()->where('dedupe_key', $dedupeKey)->exists() || $isVirtualDuplicate) {
                        $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'dedupe', [
                            'dedupe_key' => $dedupeKey,
                            'decision' => $decision,
                        ]);

                        continue;
                    }

                    if ($bot->type === BotInstrumentLossStateService::BOT_TYPE) {
                        // 0 = виртуальная серия выкл.: сразу paper-сделка. Иначе ждём N LOSS подряд по паре.
                        $lossesBeforeEntry = max(0, min(20, (int) ($config['losses_before_entry'] ?? 2)));
                        if ($lossesBeforeEntry > 0) {
                            $hasOpenVirtualTrade = BotVirtualTrade::query()
                                ->where('bot_id', $bot->id)
                                ->where('instrument', $instrument)
                                ->where('status', BotVirtualTrade::STATUS_OPEN)
                                ->exists();
                            if ($hasOpenVirtualTrade) {
                                // Не открываем вторую виртуальную сделку, пока не известен
                                // итог первой: серия должна состоять из последовательных результатов.
                                $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'virtual_trade_pending', [
                                    'decision' => $decision,
                                ]);

                                continue;
                            }

                            $currentLosses = $this->lossState->consecutiveLosses($bot, $instrument);
                            if ($currentLosses < $lossesBeforeEntry) {
                                // Виртуальный вход берёт ту же цену и ту же экспирацию, что
                                // будущая реальная сделка. Его результат обновит счётчик пары.
                                $virtualEntryAt = now();
                                $virtualEntryPrice = (float) (($partial['close'] ?? null) ?? end($closed)['close']);
                                BotVirtualTrade::query()->create([
                                    'bot_id' => $bot->id,
                                    'instrument' => $instrument,
                                    'side' => $side,
                                    'status' => BotVirtualTrade::STATUS_OPEN,
                                    'result' => BotVirtualTrade::RESULT_PENDING,
                                    'entry_time' => $virtualEntryAt,
                                    'expiry_time' => $virtualEntryAt->copy()->addSeconds($decisionExpirySec),
                                    'entry_price' => $virtualEntryPrice,
                                    'dedupe_key' => $dedupeKey,
                                ]);
                                $this->finishAnalysisRun($analysisRun, BotAnalysisRun::STATUS_SKIPPED, 'virtual_signal_recorded', [
                                    'decision' => $decision,
                                    'virtual_losses' => $currentLosses,
                                    'losses_before_entry' => $lossesBeforeEntry,
                                ]);

                                continue;
                            }
                        }
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
                            'meta_json' => array_filter([
                                'confidence' => $confidence,
                                'decision_version' => $signal->decision_version,
                                // Полная длительность на PO = expiry_sec_used (см. BotTradeMirrorService::resolveDurationSec).
                                'expiry_sec_used' => $decisionExpirySec,
                                // Dual DeMarker: колено 1; follow-up после LOSS читает эти поля.
                                'knee' => DemarkerDualFastConfig::is($bot->type)
                                    ? max(1, (int) (($decision['features']['knee'] ?? 1)))
                                    : null,
                                'entries_per_signal' => DemarkerDualFastConfig::is($bot->type)
                                    ? max(1, min(5, (int) (($decision['features']['entries_per_signal'] ?? 2))))
                                    : null,
                            ], static fn ($v) => $v !== null),
                        ]);
                        $createdTradeId = (int) $trade->id;

                        // WS: полная карточка сделки → dashboard лента + stats на странице бота.
                        $openedTrade = $trade;
                        if (! $openedTrade->relationLoaded('bot')) {
                            $openedTrade->setRelation('bot', $bot);
                        }
                        DB::afterCommit(function () use ($openedTrade): void {
                            app(\App\Services\BotLiveEventsBroadcast::class)->tradeOpened($openedTrade);
                        });

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

                    // Telegram только после настоящей paper-сделки: виртуальные проверки
                    // Strong Trend + DeMarker не должны создавать торговые уведомления.
                    $telegramResult = null;
                    if (in_array($bot->type, ['strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE, 'sweep_raid_liquidity', 'engulfing_liquidity', AuthorFlowConfig::BOT_TYPE, 'smc_flow'], true)) {
                        $telegramResult = app(TelegramAlertService::class)->sendStrategyAlert(
                            $bot,
                            $instrument,
                            $decision,
                            $payoutSnapshot,
                            $decisionExpirySec,
                            $entryPrice,
                            $closed,
                        );
                        if (! ($telegramResult['ok'] ?? false)
                            && ! in_array($telegramResult['error'] ?? '', ['telegram_disabled', 'telegram_notify_disabled'], true)) {
                            Log::warning('strategy_telegram_alert_failed', [
                                'bot_id' => $bot->id,
                                'bot_type' => $bot->type,
                                'instrument' => $instrument,
                                'error' => $telegramResult['error'] ?? 'unknown',
                            ]);
                        }
                    } elseif (LevelBounceBotTypes::supportsCards($bot->type)) {
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
                    if (! $skipProgressLifecycle) {
                        BotScanProgress::finishInstrument($bot->id, $instrument, $shardIndex, $shardCount);
                    }
                }
            }

            return [
                'processed' => $processed,
                'signals' => $signals,
                'trades' => $trades,
                'cache_hits' => $cacheHits,
                'db_loads' => $dbLoads,
                'promoted' => $promotedBars,
                'wall_ms' => (int) round((hrtime(true) - $scanWallStarted) / 1e6),
            ];
        } finally {
            if (! $skipProgressLifecycle) {
                BotScanProgress::endScan($bot->id, $scanRunId, $shardIndex, $shardCount);
            }
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
        if (! ChartAnalystBotTypes::isAnalyst($bot->type)) {
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
        // Промпты ForConfig различают SMC/SRL/AF по флагу и type — прокидываем type в config.
        $config['_bot_type'] = $bot->type;
        $count = \App\Support\OpenRouterLlmConfig::m1BarCount($config);
        $s5Count = \App\Support\OpenRouterLlmConfig::s5BarCount($config);
        $analystSrlEnabled = ChartAnalystBotTypes::isSrl($bot->type)
            || \App\Support\OpenRouterLlmConfig::analystSrlFlowEnabled($config);
        $analystEngulfEnabled = ChartAnalystBotTypes::isEngulf($bot->type)
            || \App\Support\OpenRouterLlmConfig::analystEngulfFlowEnabled($config);
        $analystImpulseEnabled = ChartAnalystBotTypes::isImpulse($bot->type)
            || \App\Support\OpenRouterLlmConfig::analystImpulseLvlFlowEnabled($config);
        $analystAuthorFlowEnabled = ChartAnalystBotTypes::isAuthorFlow($bot->type)
            || \App\Support\OpenRouterLlmConfig::analystAuthorFlowEnabled($config);
        // Author Flow считает на S15; остальные аналитики — на M1.
        $primaryTf = $analystAuthorFlowEnabled ? CandleService::TF_S15 : 1;
        // stale_after_sec не проверяем: передаём очень большой порог «свежести».
        $series = $this->botDataService->series($instrument, $count, true, 31536000, $primaryTf);
        $closed = $series['closed'];
        $partial = $series['partial'];
        // S5 — сырой секундный поток ingest (4005), для тайминга внутри текущего бара.
        $s5Series = $this->botDataService->s5Series($instrument, $s5Count, true, 31536000);
        $mtf = $this->openRouterMtfSeries($instrument, $config, true);
        $analystSmcEnabled = ! $analystSrlEnabled && ! $analystEngulfEnabled && ! $analystImpulseEnabled
            && ! $analystAuthorFlowEnabled
            && \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($config);
        $smcFlowPack = $analystSmcEnabled
            ? $this->buildAnalystSmcFlowPack($bot, $instrument, $count)
            : null;
        $srlPack = $analystSrlEnabled
            ? $this->buildAnalystSweepRaidPack($bot, $instrument, $count, $config)
            : null;
        $engulfPack = $analystEngulfEnabled
            ? $this->buildAnalystEngulfPack($bot, $instrument, $count, $config)
            : null;
        $impulsePack = $analystImpulseEnabled
            ? $this->buildAnalystImpulseLvlPack($bot, $instrument, $count, $config)
            : null;
        $authorFlowPack = $analystAuthorFlowEnabled
            ? $this->buildAnalystAuthorFlowPack($closed, $config)
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
            'analyst_srl_flow_enabled' => $analystSrlEnabled,
            'analyst_engulf_flow_enabled' => $analystEngulfEnabled,
            'analyst_impulse_lvl_flow_enabled' => $analystImpulseEnabled,
            'analyst_author_flow_enabled' => $analystAuthorFlowEnabled,
        ];
        if ($analystSmcEnabled && is_array($smcFlowPack)) {
            $analyzeContext['smc_flow_summary'] = $smcFlowPack['snapshot'];
        }
        if ($analystSrlEnabled && is_array($srlPack)) {
            $analyzeContext['sweep_raid_summary'] = $srlPack['snapshot'];
        }
        if ($analystEngulfEnabled && is_array($engulfPack)) {
            $analyzeContext['engulfing_summary'] = $engulfPack['snapshot'];
        }
        if ($analystImpulseEnabled && is_array($impulsePack)) {
            $analyzeContext['impulse_lvl_summary'] = $impulsePack['snapshot'];
        }
        if ($analystAuthorFlowEnabled && is_array($authorFlowPack)) {
            $analyzeContext['author_flow_summary'] = $authorFlowPack['snapshot'];
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

        $chartLevels = is_array($decision['chart_levels'] ?? null) ? $decision['chart_levels'] : [
            'levels' => [],
            'zones' => [],
            'entry' => null,
        ];
        $chartLevelsOk = (bool) ($decision['chart_levels_ok'] ?? false);
        // Зоны 47 / свинги AF рисуем даже если модель не вернула chart_levels; уровни LLM дописываются сверху.
        if ($analystImpulseEnabled && is_array($impulsePack)) {
            $seed = is_array($impulsePack['chart_levels'] ?? null) ? $impulsePack['chart_levels'] : [
                'levels' => [],
                'zones' => [],
                'entry' => null,
            ];
            $chartLevels = ImpulseLvlLlmSnapshot::mergeChartLevels($chartLevels, $seed);
            if (($seed['levels'] ?? []) !== [] || ($seed['zones'] ?? []) !== []) {
                $chartLevelsOk = true;
            }
        }
        if ($analystAuthorFlowEnabled && is_array($authorFlowPack)) {
            $seed = is_array($authorFlowPack['chart_levels'] ?? null) ? $authorFlowPack['chart_levels'] : [
                'levels' => [],
                'zones' => [],
                'entry' => null,
            ];
            $chartLevels = AuthorFlowLlmSnapshot::mergeChartLevels($chartLevels, $seed);
            if (($seed['levels'] ?? []) !== [] || ($seed['zones'] ?? []) !== []) {
                $chartLevelsOk = true;
            }
        }

        return [
            'instrument' => $instrument,
            'summary' => 'Анализ готов.',
            'analysis_text' => (string) $decision['analysis_text'],
            'chart_levels' => $chartLevels,
            'chart_levels_ok' => $chartLevelsOk,
            'chart_levels_message' => isset($decision['chart_levels_message']) ? (string) $decision['chart_levels_message'] : null,
            'user_question' => $userQuestion,
            'model' => (string) ($features['model'] ?? ($config['model'] ?? '')),
            'llm_log_id' => isset($decision['llm_log_id']) ? (int) $decision['llm_log_id'] : null,
            'error' => null,
            'billing' => $billing,
            'billing_label' => OpenRouterClient::formatBillingLabel($billing),
            'analyst_smc_flow_enabled' => $analystSmcEnabled,
            'analyst_srl_flow_enabled' => $analystSrlEnabled,
            'analyst_engulf_flow_enabled' => $analystEngulfEnabled,
            'analyst_impulse_lvl_flow_enabled' => $analystImpulseEnabled,
            'analyst_author_flow_enabled' => $analystAuthorFlowEnabled,
            'analyst_prompt_mode' => (string) ($features['analyst_prompt_mode'] ?? \App\Support\OpenRouterLlmConfig::analystPromptMode($config)),
            'smc_flow' => $analystSmcEnabled && is_array($smcFlowPack) ? $smcFlowPack['overlay'] : null,
            'smc_flow_modules' => $analystSmcEnabled && is_array($smcFlowPack)
                ? ($smcFlowPack['overlay']['modules'] ?? [])
                : [],
            'sweep_raid_liquidity' => $analystSrlEnabled && is_array($srlPack) ? $srlPack['overlay'] : null,
            'engulfing_liquidity' => $analystEngulfEnabled && is_array($engulfPack) ? $engulfPack['overlay'] : null,
            'impulse_lvl' => $analystImpulseEnabled && is_array($impulsePack) ? $impulsePack['overlay'] : null,
            'impulse_lvl_telegram' => $analystImpulseEnabled && is_array($impulsePack)
                ? ($impulsePack['telegram'] ?? null)
                : null,
            'author_flow' => $analystAuthorFlowEnabled && is_array($authorFlowPack) ? $authorFlowPack['overlay'] : null,
            'hybrid_mode' => is_array($hybridSourceSignal) && $hybridSourceSignal !== [],
        ];
    }

    /**
     * Author Flow для аналитика: пул кандидатов на S15 + контекст структуры/ликвидности.
     *
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $s15Closed
     * @param  array<string, mixed>  $config
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,chart_levels:array<string,mixed>,bar_count:int}
     */
    private function buildAnalystAuthorFlowPack(array $s15Closed, array $config): array
    {
        $pack = AuthorFlowLlmSnapshot::pack($s15Closed, $config);

        return [
            'snapshot' => $pack['snapshot'],
            'overlay' => $pack['overlay'],
            'chart_levels' => $pack['chart_levels'],
            'bar_count' => count($s15Closed),
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
     * Sweep/Raid на M1 для аналитика: overlay на график + компактный snapshot кандидатов для LLM.
     *
     * @param  array<string, mixed>  $config
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,bar_count:int}
     */
    private function buildAnalystSweepRaidPack(Bot $bot, string $instrument, int $m1BarCount, array $config): array
    {
        $emptyOverlay = [
            'lines' => [],
            'labels' => [],
            'label_size' => 'small',
        ];

        $candleService = app(CandleService::class);
        $srlService = app(SweepRaidLiquidityIndicatorService::class);

        // Детектору нужны подтверждённые пивоты + история событий — берём не меньше count.
        $srlBars = min(5000, max($m1BarCount, 500));
        $since = time() - max(86400, $srlBars * 60);
        $rows = $candleService->seriesForDashboard($instrument, 1, $since, null, $srlBars);
        $options = [
            'pivot_length' => (int) ($config['srl_pivot_length'] ?? 2),
            'enter_sweep' => (bool) ($config['srl_enter_sweep'] ?? true),
            'enter_pullback' => (bool) ($config['srl_enter_pullback'] ?? true),
            'enter_breakout' => (bool) ($config['srl_enter_breakout'] ?? false),
            'breakout_closes' => max(1, min(5, (int) ($config['srl_breakout_closes'] ?? 2))),
        ];

        if ($rows === []) {
            return [
                'snapshot' => SweepRaidLlmSnapshot::build([], $options),
                'overlay' => $emptyOverlay,
                'bar_count' => 0,
            ];
        }

        // Грязная OTC-пара — не отдаём кандидаты LLM (тот же фильтр, что у rule-based).
        if (\App\Support\OtcGapFilter::shouldSkip($rows, $config, 'srl')) {
            $emptySnap = SweepRaidLlmSnapshot::build([], $options);
            $emptySnap['gap_skip'] = true;
            $gapStats = \App\Support\OtcGapFilter::measure($rows, $config, 'srl');
            if ($gapStats !== null) {
                $emptySnap['gap_ratio'] = $gapStats['gap_ratio'];
            }

            return [
                'snapshot' => $emptySnap,
                'overlay' => $emptyOverlay,
                'bar_count' => count($rows),
            ];
        }

        $params = IndicatorSetting::mergedSweepRaidLiquidityParams($bot);
        $params = \App\Support\BotTradingChartLayers::overlayParams($bot, 'srl', $params);
        // Для аналитика всегда показываем sweep/raid/pb/bo согласно флагам входа.
        $params['pivot_length'] = $options['pivot_length'];
        $params['show_fractals'] = true;
        $params['show_sweeps'] = $options['enter_sweep'];
        $params['show_raids'] = $options['enter_pullback'];
        $params['show_pullback_entries'] = $options['enter_pullback'];
        $params['show_breakout_entries'] = $options['enter_breakout'];
        $params['breakout_closes'] = $options['breakout_closes'];
        $overlay = $srlService->compute($rows, $params);

        return [
            'snapshot' => SweepRaidLlmSnapshot::build($rows, $options),
            'overlay' => $overlay,
            'bar_count' => count($rows),
        ];
    }

    /**
     * Engulfing на M1 для аналитика: overlay ENGULF 1/2/3 + snapshot кандидатов для LLM.
     *
     * @param  array<string, mixed>  $config
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,bar_count:int}
     */
    private function buildAnalystEngulfPack(Bot $bot, string $instrument, int $m1BarCount, array $config): array
    {
        $emptyOverlay = [
            'lines' => [],
            'labels' => [],
            'label_size' => 'small',
        ];

        $candleService = app(CandleService::class);
        $engService = app(EngulfingLiquidityIndicatorService::class);

        $engBars = min(5000, max($m1BarCount, 500));
        $since = time() - max(86400, $engBars * 60);
        $rows = $candleService->seriesForDashboard($instrument, 1, $since, null, $engBars);
        $options = [
            'allow_classic' => (bool) ($config['eng_allow_classic'] ?? true),
            'allow_same_color' => (bool) ($config['eng_allow_same_color'] ?? true),
        ];

        if ($rows === []) {
            return [
                'snapshot' => EngulfingLlmSnapshot::build([], $options),
                'overlay' => $emptyOverlay,
                'bar_count' => 0,
            ];
        }

        if (\App\Support\OtcGapFilter::shouldSkip($rows, $config, 'eng')) {
            $emptySnap = EngulfingLlmSnapshot::build([], $options);
            $emptySnap['gap_skip'] = true;
            $gapStats = \App\Support\OtcGapFilter::measure($rows, $config, 'eng');
            if ($gapStats !== null) {
                $emptySnap['gap_ratio'] = $gapStats['gap_ratio'];
            }

            return [
                'snapshot' => $emptySnap,
                'overlay' => $emptyOverlay,
                'bar_count' => count($rows),
            ];
        }

        $params = IndicatorSetting::mergedEngulfingLiquidityParams($bot);
        $params = \App\Support\BotTradingChartLayers::overlayParams($bot, 'eng', $params);
        $params['allow_classic'] = $options['allow_classic'];
        $params['allow_same_color'] = $options['allow_same_color'];
        $params['show_step1'] = (bool) ($config['eng_show_step1'] ?? $params['show_step1'] ?? true);
        $params['show_step2'] = (bool) ($config['eng_show_step2'] ?? $params['show_step2'] ?? true);
        $params['show_step3'] = (bool) ($config['eng_show_step3'] ?? $params['show_step3'] ?? true);
        $overlay = $engService->compute($rows, $params);

        return [
            'snapshot' => EngulfingLlmSnapshot::build($rows, $options),
            'overlay' => $overlay,
            'bar_count' => count($rows),
        ];
    }

    /**
     * Impulse LVL для аналитика: непробитые зоны M1–M30 + live-хиты 47 в snapshot.
     *
     * @param  array<string, mixed>  $config
     * @return array{snapshot:array<string,mixed>,overlay:array<string,mixed>,chart_levels:array<string,mixed>,telegram:array<string,mixed>,bar_count:int}
     */
    private function buildAnalystImpulseLvlPack(Bot $bot, string $instrument, int $m1BarCount, array $config): array
    {
        $impulseCfg = array_merge(ImpulseLvl1minConfig::defaultConfig(), $config);
        $history = max(
            $m1BarCount,
            ImpulseLvl1minConfig::historyPeriod($impulseCfg),
            ImpulseLvl1minConfig::DEFAULT_HISTORY_BARS
        );
        $tfMap = [
            5 => max(20, (int) ($impulseCfg['m5_take'] ?? 80)),
            15 => max(20, (int) ($impulseCfg['m15_take'] ?? 60)),
            30 => max(20, (int) ($impulseCfg['m30_take'] ?? 40)),
        ];
        $keepForming = ImpulseLvl1minConfig::flag($impulseCfg, 'keep_stale_forming_m1', true);
        $bundle = $this->botDataService->seriesWithMtf(
            $instrument,
            $history,
            $tfMap,
            true,
            31536000,
            1,
            $keepForming
        );
        $closed = is_array($bundle['closed'] ?? null) ? array_values($bundle['closed']) : [];
        $partial = is_array($bundle['partial'] ?? null) ? $bundle['partial'] : null;
        $mtf = is_array($bundle['mtf'] ?? null) ? $bundle['mtf'] : [];

        if ($partial !== null) {
            $eval = $partial;
            $m1Closed = $closed;
        } elseif ($closed !== []) {
            $eval = $closed[array_key_last($closed)];
            $m1Closed = array_slice($closed, 0, -1);
        } else {
            $eval = null;
            $m1Closed = [];
        }

        $pack = ImpulseLvlLlmSnapshot::pack($m1Closed, $eval, $mtf, $impulseCfg);

        return [
            'snapshot' => $pack['snapshot'],
            'overlay' => $pack['overlay'],
            'chart_levels' => $pack['chart_levels'],
            'telegram' => is_array($pack['telegram'] ?? null) ? $pack['telegram'] : [
                'zones' => [],
                'overlay_lines' => [],
                'price_levels' => [],
                'markers' => [],
                'signal_time' => 0,
            ],
            'bar_count' => count($closed) + ($partial ? 1 : 0),
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
        if (ChartAnalystBotTypes::isAnalyst($bot->type)) {
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

        $mtf = [];
        if ($bot->type === 'candle_reversal_confirm' || $bot->type === 'strong_level') {
            $cfg = $runtime['config'];
            $packed = $this->botDataService->seriesWithMtf(
                $instrument,
                $runtime['count'],
                [
                    5 => (int) ($cfg['m5_take'] ?? 120),
                    15 => (int) ($cfg['m15_take'] ?? 80),
                    60 => (int) ($cfg['h1_take'] ?? 12),
                ],
                true,
                $runtime['stale_after_sec'],
            );
            $series = $packed;
            $mtf = $packed['mtf'];
        } elseif (ImpulseLvl1minConfig::is((string) $bot->type)) {
            $packed = $this->botDataService->seriesWithMtf(
                $instrument,
                $runtime['count'],
                ImpulseLvl1minConfig::mtfTakeMap($runtime['config']),
                true,
                $runtime['stale_after_sec'],
                (int) ($runtime['series_timeframe'] ?? 1),
                ImpulseLvl1minConfig::flag($runtime['config'], 'keep_stale_forming_m1', true),
            );
            $series = $packed;
            $mtf = $packed['mtf'];
        } else {
            $series = $this->botDataService->series(
                $instrument,
                $runtime['count'],
                true,
                $runtime['stale_after_sec']
            );
        }
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

        if ($bot->type === 'openrouter_llm' || ChartAnalystBotTypes::isAnalyst($bot->type)) {
            $mtf = $this->openRouterMtfSeries($instrument, $runtime['config'], ChartAnalystBotTypes::isAnalyst($bot->type));
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
                    MaxBotConfig::BOT_TYPE => 'Нет импульса MaxBot: серия + ratio тел к историческому максимуму.',
                    ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => 'Нет импульса Impulse LVL: профиль/MAX + касание LVL.',
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
            $impulseCfg = is_array($runtime['config'] ?? null) ? $runtime['config'] : [];
            if (ImpulseLvl1minConfig::is((string) $bot->type)) {
                $alertsBlocked = ImpulseLvl1minConfig::flag($impulseCfg, 'occupancy_blocks_alerts', false);
                $paperBlocked = ImpulseLvl1minConfig::flag($impulseCfg, 'occupancy_blocks_paper', true);
                if ($alertsBlocked) {
                    $blockers[] = 'Открытая paper режет и алерты, и новую сделку (галочка «занятость режет алерты»).';
                } elseif ($paperBlocked) {
                    $blockers[] = 'Paper по занятости уже открыт — алерты уйдут, вторая сделка нет.';
                }
            } else {
                $blockers[] = 'По этой паре уже есть открытая сделка у данного бота.';
            }
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
        // Пустой JSON у S15 не должен читать M1 (timeframe_sec=60). Бот 47 не трогаем.
        if (ImpulseLvl1minConfig::isS15((string) $bot->type)) {
            $config['timeframe_sec'] = ImpulseLvl1minConfig::S15_TIMEFRAME_SEC;
            $config['_bot_type'] = ImpulseLvl1minConfig::BOT_TYPE_S15;
        }
        $defaultCount = match ($bot->type) {
            'smc_flow' => 500,
            // Длинная история нужна для подтверждённых фракталов и активных уровней ликвидности.
            'sweep_raid_liquidity' => 500,
            'engulfing_liquidity' => 500,
            AuthorFlowConfig::BOT_TYPE => 500,
            'strong_level' => 500,
            'candle_reversal_confirm' => 500,
            'openrouter_llm_analyst' => 120,
            ChartAnalystBotTypes::SRL => 120,
            ChartAnalystBotTypes::ENGULF => 120,
            ChartAnalystBotTypes::IMPULSE => 120,
            ChartAnalystBotTypes::AUTHOR_FLOW => 200,
            'strong_trend_telegram' => 120,
            'strong_trend_demarker' => 120,
            DemarkerDualFastConfig::BOT_TYPE => 120,
            MaxBotConfig::BOT_TYPE => max(110, MaxBotConfig::requiredHistoryBars(
                MaxBotConfig::enabledStrategies($config),
                (int) ($config['count'] ?? MaxBotConfig::DEFAULT_HISTORY_BARS)
            )),
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => max(
                ImpulseLvl1minConfig::isS15((string) $bot->type) ? 800 : 400,
                (int) ($config['count'] ?? (ImpulseLvl1minConfig::isS15((string) $bot->type) ? 800 : 400))
            ),
            // Wave B vola-adaptive: нужна ~1 день M1 для AdaptSoldiersHeight / AdaptSignalCandleHeight.
            'vol_adaptive_candles', 'three_soldier_vol_adaptive', 'pivot_points' => 2000,
            default => LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 150 : 120,
        };
        $count = match ($bot->type) {
            // LLM-боты: count = сколько M1 загрузить и отправить в модель (см. OpenRouterLlmConfig).
            'openrouter_llm' => \App\Support\OpenRouterLlmConfig::m1BarCount($config, 120, 20),
            'openrouter_llm_analyst' => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            ChartAnalystBotTypes::SRL => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            ChartAnalystBotTypes::ENGULF => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            ChartAnalystBotTypes::IMPULSE => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            ChartAnalystBotTypes::AUTHOR_FLOW => \App\Support\OpenRouterLlmConfig::m1BarCount($config),
            default => max(10, min(5000, (int) ($config['count'] ?? $defaultCount))),
        };
        $expirySec = match ($bot->type) {
            'three_candles_in_row' => (int) ($config['expiry_sec'] ?? 60),
            'smc_flow' => (int) ($config['expiry_sec'] ?? 180),
            'sweep_raid_liquidity' => BinaryExpiry::clamp((int) ($config['expiry_sec'] ?? 60)),
            'engulfing_liquidity' => BinaryExpiry::clamp((int) ($config['expiry_sec'] ?? 60)),
            AuthorFlowConfig::BOT_TYPE => BinaryExpiry::clamp((int) ($config['expiry_sec'] ?? 15)),
            'strong_trend_telegram' => (int) ($config['expiry_sec'] ?? 60),
            'strong_trend_demarker' => (int) ($config['expiry_sec'] ?? 30),
            DemarkerDualFastConfig::BOT_TYPE => (int) ($config['expiry_sec'] ?? 30),
            MaxBotConfig::BOT_TYPE => (int) ($config['expiry_sec'] ?? 60),
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => (int) ($config['expiry_sec'] ?? (ImpulseLvl1minConfig::isS15((string) $bot->type) ? 15 : 60)),
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
            'strong_trend_demarker' => 80.0,
            DemarkerDualFastConfig::BOT_TYPE => 80.0,
            MaxBotConfig::BOT_TYPE => 90.0,
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => 80.0,
            default => LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 75.0 : 80.0,
        });
        $staleAfterSec = (int) ($config['stale_after_sec'] ?? (LevelBounceBotTypes::isOsEngineBinary($bot->type) ? 60 : 30));
        // DeMarker: 0 = серия выкл.; Strong Trend Telegram: минимум 2 (сама серия и есть сигнал).
        $streakLenRaw = (int) ($config['streak_len'] ?? 6);
        $streakLen = in_array($bot->type, ['strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE], true)
            ? max(0, min(30, $streakLenRaw))
            : max(2, min(30, $streakLenRaw));
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
            'sweep_raid_liquidity' => max(10, (int) ($config['min_closed_bars'] ?? 30)),
            'engulfing_liquidity' => max(4, (int) ($config['min_closed_bars'] ?? 30)),
            AuthorFlowConfig::BOT_TYPE => max(10, (int) ($config['min_closed_bars'] ?? 30)),
            'strong_level' => max(80, (int) ($config['min_closed_bars'] ?? 200)),
            'strong_trend_telegram' => $trendPatternBars,
            'strong_trend_demarker' => max(
                $trendPatternBars + (int) ($config['demarker_period'] ?? 6) + 2,
                (int) ($config['demarker_period'] ?? 6) + 2
            ),
            DemarkerDualFastConfig::BOT_TYPE => max(
                $trendPatternBars
                    + max(
                        (int) ($config['demarker_period'] ?? 10),
                        (int) ($config['second_demarker_period'] ?? 5)
                    )
                    + (int) ($config['min_candles_in_zone'] ?? 3)
                    + (int) ($config['exit_candle_avg_bars'] ?? 10)
                    + 2,
                40
            ),
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
            MaxBotConfig::BOT_TYPE => MaxBotConfig::requiredHistoryBars(
                is_array($config['strategies'] ?? null) ? $config['strategies'] : MaxBotConfig::defaultStrategies(),
                max(MaxBotConfig::DEFAULT_HISTORY_BARS, (int) ($config['count'] ?? 0) - 10)
            ),
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => ImpulseLvl1minConfig::DEFAULT_HISTORY_BARS,
            // LLM-боту нужен достаточно широкий контекст последних свечей,
            // иначе модель чаще даёт шумные и нестабильные решения.
            'openrouter_llm' => 20,
            // Аналитик: ручной запрос без порога «минимум баров».
            'openrouter_llm_analyst' => 0,
            ChartAnalystBotTypes::SRL => 0,
            ChartAnalystBotTypes::ENGULF => 0,
            ChartAnalystBotTypes::IMPULSE => 0,
            ChartAnalystBotTypes::AUTHOR_FLOW => 0,
            default => 3,
        };
        // Указываем таймфрейм входного потока для стратегии.
        // Strong Trend + DeMarker поддерживает S30 по умолчанию и M1 по выбору.
        // SRL / Engulfing: S15 (4015) как у автора на OTC; иначе M1.
        $seriesTimeframe = match ($bot->type) {
            MaxBotConfig::BOT_TYPE => MaxBotConfig::dashboardTimeframeId(
                (int) ($config['timeframe_sec'] ?? MaxBotConfig::DEFAULT_TIMEFRAME_SEC)
            ),
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => ImpulseLvl1minConfig::dashboardTimeframeId(
                (int) ($config['timeframe_sec'] ?? (ImpulseLvl1minConfig::isS15((string) $bot->type) ? ImpulseLvl1minConfig::S15_TIMEFRAME_SEC : ImpulseLvl1minConfig::DEFAULT_TIMEFRAME_SEC))
            ),
            'strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE => in_array(
                (int) ($config['timeframe'] ?? CandleService::TF_S30),
                [CandleService::TF_S30, 1],
                true
            )
                ? (int) ($config['timeframe'] ?? CandleService::TF_S30)
                : CandleService::TF_S30,
            'sweep_raid_liquidity', 'engulfing_liquidity', AuthorFlowConfig::BOT_TYPE => in_array(
                (int) ($config['timeframe'] ?? ($bot->type === AuthorFlowConfig::BOT_TYPE ? CandleService::TF_S15 : 1)),
                [CandleService::TF_S5, CandleService::TF_S15, CandleService::TF_S30, 1],
                true
            )
                ? (int) ($config['timeframe'] ?? ($bot->type === AuthorFlowConfig::BOT_TYPE ? CandleService::TF_S15 : 1))
                : ($bot->type === AuthorFlowConfig::BOT_TYPE ? CandleService::TF_S15 : 1),
            default => 1,
        };
        $dedupeBucketSec = match ($bot->type) {
            'strong_trend_telegram' => max(60, min(3600, (int) ($config['dedupe_bucket_sec'] ?? 300))),
            'strong_trend_demarker', DemarkerDualFastConfig::BOT_TYPE => max(30, min(3600, (int) ($config['dedupe_bucket_sec'] ?? $expirySec))),
            MaxBotConfig::BOT_TYPE => max(5, min(3600, (int) ($config['dedupe_bucket_sec'] ?? $expirySec))),
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => max(5, min(3600, (int) ($config['dedupe_bucket_sec'] ?? $expirySec))),
            // Dedupe ≈ expiry: при 15с не держим искусственный floor 60.
            'smc_flow', 'strong_level', 'openrouter_llm', 'candle_reversal_confirm', 'sweep_raid_liquidity', 'engulfing_liquidity', AuthorFlowConfig::BOT_TYPE => max(15, min(900, $expirySec)),
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

    /**
     * Публичный доступ к стратегии по типу бота.
     * Нужен backtest-движку и explain, чтобы не дублировать match-таблицу.
     */
    public function strategyForType(string $type): ?BotStrategyInterface
    {
        return $this->resolveStrategy($type);
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
            ChartAnalystBotTypes::SRL => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
            ChartAnalystBotTypes::ENGULF => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
            ChartAnalystBotTypes::IMPULSE => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
            ChartAnalystBotTypes::AUTHOR_FLOW => new OpenRouterLlmAnalystStrategy($this->openRouterClient),
            'smc_flow' => new SmcFlowStrategy($this->smcFlowBotSignalAnalyzer),
            'sweep_raid_liquidity' => new SweepRaidLiquidityStrategy,
            'engulfing_liquidity' => new EngulfingLiquidityStrategy,
            AuthorFlowConfig::BOT_TYPE => new AuthorFlowStrategy,
            'strong_level' => new StrongLevelStrategy,
            'strong_trend_telegram' => new StrongTrendTelegramStrategy,
            'strong_trend_demarker' => new StrongTrendDemarkerStrategy(new DemarkerIndicatorService),
            DemarkerDualFastConfig::BOT_TYPE => new DemarkerDualFastStrategy(new DemarkerIndicatorService),
            MaxBotConfig::BOT_TYPE => new MaxBotStrategy,
            ImpulseLvl1minConfig::BOT_TYPE, ImpulseLvl1minConfig::BOT_TYPE_S15 => new ImpulseLvl1minStrategy,
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

    /**
     * MAX + все профили + prev: отдельные decision для алертов (без вложенных extra_alerts).
     *
     * @param  array<string, mixed>  $decision
     * @return list<array<string, mixed>>
     */
    private function impulseLvlDecisionHits(array $decision): array
    {
        $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $extras = is_array($features['extra_alerts'] ?? null) ? $features['extra_alerts'] : [];
        $primary = $decision;
        if (! isset($primary['features']) || ! is_array($primary['features'])) {
            $primary['features'] = [];
        }
        unset($primary['features']['extra_alerts']);
        $hits = [$primary];
        foreach ($extras as $extra) {
            if (! is_array($extra) || ! in_array((string) ($extra['side'] ?? ''), ['CALL', 'PUT'], true)) {
                continue;
            }
            if (isset($extra['features']) && is_array($extra['features'])) {
                unset($extra['features']['extra_alerts']);
            }
            $hits[] = $extra;
        }

        return $hits;
    }

    /**
     * Сигнал + Telegram; paper только если $createTrade (занятость / второй профиль — без пачки сделок).
     *
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $closed
     * @param  array<string, mixed>|null  $partial  live M1 — обязателен на PNG, иначе график отстаёт на 1+ свечу
     * @return array{ok:bool,skip?:string,signal_id?:int,trade_id?:int,telegram?:array<string,mixed>|null}
     */
    private function commitImpulseLvlHit(
        Bot $bot,
        string $instrument,
        array $decision,
        ?float $payoutSnapshot,
        int $expirySec,
        float $entryPrice,
        array $closed,
        int $dedupeBucketSec,
        bool $createTrade,
        ?array $partial = null,
    ): array {
        $side = (string) ($decision['side'] ?? '');
        $confidence = (float) ($decision['confidence'] ?? 0);
        $bucket = intdiv(time(), max(5, $dedupeBucketSec)) * max(5, $dedupeBucketSec);
        $feat = is_array($decision['features'] ?? null) ? $decision['features'] : [];
        $dedupeSuffix = sprintf(
            '%s:%d:%s',
            (string) (($feat['strategy_id'] ?? '') ?: 'max'),
            (int) ($feat['signal_timestamp'] ?? 0),
            ! empty($feat['is_prev']) ? 'prev' : 'live'
        );
        $dedupeKey = sprintf('%d:%s:%d:%s', $bot->id, $instrument, $bucket, $dedupeSuffix);
        if (BotSignal::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return ['ok' => false, 'skip' => 'dedupe'];
        }

        $entryAt = now();
        $expiryAt = $entryAt->copy()->addSeconds($expirySec);
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
            $expirySec,
            $decision,
            $dedupeKey,
            $entryPrice,
            $createTrade,
            &$createdSignalId,
            &$createdTradeId
        ): void {
            $features = is_array($decision['features'] ?? null) ? $decision['features'] : [];
            unset($features['extra_alerts']);
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
                'features_json' => $features,
                'decision_version' => (string) ($decision['decision_version'] ?? 'v1'),
                'dedupe_key' => $dedupeKey,
            ]);
            $createdSignalId = (int) $signal->id;
            if (! $createTrade) {
                return;
            }
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
                    'expiry_sec_used' => $expirySec,
                ],
            ]);
            $createdTradeId = (int) $trade->id;
            DB::afterCommit(function () use ($createdTradeId): void {
                app(MirrorTradeDispatcher::class)->dispatch($createdTradeId);
            });
            // WS: полная карточка → dashboard / stats.
            $openedTrade = $trade;
            if (! $openedTrade->relationLoaded('bot')) {
                $openedTrade->setRelation('bot', $bot);
            }
            DB::afterCommit(function () use ($openedTrade): void {
                app(\App\Services\BotLiveEventsBroadcast::class)->tradeOpened($openedTrade);
            });
        });

        // Как в scanBot: гибрид на любой BotSignal, не только на paper-сделку
        // (у 47 occupancy часто режет paper, алерты всё равно уходят).
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

        // PNG как у updateBotM1: closed + текущий forming M1. Без partial график отстаёт на 1+ свечу
        // (сигнал live по signal_timestamp отсутствует в серии → золотая рамка на старой закрытой).
        $chartCandles = $this->impulseChartCandles($closed, $partial);

        $telegramResult = app(TelegramAlertService::class)->sendImpulseLvlAlert(
            $bot,
            $instrument,
            $decision,
            $payoutSnapshot,
            $expirySec,
            $entryPrice,
            $chartCandles
        );

        return [
            'ok' => true,
            'signal_id' => $createdSignalId,
            'trade_id' => $createdTradeId,
            'telegram' => $telegramResult,
        ];
    }

    /**
     * Серия для Telegram M1: закрытые + live partial (по time, partial перекрывает тот же бакет).
     *
     * @param  list<array<string, mixed>>  $closed
     * @param  array<string, mixed>|null  $partial
     * @return list<array<string, mixed>>
     */
    private function impulseChartCandles(array $closed, ?array $partial): array
    {
        $byTime = [];
        foreach ($closed as $row) {
            if (! is_array($row)) {
                continue;
            }
            $t = (int) ($row['time'] ?? 0);
            if ($t <= 0) {
                continue;
            }
            $byTime[$t] = $row;
        }
        if ($partial !== null) {
            $t = (int) ($partial['time'] ?? 0);
            if ($t > 0) {
                $byTime[$t] = $partial;
            }
        }
        ksort($byTime, SORT_NUMERIC);

        return array_values($byTime);
    }
}
