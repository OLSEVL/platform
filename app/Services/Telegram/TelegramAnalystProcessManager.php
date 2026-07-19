<?php

namespace App\Services\Telegram;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Support\ArtisanProcessFinder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Запуск/остановка фоновых процессов Telegram Chart Analyst:
 * queue:work (анализ) и telegram:analyst-poll (long polling).
 * Ищем процессы по cmdline в /proc (как bots:scan в routes/console.php).
 */
class TelegramAnalystProcessManager
{
    /** Таймаут queue worker для OpenRouter-анализа (сек). */
    public const QUEUE_TIMEOUT_SEC = 600;

    /**
     * @return array{
     *   ok:bool,
     *   poll_running:bool,
     *   queue_running:bool,
     *   poll_pids:list<int>,
     *   queue_pids:list<int>,
     *   message?:string
     * }
     */
    public function status(int $botId): array
    {
        $pollPids = $this->pollPids($botId);
        $queuePids = $this->queueWorkerPids();

        return [
            'ok' => true,
            'poll_running' => $pollPids !== [],
            'queue_running' => $queuePids !== [],
            'poll_pids' => $pollPids,
            'queue_pids' => $queuePids,
        ];
    }

    /**
     * Поднять poll Chart Analyst, если не запущен (/menu, callback инструментов).
     */
    public function ensurePollRunning(int $botId): bool
    {
        if ($this->pollPids($botId) !== []) {
            return true;
        }

        $started = $this->startPoll($botId);
        if (! $started) {
            Log::warning('telegram_analyst_poll_start_failed', ['bot_id' => $botId]);

            return false;
        }

        usleep(300_000);

        return $this->pollPids($botId) !== [];
    }

    /**
     * Поднять queue worker telegram-analyst, если не запущен.
     */
    public function ensureQueueRunning(): bool
    {
        if ($this->queueWorkerPids() !== []) {
            return true;
        }

        $started = $this->startQueueWorker();
        if (! $started) {
            Log::warning('telegram_analyst_queue_start_failed');

            return false;
        }

        usleep(300_000);

        return $this->queueWorkerPids() !== [];
    }

