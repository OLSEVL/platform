<?php

namespace App\Services;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Models\InstrumentCandle;
use App\Models\PocketInstrument;
use App\Services\Telegram\TelegramAnalystProcessManager;
use App\Support\ArtisanProcessFinder;
use App\Support\BotScanProgress;
use App\Support\IngestHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сводная диагностика фоновых процессов платформы для web-страницы /settings/processes.
 * Читает /proc, cache lock, очереди, ingest и хвосты логов.
 */
class PlatformProcessMonitorService
{
    /** Scan дольше — warning (сек). */
    public const SCAN_WARN_ELAPSED_SEC = 1800;

    /** Scan дольше — error (сек). */
    public const SCAN_ERROR_ELAPSED_SEC = 3600;

    /** Одна пара без смены current — warning (сек). */
    public const SCAN_STUCK_INSTRUMENT_SEC = 300;

    public function __construct(
        private readonly TelegramAnalystProcessManager $telegramProcesses,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $processes = [
            $this->probeBotsScan(),
            $this->probeScheduler(),
            $this->probeQueueDefault(),
            $this->probeQueueTelegramAnalyst(),
            $this->probeTelegramPoll(),
            $this->probeProxiesProbeLoop(),
            $this->probePocketOptionsProxy(),
        ];

        $overall = 'ok';
        foreach ($processes as $row) {
            $overall = self::mergeSeverity($overall, (string) ($row['status'] ?? 'ok'));
        }

        return [
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'overall_status' => $overall,
            'load_avg' => $this->readLoadAverage(),
            'processes' => $processes,
            'queues' => $this->queueSummary(),
            'ingest' => $this->ingestSummary(),
            'active_bots' => $this->activeBotsSummary(),
        ];
    }

    /**
     * @return array{ok:bool,error?:string,message?:string,killed?:int,status?:array<string,mixed>}
     */
    public function kill(string $key, ?int $botId = null): array
    {
        return match ($key) {
            'bots_scan' => $this->killBotsScan(),
            'queue_default' => $this->killQueueDefault(),
            'queue_telegram_analyst' => $this->killTelegramQueue(),
            'telegram_analyst_poll' => $this->killTelegramPoll($botId),
            'proxies_probe_loop' => $this->killProbeLoop(),
            'pocket_options_proxy' => $this->killPocketProxy(),
            default => ['ok' => false, 'error' => 'unsupported_process'],
        };
    }

    /**
     * @return array{ok:bool,error?:string,message?:string,status?:array<string,mixed>}
     */
    public function restart(string $key, ?int $botId = null): array
    {
        $killResult = $this->kill($key, $botId);
        if (! ($killResult['ok'] ?? false) && ($killResult['error'] ?? '') !== 'not_running') {
            // not_running — нормально перед стартом.
            if (($killResult['error'] ?? '') !== 'unsupported_process') {
                // Продолжаем старт только если kill не упал на unsupported.
            }
        }

        usleep(400_000);

        return match ($key) {
            'bots_scan' => $this->startBotsScan(),
            'queue_default' => $this->startQueueDefault(),
            'queue_telegram_analyst' => $this->startTelegramQueue(),
            'telegram_analyst_poll' => $this->startTelegramPoll($botId),
            'proxies_probe_loop' => $this->startProbeLoop(),
            'pocket_options_proxy' => [
                'ok' => false,
                'error' => 'manual_restart',
                'message' => 'Перезапустите прокси: ./start-all.sh --restart-proxy или systemctl restart platform-pocket-proxy',
            ],
            default => ['ok' => false, 'error' => 'unsupported_process'],
        };
    }

    public static function mergeSeverity(string $current, string $next): string
    {
        // stopped не перекрывает ok: опциональный не запущенный daemon не должен портить общий статус.
        $rank = ['stopped' => 0, 'ok' => 1, 'warning' => 2, 'error' => 3];

        return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeBotsScan(): array
    {
        // Только реальный php artisan bots:scan — не bash/cursor-оболочки с этим текстом в cmdline.
        $pids = ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmd));
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $lock = $this->scanLockRow();
        $logPath = storage_path('logs/bots-scan.log');

        $maxElapsed = 0;
        $maxCpu = null;
        foreach ($instances as $row) {
            $maxElapsed = max($maxElapsed, (int) ($row['elapsed_sec'] ?? 0));
            if ($row['cpu_percent'] !== null) {
                $maxCpu = $maxCpu === null ? (float) $row['cpu_percent'] : max($maxCpu, (float) $row['cpu_percent']);
            }
        }

