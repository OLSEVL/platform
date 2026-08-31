<?php

namespace App\Http\Controllers;

use App\Jobs\RunBotBacktestJob;
use App\Models\Bot;
use App\Models\BotBacktestRun;
use App\Models\BotBacktestTrade;
use App\Models\PocketInstrument;
use App\Services\Bots\BotBacktestEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Страница и API прогона настроек бота по истории свечей.
 *
 * GET    /bots/{bot}/backtest
 * GET/POST/DELETE /bots/{bot}/backtests
 * GET/DELETE /bots/{bot}/backtests/{run}
 * GET    /bots/{bot}/backtests/{run}/trades
 * POST   /bots/{bot}/backtests/{run}/cancel
 * GET    /bots/{bot}/backtests/compare?a=&b=
 */
class BotBacktestController extends Controller
{
    /**
     * Отдельная вкладка бэктеста (открывается target=_blank с графика / списка ботов).
     */
    public function page(Request $request, Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        $supported = ! in_array($bot->type, BotBacktestEngine::unsupportedTypes(), true);

        $instruments = PocketInstrument::active()
            ->orderByDesc('current_payout')
            ->orderBy('instrument')
            ->get(['instrument', 'display_name', 'current_payout']);

        // ?instrument= с графика — предвыбор пары; без параметра по умолчанию «Все» (пустая строка).
        $preselect = trim((string) $request->query('instrument', ''));
        if ($preselect !== '' && ! $instruments->contains(fn ($row) => (string) $row->instrument === $preselect)) {
            $preselect = '';
        }

        return view('bots.backtest', [
            'bot' => $bot,
            'instruments' => $instruments,
            'preselectInstrument' => $preselect,
            'backtestSupported' => $supported,
        ]);
    }

    public function index(Bot $bot): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureTables();

        // Подсказка UI: какой кусок истории реально есть в candles (S5).
        $candleRange = DB::table('candles')
            ->where('timeframe', \App\Services\CandleService::TF_S5)
            ->selectRaw('MIN(time) as min_ts, MAX(time) as max_ts')
            ->first();

        $queueConnection = (string) config('queue.default', 'sync');
        // Старые queued без job в таблице jobs — помечаем failed (иначе вечная «зависла» подсказка).
        $this->failOrphanedQueuedRuns($bot);

        $pendingJobRunIds = $this->pendingBacktestJobRunIds();
        $queuedWaitingOnWorker = BotBacktestRun::query()
            ->where('bot_id', $bot->id)
            ->where('status', BotBacktestRun::STATUS_QUEUED)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->whereIn('id', $pendingJobRunIds !== [] ? $pendingJobRunIds : [0])
            ->exists();

