<?php

namespace App\Services\Telegram;

use App\Support\ChartAnalystBotTypes;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Support\ArtisanProcessFinder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Запуск/остановка фоновых процессов Telegram Chart Analyst:
 * queue:work (анализ) и telegram:analyst-poll (long polling).
 *
 * Важно: php-fpm (www-data) не может убить worker, запущенный от CLI/cron (user o).
 * В этом случае requestDeferredRestart() + cron telegram:ensure-analyst-services.
 */
class TelegramAnalystProcessManager
{
    /** Таймаут queue worker для OpenRouter-анализа (сек). */
    public const QUEUE_TIMEOUT_SEC = 600;

    public const RESTART_FLAG_RELATIVE = 'framework/telegram-analyst-restart.flag';

    /**
     * @return array{
     *   ok:bool,
     *   poll_running:bool,
     *   queue_running:bool,
     *   poll_pids:list<int>,
     *   queue_pids:list<int>,
     *   restart_pending?:bool
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
            'restart_pending' => $this->hasDeferredRestartRequest(),
        ];
    }

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
     * @return array{queue_running:bool,bots:list<array{bot_id:int,poll_running:bool}>,deferred_restart?:bool}
     */
    public function ensureConfiguredServices(): array
    {
        $deferred = $this->processDeferredRestartIfRequested();

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
            'deferred_restart' => $deferred !== null,
        ];
    }

    /**
     * Запрос отложенного перезапуска (cron под user CLI выполнит kill+start).
     */
    public function requestDeferredRestart(?int $botId = null): void
    {
        $path = storage_path(self::RESTART_FLAG_RELATIVE);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'bot_id' => $botId,
            'requested_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function hasDeferredRestartRequest(): bool
    {
        return File::exists(storage_path(self::RESTART_FLAG_RELATIVE));
    }

    /**
     * Выполняет отложенный перезапуск (вызывается из cron под user CLI).
     *
     * @return array<string, mixed>|null
     */
    public function processDeferredRestartIfRequested(): ?array
    {
        $path = storage_path(self::RESTART_FLAG_RELATIVE);
        if (! File::exists($path)) {
            return null;
        }

        $payload = json_decode((string) File::get($path), true);
        File::delete($path);

        $botId = isset($payload['bot_id']) ? (int) $payload['bot_id'] : null;
        $bot = $botId > 0 ? Bot::query()->find($botId) : null;

        if ($bot !== null && ChartAnalystBotTypes::isAnalyst($bot->type)) {
            $result = $this->restart($bot);
        } else {
            $result = $this->restartQueueWorkerHard();
            foreach ($this->analystBotsWithTelegramToken() as $analystBot) {
                $beforePoll = $this->pollPids($analystBot->id);
                $this->stopProcessesHard($beforePoll);
                $this->startPoll($analystBot->id);
            }
        }

        Log::info('telegram_analyst_deferred_restart_done', [
            'bot_id' => $botId,
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * @return array{ok:bool,stopped:int,started:bool,queue_running:bool,queue_pids:list<int>,same_pids?:bool,message?:string,deferred?:bool}
     */
    public function restartQueueWorkerHard(): array
    {
        $before = $this->queueWorkerPids();
        $stopped = $this->stopProcessesHard($before);
        $still = array_values(array_intersect($before, $this->queueWorkerPids()));

        if ($still !== []) {
            $this->requestDeferredRestart();

            return [
                'ok' => false,
                'stopped' => $stopped,
                'started' => false,
                'queue_running' => true,
                'queue_pids' => $still,
                'same_pids' => true,
                'deferred' => true,
                'message' => $this->deferredRestartMessage($still, []),
            ];
        }

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
     * @return array{ok:bool,error?:string,status?:array<string,mixed>,stopped?:array<string,int>,started?:array<string,bool>,message?:string,deferred?:bool}
     */
    public function restart(Bot $bot): array
    {
        if (! ChartAnalystBotTypes::isAnalyst($bot->type)) {
            return ['ok' => false, 'error' => 'unsupported_bot_type'];
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $hasToken = trim((string) ($cfg['telegram_bot_token'] ?? '')) !== '';

        $beforeQueue = $this->queueWorkerPids();
        $beforePoll = $hasToken ? $this->pollPids($bot->id) : [];

        $stopped = [
            'poll' => $hasToken ? $this->stopProcessesHard($beforePoll) : 0,
            'queue' => $this->stopProcessesHard($beforeQueue),
        ];

        $stillPoll = array_values(array_intersect($beforePoll, $this->pollPids($bot->id)));
        $stillQueue = array_values(array_intersect($beforeQueue, $this->queueWorkerPids()));

        if ($stillPoll !== [] || $stillQueue !== []) {
            $this->requestDeferredRestart($bot->id);

            return [
                'ok' => true,
                'deferred' => true,
                'error' => 'worker_kill_failed',
                'stopped' => $stopped,
                'status' => $this->status($bot->id),
                'message' => $this->deferredRestartMessage($stillQueue, $stillPoll),
                'stale_queue_pids' => $stillQueue,
                'stale_poll_pids' => $stillPoll,
            ];
        }

        $started = [
            'queue' => $this->startQueueWorker(),
            'poll' => $hasToken ? $this->startPoll($bot->id) : true,
        ];

        usleep(300_000);
        $status = $this->status($bot->id);

        if (! ($started['queue'] && $started['poll'] && $status['queue_running'] && ($status['poll_running'] || ! $hasToken))) {
            return [
                'ok' => false,
                'error' => 'start_failed',
                'stopped' => $stopped,
                'started' => $started,
                'status' => $status,
                'message' => 'Не удалось подтвердить запуск poll/queue после перезапуска.',
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
     * @return \Illuminate\Support\Collection<int, Bot>
     */
    private function analystBotsWithTelegramToken()
    {
        return Bot::query()
            ->whereIn('type', ChartAnalystBotTypes::all())
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
     * @return list<int>
     */
    public function pollPids(int $botId, ?int $exceptPid = null): array
    {
        $needle = 'telegram:analyst-poll';
        $botFlag = '--bot='.$botId;

        return ArtisanProcessFinder::findArtisanPids(
            static fn (string $cmdline): bool => str_contains($cmdline, $needle) && str_contains($cmdline, $botFlag),
            $exceptPid,
        );
    }

    /**
     * @return list<int>
     */
    public function queueWorkerPids(?int $exceptPid = null): array
    {
        return ArtisanProcessFinder::findArtisanPids(
            static fn (string $cmdline): bool => self::isAnalystQueueWorkerCmdline($cmdline),
            $exceptPid,
        );
    }

    private static function isAnalystQueueWorkerCmdline(string $cmdline): bool
    {
        if (! str_contains($cmdline, 'queue:work')) {
            return false;
        }
        if (str_contains($cmdline, RunTelegramAnalystAnalysisJob::QUEUE_NAME)) {
            return true;
        }

        return str_contains($cmdline, '--timeout='.self::QUEUE_TIMEOUT_SEC)
            && ! str_contains($cmdline, '--queue=');
    }

    public function stopPoll(int $botId): int
    {
        return $this->stopProcessesHard($this->pollPids($botId));
    }

    public function stopQueueWorker(): int
    {
        return $this->stopProcessesHard($this->queueWorkerPids());
    }

    /**
     * SIGTERM → wait → SIGKILL → wait.
     *
     * @param  list<int>  $pids
     */
    private function stopProcessesHard(array $pids): int
    {
        if ($pids === []) {
            return 0;
        }

        $killed = ArtisanProcessFinder::terminatePids($pids);
        ArtisanProcessFinder::waitUntilPidsGone($pids, 2500);

        $stillAlive = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && is_readable("/proc/{$pid}/cmdline")) {
                $stillAlive[] = $pid;
            }
        }

        if ($stillAlive !== []) {
            $killed += ArtisanProcessFinder::killPids($stillAlive);
            ArtisanProcessFinder::waitUntilPidsGone($stillAlive, 1500);
        }

        return $killed;
    }

    public function startPoll(int $botId): bool
    {
        if ($this->pollPids($botId) !== []) {
            return true;
        }

        $log = storage_path('logs/telegram-analyst-poll-'.$botId.'.log');

        return ArtisanProcessFinder::spawnBackground([
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

        return ArtisanProcessFinder::spawnBackground([
            ArtisanProcessFinder::cliPhpBinary(),
            'artisan',
            'queue:work',
            '--queue='.RunTelegramAnalystAnalysisJob::QUEUE_NAME,
            '--timeout='.self::QUEUE_TIMEOUT_SEC,
        ], $log);
    }

    /**
     * @param  list<int>  $queuePids
     * @param  list<int>  $pollPids
     */
    private function deferredRestartMessage(array $queuePids, array $pollPids): string
    {
        $parts = [];
        if ($queuePids !== []) {
            $parts[] = 'queue PID '.implode(',', $queuePids);
        }
        if ($pollPids !== []) {
            $parts[] = 'poll PID '.implode(',', $pollPids);
        }

        $detail = $parts !== [] ? implode('; ', $parts) : 'процессы';

        // Не ошибка: worker/poll подняты cron/CLI (user o), php-fpm (www-data) не может послать SIGTERM.
        // Флаг telegram-analyst-restart.flag подхватывает telegram:ensure-analyst-services (~1 мин).
        return 'Прямой перезапуск из браузера недоступен для '.$detail.' (процессы запущены от пользователя ОС, не от веб-сервера). '
            .'Запрос на перезапуск поставлен в очередь cron — обычно завершается за 1 минуту. '
            .'Статус обновится автоматически.';
    }
}
