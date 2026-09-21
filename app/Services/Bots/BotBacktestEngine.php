<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotBacktestRun;
use App\Models\BotBacktestTrade;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
use App\Models\PocketInstrument;
/**
 * Candle-replay прогон настроек бота по истории.
 *
 * Не вызывает scanBot: нет Telegram/mirror/BotTrade/loss-state.
 * Берёт тот же strategy->analyze() и runtimeSettings(), что и live-движок.
 *
 * Модель входа (v1):
 * - только закрытые бары (partial = null);
 * - сигнал на баре i → entry_price = close[i], entry_ts = time[i] + period(tf);
 *   (как live: вход в момент принятия решения после закрытия бара);
 * - settle через BotSettlementService::marketPriceAtExpiry.
 */
class BotBacktestEngine
{
    /** Типы, которые нельзя дёшево/детерминированно прогнать на истории. */
    private const UNSUPPORTED_TYPES = [
        'openrouter_llm',
        'openrouter_llm_analyst',
        \App\Support\ChartAnalystBotTypes::SRL,
        \App\Support\ChartAnalystBotTypes::ENGULF,
        \App\Support\ChartAnalystBotTypes::IMPULSE,
        'manual_dashboard',
    ];

    public function __construct(
        private readonly BotEngineService $engine,
        private readonly BotDataService $botData,
        private readonly BotSettlementService $settlement,
    ) {}

    /**
     * Создаёт queued-run и возвращает модель (dispatch Job снаружи).
     *
     * @param  array{
     *   from_ts:int,
     *   to_ts:int,
     *   instruments?:list<string>,
     *   payout?:float,
     *   config_override?:array<string,mixed>,
     *   simulate_dedupe?:bool,
     *   one_trade_at_a_time?:bool
     * }  $params
     */
    public function createRun(Bot $bot, int $userId, array $params): BotBacktestRun
    {
        if (in_array($bot->type, self::UNSUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Тип бота «{$bot->type}» не поддерживает backtest (LLM/ручной)."
            );
        }

        $fromTs = (int) $params['from_ts'];
        $toTs = (int) $params['to_ts'];
        if ($fromTs <= 0 || $toTs <= $fromTs) {
            throw new \InvalidArgumentException('Некорректный период: from_ts < to_ts обязательны.');
        }

        // Snapshot: live config + optional override (A/B без сохранения в бота).
        $baseConfig = is_array($bot->config) ? $bot->config : [];
        $override = is_array($params['config_override'] ?? null) ? $params['config_override'] : [];
        $configSnapshot = array_replace($baseConfig, $override);

        // Payout формы бэктеста: 0 = все пары; >0 = min_payout для прогона + PnL-снимок.
        $payout = (float) ($params['payout'] ?? 0.0);
        $configSnapshot['min_payout'] = $payout;

        $runParams = [
            'instruments' => array_values(array_filter(
                array_map('strval', $params['instruments'] ?? []),
                static fn (string $s) => $s !== ''
            )),
            // Исторического payout нет → фиксируем для PnL (как paper-юнит); 0 = win даёт 0 юнитов.
            'payout' => $payout,
            'simulate_dedupe' => (bool) ($params['simulate_dedupe'] ?? true),
            // v1: не открываем новую сделку, пока предыдущая по паре ещё «в экспирации».
            'one_trade_at_a_time' => (bool) ($params['one_trade_at_a_time'] ?? true),
        ];

        return BotBacktestRun::query()->create([
            'bot_id' => $bot->id,
            'user_id' => $userId,
            'status' => BotBacktestRun::STATUS_QUEUED,
            'progress_pct' => 0,
            'from_ts' => $fromTs,
            'to_ts' => $toTs,
            'config_snapshot_json' => $configSnapshot,
            'params_json' => $runParams,
        ]);
    }