        $scanProgress = $this->scanProgressForActiveBots();
        $stuckInstrument = $this->detectStuckScanInstrument($scanProgress);

        $status = 'stopped';
        $issues = [];

        if ($pids !== []) {
            $status = 'ok';
            if (count($pids) > 1) {
                $status = 'error';
                $issues[] = 'Несколько параллельных bots:scan ('.count($pids).')';
            }
            if ($maxElapsed >= self::SCAN_ERROR_ELAPSED_SEC) {
                $status = 'error';
                $issues[] = 'Scan идёт дольше '.intdiv(self::SCAN_ERROR_ELAPSED_SEC, 60).' мин';
            } elseif ($maxElapsed >= self::SCAN_WARN_ELAPSED_SEC) {
                $status = self::mergeSeverity($status, 'warning');
                $issues[] = 'Scan идёт дольше '.intdiv(self::SCAN_WARN_ELAPSED_SEC, 60).' мин';
            }
            if ($stuckInstrument !== null) {
                $status = self::mergeSeverity($status, 'warning');
                $issues[] = 'Долго на одной паре: '.$stuckInstrument['instrument'];
            }
        } else {
            $issues[] = 'Процесс не запущен (cron запустит при следующем schedule:run, если есть активные боты)';
        }

        if ($lock !== null && $pids === []) {
            $status = self::mergeSeverity($status === 'stopped' ? 'warning' : $status, 'warning');
            $issues[] = 'Lock bots:scan в БД без живого процесса';
        }
        if ($lock === null && $pids !== []) {
            $status = self::mergeSeverity($status, 'warning');
            $issues[] = 'Процесс есть, lock в cache_locks не найден';
        }

