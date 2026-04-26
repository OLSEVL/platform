<?php

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotSignal;
use App\Models\BotTrade;
use App\Models\PocketInstrument;
use App\Services\Bots\Strategies\BotStrategyInterface;
use App\Services\Bots\Strategies\FeshanStrategy;
use App\Services\Bots\Strategies\ThreeCandlesInRowStrategy;
use Illuminate\Support\Facades\DB;

class BotEngineService
{
    public function __construct(
        private readonly BotDataService $botDataService,
    ) {}

    public function scanBot(Bot $bot): array
    {
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
        foreach ($instruments as $instrumentRow) {
            $instrument = (string) $instrumentRow->instrument;
            if ($allowedInstrumentSet !== [] && ! isset($allowedInstrumentSet[$instrument])) {
                continue;
            }
            if (isset($blockedInstrumentSet[$instrument])) {
                continue;
            }
            $processed++;
            // Профессиональный риск-фильтр по экономике сделки:
            // если текущий payout ниже порога, математическое ожидание становится слишком слабым,
            // поэтому такой инструмент пропускаем до любого технического анализа.
            $currentPayout = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;
            if ($currentPayout === null || $currentPayout < $minPayout) {
                continue;
            }

            $series = $this->botDataService->series($instrument, $count, true, $staleAfterSec);
            $closed = $series['closed'];
            if (count($closed) < $minClosedBars) {
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
            }
            $decision = $strategy->analyze($closed, $partial, [
                'instrument' => $instrument,
                'payout' => $currentPayout,
                'config' => $config,
                'mtf' => $mtf,
            ]);
            if (! $decision) {
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
                continue;
            }

            $side = (string) ($decision['side'] ?? '');
            if (! in_array($side, ['CALL', 'PUT'], true)) {
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
                continue;
            }

            $hasOpenTrade = BotTrade::query()
                ->where('bot_id', $bot->id)
                ->where('instrument', $instrument)
                ->where('status', BotTrade::STATUS_OPEN)
                ->exists();
            if ($hasOpenTrade) {
                continue;
            }

            $entryPrice = (float) (($partial['close'] ?? null) ?? end($closed)['close']);
            // БД-сессия MySQL работает в UTC (config/database.php: SET time_zone='+00:00').
            // Поэтому signal_at/entry_time/expiry_time фиксируем тоже в UTC, чтобы не получать
            // искусственный +3 часа в raw TIMESTAMP полях.
            $entryAt = now();
            $expiryAt = $entryAt->copy()->addSeconds($decisionExpirySec);
            $payoutSnapshot = $instrumentRow->current_payout !== null ? (float) $instrumentRow->current_payout : null;

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
                &$trades
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

                BotTrade::query()->create([
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
                        // Сохраняем примененную экспирацию, чтобы потом можно было
                        // анализировать winrate по длине сделки (2/3/4/5 минут).
                        'expiry_sec_used' => $decisionExpirySec,
                    ],
                ]);

                $signals++;
                $trades++;
            });
        }

        return [
            'processed' => $processed,
            'signals' => $signals,
            'trades' => $trades,
        ];
    }

    /**
     * Диагностика по одному инструменту: показывает, почему бот войдёт/не войдёт сейчас.
     * Метод не создаёт сигнал/сделку и не меняет состояние БД.
     *
     * @return array<string,mixed>
     */
    public function explainInstrumentDecision(Bot $bot, string $instrument): array
    {
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
     * @return array{config:array<string,mixed>,count:int,expiry_sec:int,min_confidence:float,min_payout:float,stale_after_sec:int,min_closed_bars:int,dedupe_bucket_sec:int}
     */
    private function runtimeSettings(Bot $bot): array
    {
        $config = is_array($bot->config) ? $bot->config : [];
        $defaultCount = $bot->type === 'feshan' ? 2500 : 120;
        $count = max(10, min(5000, (int) ($config['count'] ?? $defaultCount)));
        $expirySec = match ($bot->type) {
            'three_candles_in_row' => (int) ($config['expiry_sec'] ?? 60),
            'feshan' => (int) ($config['expiry_sec'] ?? 300),
            default => (int) ($config['expiry_sec'] ?? 300),
        };
        // Для liquidity sweep базовый дефолт confidence ниже, иначе стратегия чрезмерно "молчит".
        $minConfidence = (float) ($config['min_confidence'] ?? ($bot->type === 'feshan' ? 0.6 : 0.55));
        // Минимальный payout как экономический фильтр качества входа.
        $minPayout = (float) ($config['min_payout'] ?? ($bot->type === 'feshan' ? 80.0 : 0.0));
        $staleAfterSec = (int) ($config['stale_after_sec'] ?? 30);
        $minClosedBars = match ($bot->type) {
            'feshan' => 40,
            default => 3,
        };
        $dedupeBucketSec = $bot->type === 'feshan'
            ? max(60, min(900, $expirySec))
            : 60;
        return [
            'config' => $config,
            'count' => $count,
            'expiry_sec' => $expirySec,
            'min_confidence' => $minConfidence,
            'min_payout' => $minPayout,
            'stale_after_sec' => $staleAfterSec,
            'min_closed_bars' => $minClosedBars,
            'dedupe_bucket_sec' => $dedupeBucketSec,
        ];
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
            'feshan' => new FeshanStrategy,
            default => null,
        };
    }
}
