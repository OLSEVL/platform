<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotBacktestRun;
use App\Models\BotBacktestTrade;
use App\Models\BotTrade;
use App\Models\IndicatorSetting;
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

        $runParams = [
            'instruments' => array_values(array_filter(
                array_map('strval', $params['instruments'] ?? []),
                static fn (string $s) => $s !== ''
            )),
            // Исторического payout нет → фиксируем для PnL (как paper-юнит).
            'payout' => (float) ($params['payout'] ?? 80.0),
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

    /** Основной цикл replay. Вызывается из Job. */
    public function execute(BotBacktestRun $run): void
    {
        $run->refresh();
        if ($run->status === BotBacktestRun::STATUS_CANCELLED) {
            return;
        }

        $bot = $run->bot;
        if (! $bot) {
            $this->fail($run, 'Бот не найден.');

            return;
        }

        $strategy = $this->engine->strategyForType($bot->type);
        if (! $strategy) {
            $this->fail($run, "Стратегия для типа «{$bot->type}» не найдена.");

            return;
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

            return;
        }

        $payout = (float) ($params['payout'] ?? 80.0);
        $simulateDedupe = (bool) ($params['simulate_dedupe'] ?? true);
        $oneAtATime = (bool) ($params['one_trade_at_a_time'] ?? true);
        $dedupeBucketSec = (int) $runtime['dedupe_bucket_sec'];
        $minConfidence = (float) $runtime['min_confidence'];
        $minClosedBars = (int) $runtime['min_closed_bars'];
        $lookback = (int) $runtime['count'];
        $seriesTf = (int) $runtime['series_timeframe'];
        $defaultExpirySec = (int) $runtime['expiry_sec'];

        $run->update([
            'status' => BotBacktestRun::STATUS_RUNNING,
            'started_at' => now(),
            'progress_pct' => 0,
            'error_message' => null,
        ]);

        $allTradesBuffer = [];
        $barsTotal = 0;
        $barsDone = 0;
        $instrumentCount = count($instruments);

        try {
            foreach ($instruments as $idx => $instrument) {
                $instrument = (string) $instrument;
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
                    $barsDone += 0;
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

                $lastDedupeBucket = null;
                $busyUntilTs = 0;

                foreach ($tradeableIndexes as $i) {
                    if ($run->fresh()?->status === BotBacktestRun::STATUS_CANCELLED) {
                        return;
                    }

                    $bar = $series[$i];
                    $barTs = (int) $bar['time'];
                    $closedWindow = array_slice($series, 0, $i + 1);
                    // Ограничиваем окно lookback как в live (не тащим всю историю в analyze).
                    if (count($closedWindow) > $lookback) {
                        $closedWindow = array_slice($closedWindow, -$lookback);
                    }
                    if (count($closedWindow) < $minClosedBars) {
                        $barsDone++;
                        continue;
                    }

                    if ($oneAtATime && $barTs < $busyUntilTs) {
                        $barsDone++;
                        continue;
                    }

                    $mtf = $this->buildMtfIfNeeded($botClone, $runtime, $instrument, $closedWindow);
                    // now_utc — для фильтров trade hours в стратегиях (не Carbon::now()).
                    $context = [
                        'bot_id' => $bot->id,
                        'instrument' => $instrument,
                        'payout' => $payout,
                        'config' => $runtime['config'],
                        'mtf' => $mtf,
                        'now_ts' => $barTs,
                        'now_utc' => \Illuminate\Support\Carbon::createFromTimestampUTC($barTs),
                    ];
                    if ($bot->type === 'smc_flow') {
                        $context['smc_params'] = IndicatorSetting::mergedSmcFlowParams($botClone);
                    }
                    if ($bot->type === 'strong_level' || $bot->type === 'candle_reversal_confirm') {
                        $context['pa_params'] = IndicatorSetting::mergedPriceActionDgtParams($botClone);
                        $context['smc_params'] = IndicatorSetting::mergedSmcFlowParams($botClone);
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
                    $periodSec = $this->periodSeconds($seriesTf);
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

                    if ($oneAtATime) {
                        $busyUntilTs = $expiryTs;
                    }

                    $barsDone++;
                    // Периодический flush + progress (не держим 10k строк в RAM зря).
                    if (count($allTradesBuffer) >= 200) {
                        $this->flushTrades($allTradesBuffer);
                        $allTradesBuffer = [];
                        $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);
                    }
                }

                $this->touchProgress($run, $idx, $instrumentCount, $barsDone, $barsTotal);
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
            ]);
        } catch (\Throwable $e) {
            if ($allTradesBuffer !== []) {
                $this->flushTrades($allTradesBuffer);
            }
            $this->fail($run, $e->getMessage());
        }
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

            if ($t->result === BotTrade::RESULT_WIN) {
                $curWinStreak++;
                $curLossStreak = 0;
                $maxWinStreak = max($maxWinStreak, $curWinStreak);
            } elseif ($t->result === BotTrade::RESULT_LOSS) {
                $curLossStreak++;
                $curWinStreak = 0;
                $maxLossStreak = max($maxLossStreak, $curLossStreak);
            } else {
                $curWinStreak = 0;
                $curLossStreak = 0;
            }

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
                ];
            }
            $byInstrument[$inst]['trades']++;
            $byInstrument[$inst]['pnl'] += $pnl;
            if ($t->result === BotTrade::RESULT_WIN) {
                $byInstrument[$inst]['wins']++;
                $byInstrument[$inst]['cur_win_streak']++;
                $byInstrument[$inst]['cur_loss_streak'] = 0;
                $byInstrument[$inst]['max_win_streak'] = max(
                    (int) $byInstrument[$inst]['max_win_streak'],
                    (int) $byInstrument[$inst]['cur_win_streak']
                );
            } elseif ($t->result === BotTrade::RESULT_LOSS) {
                $byInstrument[$inst]['losses']++;
                $byInstrument[$inst]['cur_loss_streak']++;
                $byInstrument[$inst]['cur_win_streak'] = 0;
                $byInstrument[$inst]['max_loss_streak'] = max(
                    (int) $byInstrument[$inst]['max_loss_streak'],
                    (int) $byInstrument[$inst]['cur_loss_streak']
                );
            } else {
                $byInstrument[$inst]['draws']++;
                $byInstrument[$inst]['cur_win_streak'] = 0;
                $byInstrument[$inst]['cur_loss_streak'] = 0;
            }

            // Редкая точка equity (каждая сделка) — UI сам проредит при большом N.
            $equityCurve[] = ['ts' => (int) $t->entry_ts, 'equity' => round($equity, 4)];
        }

        // Досчёт WR и округление PnL; убираем временные cur_* из ответа.
        foreach ($byInstrument as $inst => $row) {
            $dec = (int) $row['wins'] + (int) $row['losses'];
            $byInstrument[$inst]['pnl'] = round((float) $row['pnl'], 4);
            $byInstrument[$inst]['winrate'] = $dec > 0
                ? round(((int) $row['wins']) / $dec, 4)
                : null;
            $byInstrument[$inst]['max_win_streak'] = (int) ($row['max_win_streak'] ?? 0);
            $byInstrument[$inst]['max_loss_streak'] = (int) ($row['max_loss_streak'] ?? 0);
            unset($byInstrument[$inst]['cur_win_streak'], $byInstrument[$inst]['cur_loss_streak']);
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
            'by_instrument' => $byInstrument,
            'equity_curve' => $equityCurve,
        ];
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
     * @param  array<string,mixed>  $runtime
     * @param  list<array<string,mixed>>  $closedWindow
     * @return array<string, list<array<string,mixed>>>
     */
    private function buildMtfIfNeeded(Bot $bot, array $runtime, string $instrument, array $closedWindow): array
    {
        // MVP: MTF для CRC/strong_level строим из тех же S5 через BotDataService на «конец окна».
        // Полный бар-за-баром MTF дорогой — для точности v2 можно кэшировать prefix-агрегаты.
        if (! in_array($bot->type, ['candle_reversal_confirm', 'strong_level'], true)) {
            return [];
        }

        $cfg = $runtime['config'];
        $lastTs = (int) ($closedWindow[array_key_last($closedWindow)]['time'] ?? 0);
        if ($lastTs <= 0) {
            return [];
        }

        // Берём MTF «как сейчас на lastTs»: seriesBundle с before = lastTs+1.
        $map = [
            5 => (int) ($cfg['m5_take'] ?? 120),
            15 => (int) ($cfg['m15_take'] ?? 80),
            60 => (int) ($cfg['h1_take'] ?? 12),
        ];
        $mtf = [];
        foreach ($map as $tf => $take) {
            $rows = $this->botData->closedSeriesInRange($instrument, (int) $tf, 0, $lastTs, $take);
            $mtf[(string) $tf] = array_map(static fn (array $c) => [
                'time' => (int) $c['time'],
                'open' => (float) $c['open'],
                'high' => (float) $c['high'],
                'low' => (float) $c['low'],
                'close' => (float) $c['close'],
                'volume' => (float) ($c['volume'] ?? 0),
            ], $rows);
        }

        return $mtf;
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