    /**
     * Poll + queue для всех openrouter_llm_analyst с telegram_bot_token (cron watchdog).
     *
     * @return array{queue_running:bool,bots:list<array{bot_id:int,poll_running:bool}>}
     */
    public function ensureConfiguredServices(): array
    {
        $queueRunning = $this->ensureQueueRunning();
        $bots = [];

        foreach ($this->analystBotsWithTelegramToken() as $bot) {
            $pollRunning = $this->ensurePollRunning($bot->id);
            $bots[] = [
                'bot_id' => $bot->id,
                'poll_running' => $pollRunning,
            ];
        }

        return [
            'queue_running' => $queueRunning,
            'bots' => $bots,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Bot>
     */
    private function analystBotsWithTelegramToken()
    {
        return Bot::query()
            ->where('type', 'openrouter_llm_analyst')
            ->orderBy('id')
            ->get()
            ->filter(function (Bot $bot): bool {
                /** @var array<string, mixed> $cfg */
                $cfg = is_array($bot->config) ? $bot->config : [];

                return trim((string) ($cfg['telegram_bot_token'] ?? '')) !== '';
            })
            ->values();
    }

    /**
     * Жёсткий перезапуск только queue worker telegram-analyst (без poll).
     * Нужен после деплоя/сохранения настроек гибрида: мягкий queue:restart
     * не всегда убивает долгоживущий worker, и старый код продолжает крутить jobs.
     *
     * @return array{ok:bool,stopped:int,started:bool,queue_running:bool,queue_pids:list<int>}
     */
    public function restartQueueWorkerHard(): array
    {
        $stopped = $this->stopQueueWorker();
        usleep(400_000);
        $started = $this->startQueueWorker();
        usleep(300_000);
        $pids = $this->queueWorkerPids();

        if ($pids === []) {
            Log::warning('telegram_analyst_queue_hard_restart_failed', [
                'stopped' => $stopped,
                'started' => $started,
            ]);
        }

        return [
            'ok' => $pids !== [],
            'stopped' => $stopped,
            'started' => $started,
            'queue_running' => $pids !== [],
            'queue_pids' => $pids,
        ];
    }

    /**
     * Останавливает poll + queue worker и поднимает заново (подхватывает config из БД).
     *
     * @return array{ok:bool,error?:string,status?:array<string,mixed>,stopped?:array<string,int>,started?:array<string,bool>}
     */
    public function restart(Bot $bot): array
    {
        if ($bot->type !== 'openrouter_llm_analyst') {
            return ['ok' => false, 'error' => 'unsupported_bot_type'];
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (trim((string) ($cfg['telegram_bot_token'] ?? '')) === '') {
            // Без token poll не нужен — всё равно поднимаем queue (гибрид paper/PO).
            $queue = $this->restartQueueWorkerHard();
            if (! $queue['ok']) {
                return [
                    'ok' => false,
                    'error' => 'queue_start_failed',
                    'status' => $this->status($bot->id),
                    'message' => 'Не удалось перезапустить queue telegram-analyst.',
                ];
            }

            return [
                'ok' => true,
                'status' => $this->status($bot->id),
                'message' => 'Queue telegram-analyst перезапущен (poll без token не стартовал).',
            ];
        }

        $stopped = [
            'poll' => $this->stopPoll($bot->id),
            'queue' => $this->stopQueueWorker(),
        ];

        // Даём процессам завершиться после SIGTERM.
        usleep(400_000);

        $started = [
            'queue' => $this->startQueueWorker(),
            'poll' => $this->startPoll($bot->id),
        ];

        $status = $this->status($bot->id);
        if (! ($started['poll'] && $started['queue'])) {
            return [
                'ok' => false,
                'error' => 'start_failed',
                'stopped' => $stopped,
                'started' => $started,
                'status' => $status,
            ];
        }

        return [
            'ok' => true,
            'stopped' => $stopped,
            'started' => $started,
            'status' => $status,
            'message' => 'Процессы перезапущены. Poll и queue worker подхватили настройки из БД.',
        ];
    }

    /**
     * @return list<int>
     */
    public function pollPids(int $botId, ?int $exceptPid = null): array
    {
        $needle = 'telegram:analyst-poll';
        $botFlag = '--bot='.$botId;

        return $this->findArtisanPids(static function (string $cmdline) use ($needle, $botFlag): bool {
            return str_contains($cmdline, $needle) && str_contains($cmdline, $botFlag);
        }, $exceptPid);
    }

    /**
     * @return list<int>
     */
    public function queueWorkerPids(?int $exceptPid = null): array
    {
        return $this->findArtisanPids(static function (string $cmdline): bool {
            return self::isAnalystQueueWorkerCmdline($cmdline);
        }, $exceptPid);
    }

    /**
     * Воркер очереди telegram-analyst или устаревший запуск без --queue= (не обрабатывает jobs).
     */
    private static function isAnalystQueueWorkerCmdline(string $cmdline): bool
    {
        if (! str_contains($cmdline, 'queue:work')) {
            return false;
        }
        if (str_contains($cmdline, RunTelegramAnalystAnalysisJob::QUEUE_NAME)) {
            return true;
        }
        // Старый вариант: queue:work --timeout=600 без --queue=telegram-analyst (jobs зависают).
        return str_contains($cmdline, '--timeout='.self::QUEUE_TIMEOUT_SEC)
            && ! str_contains($cmdline, '--queue=');
    }

    public function stopPoll(int $botId): int
    {
        return $this->terminatePids($this->pollPids($botId));
    }

    public function stopQueueWorker(): int
    {
        return $this->terminatePids($this->queueWorkerPids());
    }

    public function startPoll(int $botId): bool
    {
        if ($this->pollPids($botId) !== []) {
            return true;
        }

        $log = storage_path('logs/telegram-analyst-poll-'.$botId.'.log');
        File::ensureDirectoryExists(dirname($log));

        return $this->spawnBackground([
            ArtisanProcessFinder::cliPhpBinary(),
            'artisan',
            'telegram:analyst-poll',
            '--bot='.$botId,
        ], $log);
    }

    public function startQueueWorker(): bool
    {
        if ($this->queueWorkerPids() !== []) {
            return true;
        }

        $log = storage_path('logs/telegram-analyst-queue.log');
        File::ensureDirectoryExists(dirname($log));

        return $this->spawnBackground([
            ArtisanProcessFinder::cliPhpBinary(),
            'artisan',
            'queue:work',
            '--queue='.RunTelegramAnalystAnalysisJob::QUEUE_NAME,
            '--timeout='.self::QUEUE_TIMEOUT_SEC,
        ], $log);
    }

    /**
     * @param  list<string>  $command
     */
    private function spawnBackground(array $command, string $logPath): bool
    {
        return ArtisanProcessFinder::spawnBackground($command, $logPath);
    }

    /**
     * @param  callable(string):bool  $matcher
     * @return list<int>
     */
    private function findArtisanPids(callable $matcher, ?int $exceptPid = null): array
    {
        $exceptPid ??= getmypid();
        $pids = [];

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            if (! preg_match('#/proc/(\d+)/cmdline#', $path, $matches)) {
                continue;
            }
            $pid = (int) $matches[1];
            if ($pid === $exceptPid) {
                continue;
            }
            $cmdline = @file_get_contents($path);
            if ($cmdline === false) {
                continue;
            }
            $cmdline = str_replace("\0", ' ', $cmdline);
            if (! preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+#', $cmdline)) {
                continue;
            }
            if ($matcher($cmdline)) {
                $pids[] = $pid;
            }
        }

        sort($pids);

        return $pids;
    }

    /**
     * @param  list<int>  $pids
     */
    private function terminatePids(array $pids): int
    {
        $killed = 0;
        foreach ($pids as $pid) {
            if ($pid <= 0) {
                continue;
            }
            if ($this->signalTerm($pid)) {
                $killed++;
            }
        }

        return $killed;
    }

    /** SIGTERM: в PHP-FPM константа часто не определена — используем 15 (Linux). */
    private function signalTerm(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            $sig = defined('SIGTERM') ? (int) constant('SIGTERM') : 15;
            if (@posix_kill($pid, $sig)) {
                return true;
            }
        }

        exec(sprintf('kill -TERM %d 2>/dev/null', $pid), $_, $exitCode);

        return $exitCode === 0;
    }
}