        return [
            'key' => 'bots_scan',
            'label' => 'Bot scan (bots:scan)',
            'description' => 'Плановый анализ пар активными ботами (cron каждую минуту).',
            'running' => $pids !== [],
            'status' => $status,
            'pids' => $pids,
            'instances' => $instances,
            'elapsed_sec' => $maxElapsed > 0 ? $maxElapsed : null,
            'cpu_percent' => $maxCpu,
            'issues' => $issues,
            'details' => [
                'lock_held' => $lock !== null,
                'lock_expiration' => $lock['expiration'] ?? null,
                'scan_progress' => $scanProgress,
                'stuck_instrument' => $stuckInstrument,
            ],
            'log_path' => $logPath,
            'log_tail' => ArtisanProcessFinder::tailLogFile($logPath, 25),
            'actions' => ['kill', 'restart'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeScheduler(): array
    {
        $logPath = storage_path('logs/scheduler.log');
        $tail = ArtisanProcessFinder::tailLogFile($logPath, 40);
        $lastRunAt = $this->parseSchedulerLastRun($tail);
        $minutesAgo = $lastRunAt !== null ? max(0, (int) $lastRunAt->diffInMinutes(now())) : null;

        $status = 'ok';
        $issues = [];
        if ($lastRunAt === null) {
            $status = 'warning';
            $issues[] = 'Не найден scheduler.log или записей schedule:run';
        } elseif ($minutesAgo !== null && $minutesAgo > 3) {
            $status = 'warning';
            $issues[] = 'Последний schedule:run '.$minutesAgo.' мин назад — проверьте cron';
        }

        return [
            'key' => 'scheduler',
            'label' => 'Laravel Scheduler (cron)',
            'description' => 'Cron: * * * * * php artisan schedule:run — запускает bots:scan и bots:settle.',
            'running' => true,
            'status' => $status,
            'pids' => [],
            'instances' => [],
            'issues' => $issues,
            'details' => [
                'last_run_at' => $lastRunAt?->toIso8601String(),
                'minutes_ago' => $minutesAgo,
                'cron_hint' => '* * * * * cd '.base_path().' && php artisan schedule:run >> '.storage_path('logs/scheduler.log').' 2>&1',
            ],
            'log_path' => $logPath,
            'log_tail' => $tail,
            'actions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeQueueDefault(): array
    {
        $pids = $this->defaultQueuePids();
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $logPath = storage_path('logs/queue.log');

        $status = $pids !== [] ? 'ok' : 'warning';
        $issues = $pids === [] ? ['Queue worker (default/mirror) не запущен — mirror-сделки могут не уходить'] : [];

        return [
            'key' => 'queue_default',
            'label' => 'Queue worker (default/mirror)',
            'description' => 'Обработка mirror и default jobs.',
            'running' => $pids !== [],
            'status' => $status,
            'pids' => $pids,
            'instances' => $instances,
            'issues' => $issues,
            'details' => [],
            'log_path' => $logPath,
            'log_tail' => ArtisanProcessFinder::tailLogFile($logPath, 20),
            'actions' => ['kill', 'restart'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeQueueTelegramAnalyst(): array
    {
        $pids = $this->telegramProcesses->queueWorkerPids();
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $logPath = storage_path('logs/telegram-analyst-queue.log');
        $pending = $this->pendingJobsForQueue(RunTelegramAnalystAnalysisJob::QUEUE_NAME);

        $status = $pids !== [] ? 'ok' : 'stopped';
        $issues = [];
        if ($pids === []) {
            $issues[] = 'Worker telegram-analyst не запущен';
            $status = $pending > 0 ? 'error' : 'stopped';
        } elseif ($pending > 5) {
            $status = 'warning';
            $issues[] = "В очереди {$pending} jobs — возможна перегрузка";
        }

        return [
            'key' => 'queue_telegram_analyst',
            'label' => 'Queue worker (telegram-analyst)',
            'description' => 'OpenRouter-анализ для Telegram (timeout 600s).',
            'running' => $pids !== [],
            'status' => $status,
            'pids' => $pids,
            'instances' => $instances,
            'issues' => $issues,
            'details' => ['pending_jobs' => $pending],
            'log_path' => $logPath,
            'log_tail' => ArtisanProcessFinder::tailLogFile($logPath, 20),
            'actions' => ['kill', 'restart'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeTelegramPoll(): array
    {
        $botId = $this->defaultTelegramAnalystBotId();
        $pids = $botId !== null ? $this->telegramProcesses->pollPids($botId) : [];
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $logPath = $botId !== null ? storage_path('logs/telegram-analyst-poll-'.$botId.'.log') : null;

        $status = 'stopped';
        $issues = [];
        if ($botId === null) {
            $issues[] = 'Нет бота openrouter_llm_analyst';
        } elseif ($pids === []) {
            $issues[] = 'Long polling не запущен (bot #'.$botId.')';
        } else {
            $status = 'ok';
        }

        return [
            'key' => 'telegram_analyst_poll',
            'label' => 'Telegram analyst poll',
            'description' => 'Long polling getUpdates для интерактивного аналитика.',
            'running' => $pids !== [],
            'status' => $status,
            'pids' => $pids,
            'instances' => $instances,
            'issues' => $issues,
            'details' => ['bot_id' => $botId],
            'log_path' => $logPath,
            'log_tail' => $logPath ? ArtisanProcessFinder::tailLogFile($logPath, 20) : '',
            'actions' => $botId !== null ? ['kill', 'restart'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeProxiesProbeLoop(): array
    {
        $pids = ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => str_contains($cmd, 'proxies:probe-loop'));
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $logPath = storage_path('logs/proxy-probe.log');

        return [
            'key' => 'proxies_probe_loop',
            'label' => 'Proxy probe loop',
            'description' => 'Демон авто-пробы dead-прокси (proxies:probe-loop).',
            'running' => $pids !== [],
            'status' => $pids !== [] ? 'ok' : 'stopped',
            'pids' => $pids,
            'instances' => $instances,
            'issues' => $pids === [] ? ['Демон probe-loop не запущен (опционально)'] : [],
            'details' => [],
            'log_path' => $logPath,
            'log_tail' => ArtisanProcessFinder::tailLogFile($logPath, 20),
            'actions' => ['kill', 'restart'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probePocketOptionsProxy(): array
    {
        $scriptPath = base_path('bot/pocket-options-proxy.py');
        $pids = ArtisanProcessFinder::findCommandPids(static function (string $cmd) use ($scriptPath): bool {
            // Только канонический запуск: абсолютный путь к скрипту.
            // Отсекаем краткоживущие `python pocket-options-proxy.py` при рестарте.
            return str_contains($cmd, $scriptPath)
                && preg_match('#(?:^|/)(python\d*(?:\.\d+)?|'.preg_quote(base_path('bot/.venv/bin/python'), '#').')#', $cmd) === 1;
        });
        $instances = ArtisanProcessFinder::metricsForPids($pids);
        $logPath = storage_path('logs/proxy.log');

        $status = $pids !== [] ? 'ok' : 'error';
        $issues = $pids === [] ? ['Python-прокси свечей не запущен — ingest остановится'] : [];
        if (count($pids) > 1) {
            $status = 'error';
            $issues[] = 'Несколько процессов pocket-options-proxy.py — риск EADDRINUSE';
        }

        return [
            'key' => 'pocket_options_proxy',
            'label' => 'Pocket Options proxy (Python)',
            'description' => 'WebSocket → ingest-candles / ingest-current-candle.',
            'running' => $pids !== [],
            'status' => $status,
            'pids' => $pids,
            'instances' => $instances,
            'issues' => $issues,
            'details' => ['port' => 3000],
            'log_path' => $logPath,
            'log_tail' => ArtisanProcessFinder::tailLogFile($logPath, 20),
            'actions' => ['kill'],
        ];
    }

    /**
     * @return list<int>
     */
    private function defaultQueuePids(): array
    {
        return ArtisanProcessFinder::findArtisanPids(static function (string $cmd): bool {
            if (! str_contains($cmd, 'queue:work')) {
                return false;
            }
            if (str_contains($cmd, RunTelegramAnalystAnalysisJob::QUEUE_NAME)) {
                return false;
            }
            // Старый analyst worker без --queue= — относим к telegram, не к default.
            if (str_contains($cmd, '--timeout='.TelegramAnalystProcessManager::QUEUE_TIMEOUT_SEC)
                && ! str_contains($cmd, '--queue=')) {
                return false;
            }

            return true;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scanLockRow(): ?array
    {
        if (! Schema::hasTable('cache_locks')) {
            return null;
        }

        $key = config('cache.prefix').'bots:scan:global';
        $row = DB::table('cache_locks')->where('key', $key)->first();
        if ($row === null) {
            return null;
        }

        return [
            'key' => $row->key,
            'owner' => $row->owner ?? null,
            'expiration' => isset($row->expiration) ? (int) $row->expiration : null,
            'expired' => isset($row->expiration) && (int) $row->expiration <= time(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanProgressForActiveBots(): array
    {
        $rows = [];
        $bots = Bot::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'type', 'config']);
        foreach ($bots as $bot) {
            if ($bot->type === 'openrouter_llm_analyst') {
                continue;
            }
            $cfg = is_array($bot->config) ? $bot->config : [];
            $minPayout = (float) ($cfg['min_payout'] ?? 0);
            $progress = BotScanProgress::snapshot($bot->id, $minPayout);
            if ($progress['scan_active'] ?? false) {
                $rows[] = [
                    'bot_id' => $bot->id,
                    'bot_name' => $bot->name,
                    'bot_type' => $bot->type,
                    'progress' => $progress,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $scanProgress
     * @return array{instrument:string,bot_id:int,seconds:int}|null
     */
    private function detectStuckScanInstrument(array $scanProgress): ?array
    {
        $now = time();
        foreach ($scanProgress as $row) {
            $progress = is_array($row['progress'] ?? null) ? $row['progress'] : [];
            $instrument = $progress['analyzing_instrument'] ?? null;
            $since = $progress['analyzing_since'] ?? null;
            if (! is_string($instrument) || $instrument === '' || ! is_int($since)) {
                continue;
            }
            $seconds = max(0, $now - $since);
            if ($seconds >= self::SCAN_STUCK_INSTRUMENT_SEC) {
                return [
                    'instrument' => $instrument,
                    'bot_id' => (int) ($row['bot_id'] ?? 0),
                    'seconds' => $seconds,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{1m:float,5m:float,15m:float}|null
     */
    private function readLoadAverage(): ?array
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($raw)) ?: [];

        return [
            '1m' => isset($parts[0]) ? (float) $parts[0] : 0.0,
            '5m' => isset($parts[1]) ? (float) $parts[1] : 0.0,
            '15m' => isset($parts[2]) ? (float) $parts[2] : 0.0,
        ];
    }

    /**
     * @return array<string, array{pending:int,failed:int}>
     */
    private function queueSummary(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $pending = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as c'))
            ->groupBy('queue')
            ->pluck('c', 'queue')
            ->all();

        $failedTotal = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $queues = ['default', 'mirror', RunTelegramAnalystAnalysisJob::QUEUE_NAME];
        $out = [];
        foreach ($queues as $q) {
            $out[$q] = [
                'pending' => (int) ($pending[$q] ?? 0),
                'failed' => $q === 'default' ? $failedTotal : 0,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int|float>
     */
    private function ingestSummary(): array
    {
        $active = PocketInstrument::active()->count();
        $updatesLastMinute = InstrumentCandle::query()
            ->where('updated_at', '>=', now()->subMinute())
            ->count();

        $silent = 0;
        foreach (PocketInstrument::active()->get() as $instrument) {
            if (IngestHealth::isIngestSilent($instrument)) {
                $silent++;
            }
        }

        return [
            'active_instruments' => $active,
            'candle_updates_last_minute' => $updatesLastMinute,
            'ingest_silent_instruments' => $silent,
        ];
    }

    /**
     * @return list<array{id:int,name:string,type:string}>
     */
    private function activeBotsSummary(): array
    {
        return Bot::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'type'])
            ->map(fn (Bot $b) => ['id' => $b->id, 'name' => $b->name, 'type' => $b->type])
            ->values()
            ->all();
    }

    private function pendingJobsForQueue(string $queue): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->where('queue', $queue)->count();
    }

    private function defaultTelegramAnalystBotId(): ?int
    {
        $bot = Bot::query()
            ->where('type', 'openrouter_llm_analyst')
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id']);

        if ($bot !== null) {
            return $bot->id;
        }

        $fallback = Bot::query()->where('type', 'openrouter_llm_analyst')->orderBy('id')->first(['id']);

        return $fallback?->id;
    }

    private function parseSchedulerLastRun(string $logTail): ?\Illuminate\Support\Carbon
    {
        if ($logTail === '') {
            return null;
        }

        $lines = array_reverse(explode("\n", $logTail));
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
                try {
                    return \Illuminate\Support\Carbon::parse($m[1]);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,error?:string,message?:string,killed?:int}
     */
    private function killBotsScan(): array
    {
        // Только реальный php artisan bots:scan — не bash/cursor-оболочки с этим текстом в cmdline.
        $pids = ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmd));
        if ($pids === []) {
            $this->clearScanLock();

            return ['ok' => true, 'message' => 'bots:scan не был запущен', 'killed' => 0, 'error' => 'not_running'];
        }

        $killed = ArtisanProcessFinder::terminatePids($pids);
        usleep(300_000);
        $this->clearScanLock();

        return ['ok' => true, 'message' => "Остановлено процессов: {$killed}", 'killed' => $killed];
    }

    private function clearScanLock(): void
    {
        if (! Schema::hasTable('cache_locks')) {
            return;
        }
        $key = config('cache.prefix').'bots:scan:global';
        DB::table('cache_locks')->where('key', $key)->delete();
    }

    /**
     * @return array{ok:bool,error?:string,message?:string,status?:array<string,mixed>}
     */
    private function startBotsScan(): array
    {
        if (ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+bots:scan\b#', $cmd)) !== []) {
            return ['ok' => false, 'error' => 'already_running'];
        }

        $log = storage_path('logs/bots-scan.log');
        $ok = ArtisanProcessFinder::spawnBackground([PHP_BINARY, 'artisan', 'bots:scan'], $log);

        return [
            'ok' => $ok,
            'message' => $ok ? 'bots:scan запущен в фоне' : 'Не удалось запустить bots:scan',
            'status' => $this->snapshot(),
        ];
    }

    /**
     * @return array{ok:bool,message?:string,killed?:int,error?:string}
     */
    private function killQueueDefault(): array
    {
        $pids = $this->defaultQueuePids();
        if ($pids === []) {
            return ['ok' => true, 'message' => 'Queue worker не был запущен', 'killed' => 0, 'error' => 'not_running'];
        }

        return ['ok' => true, 'killed' => ArtisanProcessFinder::terminatePids($pids), 'message' => 'Queue worker остановлен'];
    }

    /**
     * @return array{ok:bool,message?:string,status?:array<string,mixed>,error?:string}
     */
    private function startQueueDefault(): array
    {
        if ($this->defaultQueuePids() !== []) {
            return ['ok' => true, 'message' => 'Queue worker уже запущен'];
        }

        $log = storage_path('logs/queue.log');
        $ok = ArtisanProcessFinder::spawnBackground([
            PHP_BINARY,
            'artisan',
            'queue:work',
            'database',
            '--queue=mirror,default',
            '--sleep=1',
            '--tries=3',
            '--max-time=3600',
            '--timeout=180',
        ], $log);

        return [
            'ok' => $ok,
            'message' => $ok ? 'Queue worker запущен' : 'Не удалось запустить queue worker',
            'status' => $this->snapshot(),
        ];
    }

    /**
     * @return array{ok:bool,message?:string,killed?:int,error?:string}
     */
    private function killTelegramQueue(): array
    {
        $pids = $this->telegramProcesses->queueWorkerPids();
        if ($pids === []) {
            return ['ok' => true, 'killed' => 0, 'error' => 'not_running'];
        }

        return ['ok' => true, 'killed' => $this->telegramProcesses->stopQueueWorker()];
    }

    /**
     * @return array{ok:bool,message?:string,status?:array<string,mixed>}
     */
    private function startTelegramQueue(): array
    {
        $ok = $this->telegramProcesses->startQueueWorker();

        return [
            'ok' => $ok,
            'message' => $ok ? 'telegram-analyst queue worker запущен' : 'Не удалось запустить worker',
            'status' => $this->snapshot(),
        ];
    }

    /**
     * @return array{ok:bool,message?:string,killed?:int,error?:string}
     */
    private function killTelegramPoll(?int $botId): array
    {
        $botId = $botId ?? $this->defaultTelegramAnalystBotId();
        if ($botId === null) {
            return ['ok' => false, 'error' => 'no_analyst_bot'];
        }

        return ['ok' => true, 'killed' => $this->telegramProcesses->stopPoll($botId)];
    }

    /**
     * @return array{ok:bool,message?:string,status?:array<string,mixed>,error?:string}
     */
    private function startTelegramPoll(?int $botId): array
    {
        $botId = $botId ?? $this->defaultTelegramAnalystBotId();
        if ($botId === null) {
            return ['ok' => false, 'error' => 'no_analyst_bot'];
        }

        $ok = $this->telegramProcesses->startPoll($botId);

        return [
            'ok' => $ok,
            'message' => $ok ? 'Telegram poll запущен' : 'Не удалось запустить poll',
            'status' => $this->snapshot(),
        ];
    }

    /**
     * @return array{ok:bool,message?:string,killed?:int,error?:string}
     */
    private function killProbeLoop(): array
    {
        $pids = ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => str_contains($cmd, 'proxies:probe-loop'));
        if ($pids === []) {
            return ['ok' => true, 'killed' => 0, 'error' => 'not_running'];
        }

        return ['ok' => true, 'killed' => ArtisanProcessFinder::terminatePids($pids)];
    }

    /**
     * @return array{ok:bool,message?:string,status?:array<string,mixed>}
     */
    private function startProbeLoop(): array
    {
        if (ArtisanProcessFinder::findArtisanPids(static fn (string $cmd): bool => str_contains($cmd, 'proxies:probe-loop')) !== []) {
            return ['ok' => true, 'message' => 'probe-loop уже запущен'];
        }

        $log = storage_path('logs/proxy-probe.log');
        $ok = ArtisanProcessFinder::spawnBackground([PHP_BINARY, 'artisan', 'proxies:probe-loop'], $log);

        return [
            'ok' => $ok,
            'message' => $ok ? 'proxies:probe-loop запущен' : 'Не удалось запустить probe-loop',
            'status' => $this->snapshot(),
        ];
    }

    /**
     * @return array{ok:bool,message?:string,killed?:int,error?:string}
     */
    private function killPocketProxy(): array
    {
        $scriptPath = base_path('bot/pocket-options-proxy.py');
        $pids = ArtisanProcessFinder::findCommandPids(static function (string $cmd) use ($scriptPath): bool {
            return str_contains($cmd, $scriptPath)
                && preg_match('#(?:^|/)(python\d*(?:\.\d+)?|'.preg_quote(base_path('bot/.venv/bin/python'), '#').')#', $cmd) === 1;
        });
        if ($pids === []) {
            return ['ok' => true, 'killed' => 0, 'error' => 'not_running'];
        }

        return [
            'ok' => true,
            'killed' => ArtisanProcessFinder::terminatePids($pids),
            'message' => 'Python-прокси остановлен. Запустите: ./start-all.sh --restart-proxy',
        ];
    }
}