        // Важно: НЕ тянуть полный summary_json в ORDER BY/SELECT списка —
        // там equity_curve на десятки тысяч точек → MySQL HY001 Out of sort memory.
        $runIds = BotBacktestRun::query()
            ->where('bot_id', $bot->id)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        $runs = [];
        if ($runIds->isNotEmpty()) {
            // Только скаляры + JSON_EXTRACT метрик (без blob целиком).
            $rows = DB::table('bot_backtest_runs')
                ->whereIn('id', $runIds->all())
                ->orderByDesc('id')
                ->get([
                    'id',
                    'status',
                    'progress_pct',
                    'from_ts',
                    'to_ts',
                    'trades_total',
                    'error_message',
                    'started_at',
                    'finished_at',
                    'created_at',
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(summary_json, '$.winrate')) as summary_winrate"),
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(summary_json, '$.pnl_units')) as summary_pnl_units"),
                ]);

            foreach ($rows as $row) {
                $runs[] = [
                    'id' => (int) $row->id,
                    'status' => $row->status,
                    'progress_pct' => (int) ($row->progress_pct ?? 0),
                    'from_ts' => $row->from_ts !== null ? (int) $row->from_ts : null,
                    'to_ts' => $row->to_ts !== null ? (int) $row->to_ts : null,
                    'trades_total' => (int) ($row->trades_total ?? 0),
                    'error_message' => $row->error_message,
                    'started_at' => $row->started_at,
                    'finished_at' => $row->finished_at,
                    'created_at' => $row->created_at,
                    // Компактный summary только для строк списка / compare select.
                    'summary_json' => [
                        'winrate' => $row->summary_winrate !== null ? (float) $row->summary_winrate : null,
                        'pnl_units' => $row->summary_pnl_units !== null ? (float) $row->summary_pnl_units : null,
                    ],
                ];
            }
        }

        return response()->json([
            'unsupported_types' => BotBacktestEngine::unsupportedTypes(),
            'bot_supported' => ! in_array($bot->type, BotBacktestEngine::unsupportedTypes(), true),
            'candle_range' => [
                'min_ts' => $candleRange && $candleRange->min_ts !== null ? (int) $candleRange->min_ts : null,
                'max_ts' => $candleRange && $candleRange->max_ts !== null ? (int) $candleRange->max_ts : null,
            ],
            // Подсказка только если job реально лежит в очереди >2 мин (worker не берёт).
            'queue' => [
                'connection' => $queueConnection,
                'queue_name' => RunBotBacktestJob::QUEUE_NAME,
                'worker_hint' => 'sudo systemctl restart platform-queue  # или: php artisan queue:work --queue='.RunBotBacktestJob::QUEUE_NAME.',default --timeout=1800',
                'stuck_queued' => $queuedWaitingOnWorker,
                'pending_jobs' => count($pendingJobRunIds),
            ],
            'runs' => $runs,
        ]);
    }

    public function store(Request $request, Bot $bot, BotBacktestEngine $engine): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureTables();
        $this->failOrphanedQueuedRuns($bot);

        $data = $request->validate([
            'from_ts' => ['required', 'integer', 'min:1'],
            'to_ts' => ['required', 'integer', 'gt:from_ts'],
            'instruments' => ['required', 'array', 'min:1', 'max:200'],
            'instruments.*' => ['string', 'max:63'],
            // 0 = все пары (без фильтра по min_payout в прогоне).
            'payout' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'config_override' => ['sometimes', 'array'],
            'simulate_dedupe' => ['sometimes', 'boolean'],
            'one_trade_at_a_time' => ['sometimes', 'boolean'],
            // sync=1 — прогон в HTTP (только короткие тесты / одна пара).
            'sync' => ['sometimes', 'boolean'],
        ]);

        try {
            $run = $engine->createRun($bot, (int) auth()->id(), $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $instrumentCount = count($data['instruments']);
        $spanSec = (int) $data['to_ts'] - (int) $data['from_ts'];
        $wantSync = $request->boolean('sync');
        // Короткие однопарные — sync ок; «Все» / длинный период — только очередь (или inline при QUEUE=sync).
        $allowSync = $wantSync && $instrumentCount === 1 && $spanSec <= 6 * 3600;
        $queueConnection = (string) config('queue.default', 'sync');

        $ranInline = false;
        if ($allowSync || $queueConnection === 'sync') {
            // Нет async-worker (driver=sync) или явный короткий sync — крутим в запросе.
            if (! $allowSync && $queueConnection === 'sync') {
                @set_time_limit(1800);
            }
            $engine->execute($run);
            $run->refresh();
            $ranInline = true;
        } else {
            RunBotBacktestJob::dispatch($run->id);
        }

        return response()->json([
            'run' => $this->runPayload($run),
            'queued' => ! $ranInline,
            'queue' => [
                'connection' => $queueConnection,
                'queue_name' => RunBotBacktestJob::QUEUE_NAME,
                'worker_hint' => 'php artisan queue:work --queue='.RunBotBacktestJob::QUEUE_NAME.',default --timeout=1800',
            ],
        ], 201);
    }

    public function show(Bot $bot, BotBacktestRun $run): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureRunBelongs($bot, $run);
        $this->ensureTables();

        return response()->json(['run' => $this->runPayload($run->fresh())]);
    }

    /**
     * Сделки прогона с пагинацией и фильтрами (пара / результат).
     */
    public function trades(Request $request, Bot $bot, BotBacktestRun $run): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureRunBelongs($bot, $run);
        $this->ensureTables();

        $perPage = max(1, min(200, (int) $request->input('per_page', $request->input('limit', 50))));
        $page = max(1, (int) $request->input('page', 1));
        $instrument = trim((string) $request->input('instrument', ''));
        $result = strtolower(trim((string) $request->input('result', '')));

        $base = BotBacktestTrade::query()->where('bot_backtest_run_id', $run->id);
        if ($instrument !== '') {
            $base->where('instrument', $instrument);
        }
        if (in_array($result, ['win', 'loss', 'draw', 'pending'], true)) {
            $base->where('result', $result);
        }

        $total = (clone $base)->count();
        $trades = (clone $base)
            ->orderBy('entry_ts')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Уникальные пары прогона — для фильтра в UI (без учёта текущего фильтра пары).
        $instruments = BotBacktestTrade::query()
            ->where('bot_backtest_run_id', $run->id)
            ->distinct()
            ->orderBy('instrument')
            ->pluck('instrument')
            ->values();

        return response()->json([
            'run_id' => $run->id,
            'instrument' => $instrument !== '' ? $instrument : null,
            'result' => $result !== '' ? $result : null,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'instruments' => $instruments,
            'trades' => $trades,
        ]);
    }

    /**
     * Сравнение двух завершённых прогонов одного бота (метрики + топ пар).
     */
    public function compare(Request $request, Bot $bot): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureTables();

        $data = $request->validate([
            'a' => ['required', 'integer', 'min:1'],
            'b' => ['required', 'integer', 'min:1', 'different:a'],
        ]);

        $runA = BotBacktestRun::query()->where('bot_id', $bot->id)->findOrFail((int) $data['a']);
        $runB = BotBacktestRun::query()->where('bot_id', $bot->id)->findOrFail((int) $data['b']);

        return response()->json([
            'a' => $this->compareSide($runA),
            'b' => $this->compareSide($runB),
            'delta' => $this->compareDelta(
                is_array($runA->summary_json) ? $runA->summary_json : [],
                is_array($runB->summary_json) ? $runB->summary_json : [],
            ),
        ]);
    }

    public function cancel(Bot $bot, BotBacktestRun $run): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureRunBelongs($bot, $run);
        $this->ensureTables();

        if (in_array($run->status, [
            BotBacktestRun::STATUS_COMPLETED,
            BotBacktestRun::STATUS_FAILED,
            BotBacktestRun::STATUS_CANCELLED,
        ], true)) {
            return response()->json(['run' => $this->runPayload($run)]);
        }

        $run->update([
            'status' => BotBacktestRun::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);

        return response()->json(['run' => $this->runPayload($run->fresh())]);
    }

    /** Удалить один прогон (сделки — cascade). */
    public function destroy(Bot $bot, BotBacktestRun $run): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureRunBelongs($bot, $run);
        $this->ensureTables();

        // Идущий прогон сначала помечаем cancelled, чтобы Job вышел на fresh()->status.
        if (in_array($run->status, [BotBacktestRun::STATUS_QUEUED, BotBacktestRun::STATUS_RUNNING], true)) {
            $run->update([
                'status' => BotBacktestRun::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);
        }

        $id = (int) $run->id;
        $run->delete();

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    /** Удалить все прогоны этого бота. */
    public function destroyAll(Bot $bot): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $this->ensureTables();

        BotBacktestRun::query()
            ->where('bot_id', $bot->id)
            ->whereIn('status', [BotBacktestRun::STATUS_QUEUED, BotBacktestRun::STATUS_RUNNING])
            ->update([
                'status' => BotBacktestRun::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);

        $deleted = BotBacktestRun::query()->where('bot_id', $bot->id)->delete();

        return response()->json(['deleted' => true, 'count' => $deleted]);
    }

    /** @return array<string, mixed> */
    private function runPayload(?BotBacktestRun $run): array
    {
        if (! $run) {
            return [];
        }

        $summary = is_array($run->summary_json) ? $run->summary_json : [];
        // Старые прогоны без серий (глобально или по парам) — полный пересчёт summary.
        $byInstr = is_array($summary['by_instrument'] ?? null) ? $summary['by_instrument'] : [];
        $firstPair = $byInstr !== [] ? reset($byInstr) : null;
        $needsStreaks = ! array_key_exists('max_win_streak', $summary)
            || ! array_key_exists('max_loss_streak', $summary)
            || ! array_key_exists('streak_distribution', $summary)
            || ($firstPair !== null && ! array_key_exists('max_win_streak', $firstPair))
            || ($firstPair !== null && ! array_key_exists('streak_distribution', $firstPair));
        if (
            $run->status === BotBacktestRun::STATUS_COMPLETED
            && (int) $run->trades_total > 0
            && $needsStreaks
        ) {
            $summary = app(BotBacktestEngine::class)->buildSummary($run);
            $run->summary_json = $summary;
            $run->saveQuietly();
        }

        return [
            'id' => $run->id,
            'bot_id' => $run->bot_id,
            'status' => $run->status,
            'progress_pct' => $run->progress_pct,
            'from_ts' => $run->from_ts,
            'to_ts' => $run->to_ts,
            'params' => $run->params_json,
            'config_snapshot' => $run->config_snapshot_json,
            'summary' => $summary,
            'bars_total' => $run->bars_total,
            'bars_done' => $run->bars_done,
            'trades_total' => $run->trades_total,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'created_at' => $run->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function compareSide(BotBacktestRun $run): array
    {
        $summary = is_array($run->summary_json) ? $run->summary_json : [];
        $byInstr = is_array($summary['by_instrument'] ?? null) ? $summary['by_instrument'] : [];
        // Топ-10 пар по |PnL| для компактного сравнения.
        uasort($byInstr, static fn ($a, $b) => abs((float) ($b['pnl'] ?? 0)) <=> abs((float) ($a['pnl'] ?? 0)));
        $top = array_slice($byInstr, 0, 10, true);

        return [
            'run' => $this->runPayload($run),
            'metrics' => [
                'trades' => $summary['trades'] ?? $run->trades_total,
                'wins' => $summary['wins'] ?? null,
                'losses' => $summary['losses'] ?? null,
                'draws' => $summary['draws'] ?? null,
                'winrate' => $summary['winrate'] ?? null,
                'pnl_units' => $summary['pnl_units'] ?? null,
                'profit_factor' => $summary['profit_factor'] ?? null,
                'max_drawdown_units' => $summary['max_drawdown_units'] ?? null,
                'max_win_streak' => $summary['max_win_streak'] ?? null,
                'max_loss_streak' => $summary['max_loss_streak'] ?? null,
            ],
            'by_instrument_top' => $top,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, float|null>
     */
    private function compareDelta(array $a, array $b): array
    {
        $num = static function (array $s, string $key): ?float {
            if (! array_key_exists($key, $s) || $s[$key] === null) {
                return null;
            }

            return (float) $s[$key];
        };

        $delta = static function (?float $left, ?float $right): ?float {
            if ($left === null || $right === null) {
                return null;
            }

            return round($right - $left, 4);
        };

        return [
            'trades' => $delta($num($a, 'trades'), $num($b, 'trades')),
            'winrate' => $delta($num($a, 'winrate'), $num($b, 'winrate')),
            'pnl_units' => $delta($num($a, 'pnl_units'), $num($b, 'pnl_units')),
            'profit_factor' => $delta($num($a, 'profit_factor'), $num($b, 'profit_factor')),
            'max_drawdown_units' => $delta($num($a, 'max_drawdown_units'), $num($b, 'max_drawdown_units')),
        ];
    }

    /**
     * run_id из payload jobs для очереди backtest (и legacy default).
     *
     * @return list<int>
     */
    private function pendingBacktestJobRunIds(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $ids = [];
        $rows = DB::table('jobs')
            ->whereIn('queue', [RunBotBacktestJob::QUEUE_NAME, 'default'])
            ->pluck('payload');

        foreach ($rows as $payload) {
            $payload = (string) $payload;
            if (! str_contains($payload, 'RunBotBacktestJob')) {
                continue;
            }
            // PHP serialize: backtestRunId";i:14;
            if (preg_match('/backtestRunId";i:(\d+);/', $payload, $m)) {
                $ids[] = (int) $m[1];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * queued без записи в jobs — job потерян (timeout/рестарт worker). Помечаем failed.
     */
    private function failOrphanedQueuedRuns(Bot $bot): void
    {
        $pending = $this->pendingBacktestJobRunIds();
        $q = BotBacktestRun::query()
            ->where('bot_id', $bot->id)
            ->where('status', BotBacktestRun::STATUS_QUEUED)
            // updated_at: chunk-continue тоже QUEUED, но свежий — не трогаем.
            ->where('updated_at', '<', now()->subMinutes(2));

        if ($pending !== []) {
            $q->whereNotIn('id', $pending);
        }

        $q->update([
            'status' => BotBacktestRun::STATUS_FAILED,
            'error_message' => 'Job не найден в очереди (worker не обработал / потерян). Запустите прогон снова.',
            'finished_at' => now(),
        ]);
    }

    private function ensureBotOwner(Bot $bot): void
    {
        abort_unless((int) $bot->user_id === (int) auth()->id(), 403);
    }

    private function ensureRunBelongs(Bot $bot, BotBacktestRun $run): void
    {
        abort_unless((int) $run->bot_id === (int) $bot->id, 404);
    }

    private function ensureTables(): void
    {
        abort_unless(
            Schema::hasTable('bot_backtest_runs') && Schema::hasTable('bot_backtest_trades'),
            503,
            'Backtest tables missing — run migrations.'
        );
    }
}
