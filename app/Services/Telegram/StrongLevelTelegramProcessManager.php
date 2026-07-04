<?php

namespace App\Services\Telegram;

use App\Jobs\RunTelegramAnalystAnalysisJob;
use App\Models\Bot;
use App\Support\ArtisanProcessFinder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Long polling getUpdates для Strong Level (inline-кнопка LLM-анализа).
 */
class StrongLevelTelegramProcessManager
{
    /**
     * @return array{ok:bool,poll_running:bool,poll_pids:list<int>,queue_running:bool,queue_pids:list<int>}
     */
    public function status(int $botId): array
    {
        $analystQueue = app(TelegramAnalystProcessManager::class);

        return [
            'ok' => true,
            'poll_running' => $this->pollPids($botId) !== [],
            'poll_pids' => $this->pollPids($botId),
            'queue_running' => $analystQueue->queueWorkerPids() !== [],
            'queue_pids' => $analystQueue->queueWorkerPids(),
        ];
    }

    /**
     * Поднять poll, если не запущен (вызывается при отправке алерта с LLM-кнопкой).
     */
    public function ensurePollRunning(int $botId): bool
    {
        if ($this->pollPids($botId) !== []) {
            return true;
        }

        $started = $this->startPoll($botId);
        if (! $started) {
            Log::warning('strong_level_telegram_poll_start_failed', ['bot_id' => $botId]);

            return false;
        }

        usleep(300_000);

        return $this->pollPids($botId) !== [];
    }

    /**
     * @return array{ok:bool,error?:string,status?:array<string,mixed>,message?:string}
     */
    public function restart(Bot $bot): array
    {
        if ($bot->type !== 'strong_level') {
            return ['ok' => false, 'error' => 'unsupported_bot_type'];
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (trim((string) ($cfg['telegram_bot_token'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        $analystQueue = app(TelegramAnalystProcessManager::class);
        $this->stopPoll($bot->id);
        $analystQueue->stopQueueWorker();
        usleep(400_000);

        $queueStarted = $analystQueue->startQueueWorker();
        $pollStarted = $this->startPoll($bot->id);
        usleep(300_000);
        $status = $this->status($bot->id);

        if (! ($status['poll_running'] && $queueStarted)) {
            return [
                'ok' => false,
                'error' => 'start_failed',
                'status' => $status,
                'message' => $pollStarted
                    ? 'Poll не подтвердился в /proc — проверьте storage/logs/telegram-strong-level-poll-'.$bot->id.'.log'
                    : 'Не удалось запустить poll',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'message' => 'Poll Strong Level и queue telegram-analyst перезапущены.',
        ];
    }

    /**
     * @return list<int>
     */
    public function pollPids(int $botId, ?int $exceptPid = null): array
    {
        $needle = 'telegram:strong-level-poll';
        $botFlag = '--bot='.$botId;

        return ArtisanProcessFinder::findArtisanPids(
            static fn (string $cmdline): bool => str_contains($cmdline, $needle) && str_contains($cmdline, $botFlag),
            $exceptPid,
        );
    }

    public function stopPoll(int $botId): int
    {
        return ArtisanProcessFinder::terminatePids($this->pollPids($botId));
    }

    public function startPoll(int $botId): bool
    {
        if ($this->pollPids($botId) !== []) {
            return true;
        }

        $log = storage_path('logs/telegram-strong-level-poll-'.$botId.'.log');
        File::ensureDirectoryExists(dirname($log));

        return ArtisanProcessFinder::spawnBackground([
            ArtisanProcessFinder::cliPhpBinary(),
            'artisan',
            'telegram:strong-level-poll',
            '--bot='.$botId,
        ], $log);
    }
}