    /**
     * Основной цикл replay. Вызывается из Job.
     *
     * @return 'completed'|'continue'|'cancelled'|'failed'
     *         continue — остались пары, нужен следующий chunk-job (анти-timeout).
     */
    public function execute(BotBacktestRun $run): string
    {
        $run->refresh();
        if ($run->status === BotBacktestRun::STATUS_CANCELLED) {
            return 'cancelled';
        }

        $bot = $run->bot;
        if (! $bot) {
            $this->fail($run, 'Бот не найден.');

            return 'failed';
        }

        $strategy = $this->engine->strategyForType($bot->type);
        if (! $strategy) {
            $this->fail($run, "Стратегия для типа «{$bot->type}» не найдена.");

            return 'failed';
        }

        // Временный bot-клон с snapshot config, чтобы runtimeSettings() видел те же числа.
        $botClone = $bot->replicate();
        $botClone->id = $bot->id;
        $botClone->config = $run->config_snapshot_json ?? [];
        $botClone->exists = true;

        $runtime = $this->engine->runtimeSettings($botClone);
        $params = is_array($run->params_json) ? $run->params_json : [];
        $instruments = $params['instruments'] ?? [];
        if ($instruments === []) {
            // Fallback: whitelist из config, иначе одна «первая» активная пара не берём —
            // требуем явный список, чтобы не прогнать 115 пар случайно.
            $cfgAllowed = $runtime['config']['allowed_instruments'] ?? null;
            if (is_array($cfgAllowed) && $cfgAllowed !== []) {
                $instruments = array_values(array_filter(array_map('strval', $cfgAllowed)));
            }
        }
        if ($instruments === []) {
            $this->fail($run, 'Укажите instruments[] — иначе прогон по всем парам слишком тяжёлый.');

            return 'failed';
        }

        $payout = (float) ($params['payout'] ?? 0.0);
        $minPayout = (float) $runtime['min_payout'];
        // Текущий payout пар — один раз на chunk (фильтр как в live-scan).
        $currentPayoutByInstrument = $minPayout > 0
            ? PocketInstrument::query()
                ->whereIn('instrument', $instruments)
                ->pluck('current_payout', 'instrument')
            : collect();
        $simulateDedupe = (bool) ($params['simulate_dedupe'] ?? true);
        $oneAtATime = (bool) ($params['one_trade_at_a_time'] ?? true);
        $dedupeBucketSec = (int) $runtime['dedupe_bucket_sec'];
        $minConfidence = (float) $runtime['min_confidence'];
        $minClosedBars = (int) $runtime['min_closed_bars'];
        $lookback = (int) $runtime['count'];
        $seriesTf = (int) $runtime['series_timeframe'];
        $defaultExpirySec = (int) $runtime['expiry_sec'];
        // Секунды бара — один раз на прогон (раньше считали на каждую сделку).
        $periodSec = $this->periodSeconds($seriesTf);

        // PA/SMC params один раз на chunk: merged*() ходит в БД/кэш — не на каждый бар.
        $needsSmcParams = in_array($bot->type, ['smc_flow', 'strong_level', 'candle_reversal_confirm'], true);
        $needsPaParams = in_array($bot->type, ['strong_level', 'candle_reversal_confirm'], true);
        $needsMtf = in_array($bot->type, ['candle_reversal_confirm', 'strong_level', \App\Support\ImpulseLvl1minConfig::BOT_TYPE], true);
        $smcParams = $needsSmcParams ? IndicatorSetting::mergedSmcFlowParams($botClone) : null;
        $paParams = $needsPaParams ? IndicatorSetting::mergedPriceActionDgtParams($botClone) : null;

        // Уже обработанные пары (chunk/resume после таймаута) — не пересчитываем и не дублируем сделки.
        $doneList = array_values(array_filter(array_map('strval', $params['instruments_done'] ?? [])));
        $doneSet = array_fill_keys($doneList, true);
        // Мягкий лимит chunk’а < job timeout (3600), чтобы успеть сохранить прогресс и поставить продолжение.
        $chunkTimeSec = max(600, (int) ($params['chunk_time_sec'] ?? 2700));
        $chunkStartedAt = time();
        // Cancel-poll: не SELECT на каждый бар (было узкое место на тяжёлых ботах).
        $cancelCheckEveryBars = 50;

        $run->update([
            'status' => BotBacktestRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ]);

        $allTradesBuffer = [];
        // Resume: не обнуляем счётчики баров.
        $barsTotal = (int) $run->bars_total;
        $barsDone = (int) $run->bars_done;
        $instrumentCount = count($instruments);

        try {
            foreach ($instruments as $idx => $instrument) {
                $instrument = (string) $instrument;
                if (isset($doneSet[$instrument])) {
                    continue;
                }

                // min_payout из формы (0 = все пары): как live — пара ниже порога не участвует.
                if ($minPayout > 0) {
                    $rowPayout = $currentPayoutByInstrument->get($instrument);
                    $currentPayout = $rowPayout !== null ? (float) $rowPayout : null;
                    if ($currentPayout === null || $currentPayout < $minPayout) {
                        $this->markInstrumentDone($run, $params, $doneList, $doneSet, $instrument);
                        $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);

                        continue;
                    }
                }

                // Warmup: грузим lookback баров ДО from_ts, иначе первые сигналы «холодные».
                $warmupFrom = $this->estimateWarmupFromTs($run->from_ts, $seriesTf, $lookback);
                $series = $this->botData->closedSeriesInRange(
                    $instrument,
                    $seriesTf,
                    $warmupFrom,
                    $run->to_ts,
                    null,
                );

                if (count($series) < $minClosedBars) {
                    $this->markInstrumentDone($run, $params, $doneList, $doneSet, $instrument);
                    $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);

                    continue;
                }

                // Индексы баров, попадающих в [from_ts, to_ts] — только их считаем «торговыми».
                $tradeableIndexes = [];
                foreach ($series as $i => $bar) {
                    $t = (int) $bar['time'];
                    if ($t >= $run->from_ts && $t <= $run->to_ts) {
                        $tradeableIndexes[] = $i;
                    }
                }
                $barsTotal += count($tradeableIndexes);

                // MTF в RAM: закрытые HTF к close M1. Impulse LVL достраивает текущий бакет из M1 в Levels.
                $mtfCache = $needsMtf
                    ? $this->preloadMtfSeries($runtime, (string) $bot->type, $instrument, $warmupFrom, (int) $run->to_ts)
                    : null;
                $mtfCursor = $mtfCache !== null
                    ? array_fill_keys(array_keys($mtfCache['series']), -1)
                    : [];

                $lastDedupeBucket = null;
                $busyUntilTs = 0;
                $barsSinceCancelCheck = 0;

                foreach ($tradeableIndexes as $i) {
                    // Редкий опрос cancel: раз в N баров достаточно для UI «Отменить».
                    if (++$barsSinceCancelCheck >= $cancelCheckEveryBars) {
                        $barsSinceCancelCheck = 0;
                        if ($run->fresh()?->status === BotBacktestRun::STATUS_CANCELLED) {
                            if ($allTradesBuffer !== []) {
                                $this->flushTrades($allTradesBuffer);
                            }

                            return 'cancelled';
                        }
                    }

                    $bar = $series[$i];
                    $barTs = (int) $bar['time'];
                    // Сразу окно lookback (без slice всей истории + второго trim).
                    $winFrom = max(0, $i + 1 - $lookback);
                    $closedWindow = array_slice($series, $winFrom, $i + 1 - $winFrom);
                    if (count($closedWindow) < $minClosedBars) {
                        $barsDone++;
                        continue;
                    }

                    if ($oneAtATime && $barTs < $busyUntilTs) {
                        $barsDone++;
                        continue;
                    }

                    // MTF без look-ahead: только HTF, которые уже закрылись к close этого M1.
                    $mtf = $mtfCache !== null
                        ? $this->mtfSliceUntil($mtfCache, $mtfCursor, $barTs, $periodSec)
                        : [];
                    // now_utc — для фильтров trade hours в стратегиях (не Carbon::now()).
                    $context = [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        // 0 в форме = «все пары»; confidence не штрафуем нулевым payout.
                        'payout' => $payout > 0 ? $payout : null,
                        'config' => $runtime['config'],
                        'mtf' => $mtf,
                        'now_ts' => $barTs,
                        'now_utc' => \Illuminate\Support\Carbon::createFromTimestampUTC($barTs),
                    ];
                    if ($smcParams !== null) {
                        $context['smc_params'] = $smcParams;
                    }
                    if ($paParams !== null) {
                        $context['pa_params'] = $paParams;
                    }

                    $decision = $strategy->analyze($closedWindow, null, $context);
                    if (! $decision) {
                        $barsDone++;
                        continue;
                    }

                    $confidence = (float) ($decision['confidence'] ?? 0);
                    if ($confidence < $minConfidence) {
                        $barsDone++;
                        continue;
                    }

                    $side = strtoupper((string) ($decision['side'] ?? ''));
                    if ($side !== 'CALL' && $side !== 'PUT') {
                        $barsDone++;
                        continue;
                    }

                    $expirySec = $this->resolveExpirySec($bot->type, $defaultExpirySec, $decision);

                    if ($simulateDedupe) {
                        $bucket = intdiv($barTs, max(1, $dedupeBucketSec));
                        $dedupeKey = $instrument.'|'.$side.'|'.$bucket;
                        if ($lastDedupeBucket === $dedupeKey) {
                            $barsDone++;
                            continue;
                        }
                        $lastDedupeBucket = $dedupeKey;
                    }

                    // Вход после закрытия бара: timestamp конца бакета.
                    $entryTs = $barTs + $periodSec;
                    $entryPrice = (float) $bar['close'];
                    $expiryTs = $entryTs + $expirySec;

                    $expiryPrice = $this->settlement->marketPriceAtExpiry($instrument, $expiryTs);
                    if ($expiryPrice === null) {
                        // Нет свечи на expiry — пропускаем сделку, не считаем draw (честнее для WR).
                        $barsDone++;
                        continue;
                    }

                    $result = $this->result($side, $entryPrice, $expiryPrice);
                    $pnl = match ($result) {
                        BotTrade::RESULT_WIN => round($payout / 100, 4),
                        BotTrade::RESULT_LOSS => -1.0,
                        default => 0.0,
                    };

                    $allTradesBuffer[] = [
                        'bot_backtest_run_id' => $run->id,
                        'instrument' => $instrument,
                        'side' => $side,
                        'result' => $result,
                        'signal_ts' => $barTs,
                        'entry_ts' => $entryTs,
                        'expiry_ts' => $expiryTs,
                        'expiry_sec' => $expirySec,
                        'entry_price' => $entryPrice,
                        'expiry_price' => $expiryPrice,
                        'payout_snapshot' => $payout,
                        'pnl' => $pnl,
                        'confidence' => $confidence,
                        'decision_json' => $decision,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Dual DeMarker: на LOSS колена 1 сразу добавляем колено 2 (без нового сигнала).
                    if ($result === BotTrade::RESULT_LOSS) {
                        $knee2 = app(BotSignalKneeFollowUpService::class)->nextKneeBacktestRow(
                            end($allTradesBuffer),
                            $payout
                        );
                        if ($knee2 !== null) {
                            $k2ExpiryPrice = $this->settlement->marketPriceAtExpiry(
                                $instrument,
                                (int) $knee2['expiry_ts']
                            );
                            if ($k2ExpiryPrice !== null) {
                                $k2Result = $this->result($side, (float) $knee2['entry_price'], $k2ExpiryPrice);
                                $k2Pnl = match ($k2Result) {
                                    BotTrade::RESULT_WIN => round($payout / 100, 4),
                                    BotTrade::RESULT_LOSS => -1.0,
                                    default => 0.0,
                                };
                                $allTradesBuffer[] = [
                                    'bot_backtest_run_id' => $run->id,
                                    'instrument' => $instrument,
                                    'side' => $side,
                                    'result' => $k2Result,
                                    'signal_ts' => (int) $knee2['signal_ts'],
                                    'entry_ts' => (int) $knee2['entry_ts'],
                                    'expiry_ts' => (int) $knee2['expiry_ts'],
                                    'expiry_sec' => (int) $knee2['expiry_sec'],
                                    'entry_price' => (float) $knee2['entry_price'],
                                    'expiry_price' => $k2ExpiryPrice,
                                    'payout_snapshot' => $payout,
                                    'pnl' => $k2Pnl,
                                    'confidence' => (float) $knee2['confidence'],
                                    'decision_json' => $knee2['decision_json'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                                if ($oneAtATime) {
                                    $busyUntilTs = (int) $knee2['expiry_ts'];
                                }
                            }
                        }
                    }

                    if ($oneAtATime) {
                        $busyUntilTs = max($busyUntilTs, $expiryTs);
                    }

                    $barsDone++;
                    // Периодический flush + progress (не держим 10k строк в RAM зря).
                    if (count($allTradesBuffer) >= 200) {
                        $this->flushTrades($allTradesBuffer);
                        $allTradesBuffer = [];
                        $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);
                    }
                }

                if ($allTradesBuffer !== []) {
                    $this->flushTrades($allTradesBuffer);
                    $allTradesBuffer = [];
                }
                $this->markInstrumentDone($run, $params, $doneList, $doneSet, $instrument);
                $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);
                // Для длинных batch-прогонов: прогресс по парам в STDERR (иначе лог молчит часами).
                if (getenv('BACKTEST_INSTRUMENT_LOG')) {
                    $doneN = count($doneList);
                    fwrite(STDERR, sprintf(
                        "[bt] bot_id=%d pair %d/%d %s trades=%d\n",
                        (int) $run->bot_id,
                        $doneN,
                        $instrumentCount,
                        $instrument,
                        (int) $run->trades_total
                    ));
                }

                // Chunk: не упираемся в job timeout — ставим продолжение на оставшиеся пары.
                $remaining = false;
                for ($j = $idx + 1; $j < $instrumentCount; $j++) {
                    if (! isset($doneSet[(string) $instruments[$j]])) {
                        $remaining = true;
                        break;
                    }
                }
                if ($remaining && (time() - $chunkStartedAt) >= $chunkTimeSec) {
                    $run->update([
                        'status' => BotBacktestRun::STATUS_QUEUED,
                        'error_message' => null,
                        'bars_done' => $barsDone,
                        'bars_total' => $barsTotal,
                    ]);

                    return 'continue';
                }
            }

            if ($allTradesBuffer !== []) {
                $this->flushTrades($allTradesBuffer);
            }

            $summary = $this->buildSummary($run);
            $run->update([
                'status' => BotBacktestRun::STATUS_COMPLETED,
                'progress_pct' => 100,
                'bars_total' => $barsTotal,
                'bars_done' => $barsDone,
                'trades_total' => (int) ($summary['trades'] ?? 0),
                'summary_json' => $summary,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            return 'completed';
        } catch (\Throwable $e) {
            if ($allTradesBuffer !== []) {
                $this->flushTrades($allTradesBuffer);
            }
            $this->fail($run, $e->getMessage());

            return 'failed';
        }
    }

    /**
     * Фиксируем пару как обработанную в params_json.instruments_done (для resume).
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>  $doneList
     * @param  array<string, true>  $doneSet
     */
    private function markInstrumentDone(
        BotBacktestRun $run,
        array &$params,
        array &$doneList,
        array &$doneSet,
        string $instrument,
    ): void {
        if (isset($doneSet[$instrument])) {
            return;
        }
        $doneList[] = $instrument;
        $doneSet[$instrument] = true;
        $params['instruments_done'] = $doneList;
        $run->params_json = $params;
        $run->save();
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function flushTrades(array $rows): void
    {
        // decision_json — array; insert нужен json-string.
        $encoded = array_map(static function (array $row): array {
            if (isset($row['decision_json']) && is_array($row['decision_json'])) {
                $row['decision_json'] = json_encode($row['decision_json'], JSON_UNESCAPED_UNICODE);
            }

            return $row;
        }, $rows);

        BotBacktestTrade::query()->insert($encoded);
    }

    /** @return array<string, mixed> */
    public function buildSummary(BotBacktestRun $run): array
    {
        $trades = BotBacktestTrade::query()
            ->where('bot_backtest_run_id', $run->id)
            ->orderBy('entry_ts')
            ->get(['result', 'pnl', 'instrument', 'entry_ts', 'side']);

        $wins = 0;
        $losses = 0;
        $draws = 0;
        $pnlSum = 0.0;
        $equity = 0.0;
        $peak = 0.0;
        $maxDd = 0.0;
        $byInstrument = [];
        $equityCurve = [];
        // Серии подряд: draw обрывает обе; считаем только win/loss.
        $curWinStreak = 0;
        $curLossStreak = 0;
        $maxWinStreak = 0;
        $maxLossStreak = 0;
        // Распределение: сколько раз завершилась серия ровно N побед / поражений (глобально).
        /** @var array<int, int> $globalWinStreakDist */
        $globalWinStreakDist = [];
        /** @var array<int, int> $globalLossStreakDist */
        $globalLossStreakDist = [];

        foreach ($trades as $t) {
            $pnl = (float) ($t->pnl ?? 0);
            $pnlSum += $pnl;
            $equity += $pnl;
            $peak = max($peak, $equity);
            $dd = $peak - $equity;
            $maxDd = max($maxDd, $dd);

            match ($t->result) {
                BotTrade::RESULT_WIN => $wins++,
                BotTrade::RESULT_LOSS => $losses++,
                default => $draws++,
            };

            // Глобальная серия: все пары в порядке entry_ts.
            $this->applyTradeStreak(
                (string) $t->result,
                $curWinStreak,
                $curLossStreak,
                $maxWinStreak,
                $maxLossStreak,
                $globalWinStreakDist,
                $globalLossStreakDist,
            );

            $inst = (string) $t->instrument;
            if (! isset($byInstrument[$inst])) {
                $byInstrument[$inst] = [
                    'trades' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'pnl' => 0.0,
                    'winrate' => null,
                    // Серии по этой паре (хронология entry_ts, draw обрывает).
                    'cur_win_streak' => 0,
                    'cur_loss_streak' => 0,
                    'max_win_streak' => 0,
                    'max_loss_streak' => 0,
                    // Распределение длин серий только внутри этой пары.
                    'win_streak_dist' => [],
                    'loss_streak_dist' => [],
                ];
            }
            $byInstrument[$inst]['trades']++;
            $byInstrument[$inst]['pnl'] += $pnl;
            if ($t->result === BotTrade::RESULT_WIN) {
                $byInstrument[$inst]['wins']++;
            } elseif ($t->result === BotTrade::RESULT_LOSS) {
                $byInstrument[$inst]['losses']++;
            } else {
                $byInstrument[$inst]['draws']++;
            }
            /** @var array<int, int> $instWinDist */
            $instWinDist = $byInstrument[$inst]['win_streak_dist'];
            /** @var array<int, int> $instLossDist */
            $instLossDist = $byInstrument[$inst]['loss_streak_dist'];
            $instCurWin = (int) $byInstrument[$inst]['cur_win_streak'];
            $instCurLoss = (int) $byInstrument[$inst]['cur_loss_streak'];
            $instMaxWin = (int) $byInstrument[$inst]['max_win_streak'];
            $instMaxLoss = (int) $byInstrument[$inst]['max_loss_streak'];
            $this->applyTradeStreak(
                (string) $t->result,
                $instCurWin,
                $instCurLoss,
                $instMaxWin,
                $instMaxLoss,
                $instWinDist,
                $instLossDist,
            );
            $byInstrument[$inst]['cur_win_streak'] = $instCurWin;
            $byInstrument[$inst]['cur_loss_streak'] = $instCurLoss;
            $byInstrument[$inst]['max_win_streak'] = $instMaxWin;
            $byInstrument[$inst]['max_loss_streak'] = $instMaxLoss;
            $byInstrument[$inst]['win_streak_dist'] = $instWinDist;
            $byInstrument[$inst]['loss_streak_dist'] = $instLossDist;

            // Точка equity на каждую сделку — ниже прореживаем перед записью в JSON.
            $equityCurve[] = ['ts' => (int) $t->entry_ts, 'equity' => round($equity, 4)];
        }

        // Незавершённые серии в конце прогона тоже попадают в распределение.
        $this->flushStreakDistributions($curWinStreak, $curLossStreak, $globalWinStreakDist, $globalLossStreakDist);

        // Досчёт WR и округление PnL; убираем временные cur_* из ответа.
        foreach ($byInstrument as $inst => $row) {
            /** @var array<int, int> $pairWinDist */
            $pairWinDist = is_array($row['win_streak_dist'] ?? null) ? $row['win_streak_dist'] : [];
            /** @var array<int, int> $pairLossDist */
            $pairLossDist = is_array($row['loss_streak_dist'] ?? null) ? $row['loss_streak_dist'] : [];
            $this->flushStreakDistributions(
                (int) ($row['cur_win_streak'] ?? 0),
                (int) ($row['cur_loss_streak'] ?? 0),
                $pairWinDist,
                $pairLossDist,
            );

            $dec = (int) $row['wins'] + (int) $row['losses'];
            $byInstrument[$inst]['pnl'] = round((float) $row['pnl'], 4);
            $byInstrument[$inst]['winrate'] = $dec > 0
                ? round(((int) $row['wins']) / $dec, 4)
                : null;
            $byInstrument[$inst]['max_win_streak'] = (int) ($row['max_win_streak'] ?? 0);
            $byInstrument[$inst]['max_loss_streak'] = (int) ($row['max_loss_streak'] ?? 0);
            $byInstrument[$inst]['streak_distribution'] = [
                'wins' => $this->normalizeStreakDistribution($pairWinDist),
                'losses' => $this->normalizeStreakDistribution($pairLossDist),
            ];
            unset(
                $byInstrument[$inst]['cur_win_streak'],
                $byInstrument[$inst]['cur_loss_streak'],
                $byInstrument[$inst]['win_streak_dist'],
                $byInstrument[$inst]['loss_streak_dist'],
            );
        }
        uasort($byInstrument, static fn ($a, $b) => ((float) $b['pnl']) <=> ((float) $a['pnl']));

        $decided = $wins + $losses;
        $grossWin = (float) $trades->where('result', BotTrade::RESULT_WIN)->sum('pnl');
        $grossLossAbs = abs((float) $trades->where('result', BotTrade::RESULT_LOSS)->sum('pnl'));

        return [
            'trades' => $trades->count(),
            'wins' => $wins,
            'losses' => $losses,
            'draws' => $draws,
            'winrate' => $decided > 0 ? round($wins / $decided, 4) : null,
            'pnl_units' => round($pnlSum, 4),
            'max_drawdown_units' => round($maxDd, 4),
            'profit_factor' => $grossLossAbs > 0 ? round($grossWin / $grossLossAbs, 4) : ($grossWin > 0 ? null : 0.0),
            // Макс. подряд плюсов / минусов (draw обрывает серию).
            'max_win_streak' => $maxWinStreak,
            'max_loss_streak' => $maxLossStreak,
            // Сколько раз завершилась серия ровно N побед / поражений (все пары, entry_ts).
            'streak_distribution' => [
                'wins' => $this->normalizeStreakDistribution($globalWinStreakDist),
                'losses' => $this->normalizeStreakDistribution($globalLossStreakDist),
            ],
            'by_instrument' => $byInstrument,
            // Лимит точек: иначе summary_json раздувается и ломает SELECT списка (MySQL sort memory).
            'equity_curve' => $this->downsampleEquityCurve($equityCurve, 400),
        ];
    }

    /**
     * Обновить текущие серии и распределение после одной сделки.
     * При смене результата закрытая серия записывается: distribution[length]++.
     *
     * @param  array<int, int>  $winDist
     * @param  array<int, int>  $lossDist
     */
    private function applyTradeStreak(
        string $result,
        int &$curWinStreak,
        int &$curLossStreak,
        int &$maxWinStreak,
        int &$maxLossStreak,
        array &$winDist,
        array &$lossDist,
    ): void {
        if ($result === BotTrade::RESULT_WIN) {
            if ($curLossStreak > 0) {
                $this->recordStreakLength($lossDist, $curLossStreak);
                $curLossStreak = 0;
            }
            $curWinStreak++;
            $maxWinStreak = max($maxWinStreak, $curWinStreak);
        } elseif ($result === BotTrade::RESULT_LOSS) {
            if ($curWinStreak > 0) {
                $this->recordStreakLength($winDist, $curWinStreak);
                $curWinStreak = 0;
            }
            $curLossStreak++;
            $maxLossStreak = max($maxLossStreak, $curLossStreak);
        } else {
            if ($curWinStreak > 0) {
                $this->recordStreakLength($winDist, $curWinStreak);
            }
            if ($curLossStreak > 0) {
                $this->recordStreakLength($lossDist, $curLossStreak);
            }
            $curWinStreak = 0;
            $curLossStreak = 0;
        }
    }

    /** @param  array<int, int>  $dist */
    private function recordStreakLength(array &$dist, int $length): void
    {
        if ($length <= 0) {
            return;
        }
        $dist[$length] = ($dist[$length] ?? 0) + 1;
    }

    /**
     * Закрыть незавершённые серии в конце прогона (последняя серия без «обрыва»).
     *
     * @param  array<int, int>  $winDist
     * @param  array<int, int>  $lossDist
     */
    private function flushStreakDistributions(
        int $curWinStreak,
        int $curLossStreak,
        array &$winDist,
        array &$lossDist,
    ): void {
        if ($curWinStreak > 0) {
            $this->recordStreakLength($winDist, $curWinStreak);
        }
        if ($curLossStreak > 0) {
            $this->recordStreakLength($lossDist, $curLossStreak);
        }
    }

    /**
     * @param  array<int, int>  $dist
     * @return array<int, int>
     */
    private function normalizeStreakDistribution(array $dist): array
    {
        $out = [];
        foreach ($dist as $len => $cnt) {
            $length = (int) $len;
            $count = (int) $cnt;
            if ($length > 0 && $count > 0) {
                $out[$length] = $count;
            }
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * Равномерно проредить equity до maxPoints (первая и последняя точки всегда сохраняются).
     *
     * @param  list<array{ts:int,equity:float}>  $curve
     * @return list<array{ts:int,equity:float}>
     */
    private function downsampleEquityCurve(array $curve, int $maxPoints): array
    {
        $n = count($curve);
        if ($n <= $maxPoints || $maxPoints < 2) {
            return $curve;
        }

        $out = [];
        $prevIdx = null;
        $lastIdx = $n - 1;
        for ($i = 0; $i < $maxPoints; $i++) {
            $idx = (int) round(($i / ($maxPoints - 1)) * $lastIdx);
            // Только схлопываем повтор одного и того же индекса после round — ts у сделок может совпадать.
            if ($prevIdx !== null && $idx === $prevIdx) {
                continue;
            }
            $out[] = $curve[$idx];
            $prevIdx = $idx;
        }

        return $out !== [] ? $out : $curve;
    }

    private function fail(BotBacktestRun $run, string $message): void
    {
        $run->update([
            'status' => BotBacktestRun::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);
    }

    private function touchProgress(
        BotBacktestRun $run,
        int $instrumentIdx,
        int $instrumentCount,
        int $barsDone,
        int $barsTotal,
    ): void {
        // Прогресс: смесь по парам и по барам.
        $byInstr = $instrumentCount > 0 ? (($instrumentIdx + 1) / $instrumentCount) * 100 : 0;
        $byBars = $barsTotal > 0 ? ($barsDone / $barsTotal) * 100 : $byInstr;
        $pct = (int) max(0, min(99, round(($byInstr * 0.3) + ($byBars * 0.7))));

        $run->update([
            'progress_pct' => $pct,
            'bars_done' => $barsDone,
            'bars_total' => $barsTotal,
            'trades_total' => BotBacktestTrade::query()->where('bot_backtest_run_id', $run->id)->count(),
        ]);
    }

    /**
     * Сколько секунд назад взять warmup под lookback баров данного ТФ.
     */
    private function estimateWarmupFromTs(int $fromTs, int $timeframe, int $lookback): int
    {
        $period = $this->periodSeconds($timeframe);
        // +20% запас на дыры в истории.
        $sec = (int) ceil($lookback * $period * 1.2);

        return max(0, $fromTs - $sec);
    }

    private function periodSeconds(int $timeframe): int
    {
        return \App\Services\CandleService::periodSecondsForDashboardTf($timeframe) ?? 60;
    }

    /**
     * Загрузить M5/M15/H1 один раз на пару (весь диапазон прогона + запас под take).
     * Раньше на КАЖДЫЙ M1-бар было 3 SQL — главный тормоз CRC/Strong Level.
     *
     * @param  array<string,mixed>  $runtime
     * @return array{series: array<string, list<array<string,mixed>>>, take: array<string,int>}
     */
    private function preloadMtfSeries(array $runtime, string $botType, string $instrument, int $fromTs, int $toTs): array
    {
        $cfg = is_array($runtime['config'] ?? null) ? $runtime['config'] : [];
        $map = \App\Support\ImpulseLvl1minConfig::is($botType)
            ? [
                '5' => (int) ($cfg['m5_take'] ?? 80),
                '15' => (int) ($cfg['m15_take'] ?? 60),
                '30' => (int) ($cfg['m30_take'] ?? 40),
            ]
            : [
                '5' => (int) ($cfg['m5_take'] ?? 120),
                '15' => (int) ($cfg['m15_take'] ?? 80),
                '60' => (int) ($cfg['h1_take'] ?? 12),
            ];
        $series = [];
        foreach ($map as $tfKey => $take) {
            $tf = (int) $tfKey;
            // Берём с запасом lookback MTF до fromTs, чтобы первые бары периода тоже «тёплые».
            $mtfWarmup = $this->estimateWarmupFromTs($fromTs, $tf, max($take, 20));
            $rows = $this->botData->closedSeriesInRange($instrument, $tf, $mtfWarmup, $toTs, null);
            $series[$tfKey] = array_map(static fn (array $c) => [
                'time' => (int) $c['time'],
                'open' => (float) $c['open'],
                'high' => (float) $c['high'],
                'low' => (float) $c['low'],
                'close' => (float) $c['close'],
                'volume' => (float) ($c['volume'] ?? 0),
            ], $rows);
        }

        return ['series' => $series, 'take' => $map];
    }

    /**
     * Срез MTF без look-ahead: бары, которые уже закрылись к close текущего бара серии.
     * time <= lastTs недостаточно: M5 12:00 содержит OHLC до 12:05, а на M1 12:02
     * этой информации ещё нет. См. BotDataService::sliceClosedMtfUntil.
     *
     * @param  array{series: array<string, list<array<string,mixed>>>, take: array<string,int>}  $mtfCache
     * @param  array<string, int>  $cursor  индекс последнего включённого закрытого бара по ТФ (по ссылке)
     * @return array<string, list<array<string,mixed>>>
     */
    private function mtfSliceUntil(array $mtfCache, array &$cursor, int $lastTs, int $seriesPeriodSec): array
    {
        return BotDataService::sliceClosedMtfUntil(
            $mtfCache['series'],
            $mtfCache['take'],
            $cursor,
            $lastTs,
            $seriesPeriodSec,
        );
    }

    /** @param  array<string,mixed>  $decision */
    private function resolveExpirySec(string $botType, int $default, array $decision): int
    {
        if (in_array($botType, ['smc_flow', 'strong_level', 'candle_reversal_confirm'], true) && isset($decision['expiry_sec'])) {
            return max(120, min(600, (int) $decision['expiry_sec']));
        }
        if ($botType === 'sweep_raid_liquidity' && isset($decision['expiry_sec'])) {
            return max(60, min(900, (int) $decision['expiry_sec']));
        }
        if ($botType === 'engulfing_liquidity' && isset($decision['expiry_sec'])) {
            return max(60, min(900, (int) $decision['expiry_sec']));
        }

        return max(5, $default);
    }

    private function result(string $side, float $entry, float $expiry): string
    {
        if ($side === 'CALL') {
            return $expiry > $entry
                ? BotTrade::RESULT_WIN
                : ($expiry < $entry ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
        }

        return $expiry < $entry
            ? BotTrade::RESULT_WIN
            : ($expiry > $entry ? BotTrade::RESULT_LOSS : BotTrade::RESULT_DRAW);
    }

    /** @return list<string> */
    public static function unsupportedTypes(): array
    {
        return self::UNSUPPORTED_TYPES;
    }
}
