<?php

namespace App\Services\Telegram;

use App\Models\Bot;
use App\Support\ArtisanProcessFinder;
use App\Support\BotScanCoordinator;
use App\Support\EngulfingLiquidityConfig;
use App\Support\ImpulseLvl1minConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Long polling getUpdates: LLM-кнопки Strong Level и /start автоподписка
 * (в том числе Sweep / Raid, Engulfing, SMC Flow и Strong Trend + DeMarker).
 */
class StrongLevelTelegramProcessManager
{
    public const RESTART_FLAG_RELATIVE = 'framework/telegram-strong-level-restart.flag';

    /**
     * Poll getUpdates нужен для LLM-кнопок и для /start автоподписки.
     */
    public static function pollSupported(string $botType): bool
    {
        return StrongLevelTelegramLlmButton::supportsBotType($botType)
            || in_array($botType, ['strong_trend_demarker', \App\Support\DemarkerDualFastConfig::BOT_TYPE, 'sweep_raid_liquidity', 'engulfing_liquidity', 'smc_flow'], true);
    }

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
            'restart_pending' => $this->hasDeferredRestartRequest($botId),
        ];
    }

    /**
     * Поднять poll, если не запущен (вызывается при отправке алерта с LLM-кнопкой / автоподпиской).
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
     * @return array{ok:bool,error?:string,status?:array<string,mixed>,message?:string,deferred?:bool}
     */
    public function restart(Bot $bot): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        if (trim((string) ($cfg['telegram_bot_token'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        // Poll нужен и для LLM-кнопок, и для /start автоподписки на любом Telegram-боте.
        if (
            ! self::pollSupported($bot->type)
            && ! TelegramChatAccess::autoSubscribeEnabled($cfg)
            && ! (bool) ($cfg['telegram_enabled'] ?? false)
        ) {
            return ['ok' => false, 'error' => 'unsupported_bot_type'];
        }

        $needsAnalystQueue = StrongLevelTelegramLlmButton::supportsBotType($bot->type);
        $analystQueue = app(TelegramAnalystProcessManager::class);
        $beforePoll = $this->pollPids($bot->id);
        $this->stopProcessesHard($beforePoll);
        if ($needsAnalystQueue) {
            $analystQueue->stopQueueWorker();
        }
        usleep(400_000);

        // php-fpm (www-data) не может убить poll, запущенный от CLI (user o) —
        // иначе UI «перезапуск» врёт, а в памяти остаётся старый код без автоподписки.
        $stillPoll = $this->pollPids($bot->id);
        if ($stillPoll !== []) {
            // Всегда просим cron и live: алерты Engulfing/Impulse иначе останутся со старым кодом.
            $this->requestDeferredRestart($bot->id, true);
            if ($needsAnalystQueue) {
                $analystQueue->requestDeferredRestart($bot->id);
            }
            $live = $this->restartLiveScanIfNeeded($bot);

            return [
                'ok' => true,
                'deferred' => true,
                'status' => $this->status($bot->id),
                'live' => $live,
                'message' => 'Poll запущен от другого пользователя ОС — прямой kill из браузера недоступен. '
                    .'Запрос на перезапуск поставлен в cron (обычно до 1 мин). '
                    .(($live['message'] ?? '') !== '' ? ' '.$live['message'] : ''),
            ];
        }

        $queueStarted = $needsAnalystQueue ? $analystQueue->startQueueWorker() : true;
        $pollStarted = $this->startPoll($bot->id);
        // Chart Analyst poll (/menu) общий для LLM-кнопок — после перезапуска queue поднимаем и его.
        if ($needsAnalystQueue) {
            $analystQueue->ensureConfiguredServices();
        }
        usleep(300_000);
        $status = $this->status($bot->id);

        // Engulfing / Impulse + continuous_scan: алерты шлёт bots:impulse-live, не telegram poll.
        // Без kill live PHP продолжает слать старый формат из памяти.
        $live = $this->restartLiveScanIfNeeded($bot);
        if (($live['deferred'] ?? false) === true) {
            $this->requestDeferredRestart($bot->id, true);
        }

        if (! $status['poll_running'] || ($needsAnalystQueue && ! $queueStarted)) {
            return [
                'ok' => false,
                'error' => 'start_failed',
                'status' => $status,
                'live' => $live,
                'message' => $pollStarted
                    ? 'Poll не подтвердился в /proc — проверьте storage/logs/telegram-strong-level-poll-'.$bot->id.'.log'
                    : 'Не удалось запустить poll',
            ];
        }

        $botLabel = self::settingsLabelForBotType($bot->type);
        $parts = [];
        if ($needsAnalystQueue) {
            $parts[] = 'Poll '.$botLabel.' и queue telegram-analyst перезапущены';
        } else {
            $parts[] = 'Poll '.$botLabel.' перезапущен — /start будет добавлять чаты в подписчики';
        }
        if ($live !== null) {
            $parts[] = (string) ($live['message'] ?? '');
        }

        return [
            'ok' => true,
            'status' => $status,
            'live' => $live,
            'message' => trim(implode(' ', array_filter($parts))),
        ];
    }

    public function requestDeferredRestart(int $botId, bool $restartLive = false): void
    {
        $path = storage_path(self::RESTART_FLAG_RELATIVE);
        File::ensureDirectoryExists(dirname($path));
        $existing = [];
        if (File::exists($path)) {
            $decoded = json_decode((string) File::get($path), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        File::put($path, json_encode([
            'bot_id' => $botId,
            // Не теряем флаг live, если poll и live просили restart подряд.
            'restart_live' => $restartLive || (bool) ($existing['restart_live'] ?? false),
            'requested_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Перезапуск bots:impulse-live / bots:scan для ботов с continuous_scan.
     * null — live не нужен (обычный batch).
     *
     * @return array<string, mixed>|null
     */
    public function restartLiveScanIfNeeded(Bot $bot): ?array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($bot->config) ? $bot->config : [];
        $needsLive = (ImpulseLvl1minConfig::is((string) $bot->type) && ImpulseLvl1minConfig::continuousScanEnabled($cfg))
            || (EngulfingLiquidityConfig::is((string) $bot->type) && EngulfingLiquidityConfig::continuousScanEnabled($cfg));
        if (! $needsLive) {
            return null;
        }

        return BotScanCoordinator::restartScan((int) $bot->id);
    }

    public function hasDeferredRestartRequest(?int $botId = null): bool
    {
        $path = storage_path(self::RESTART_FLAG_RELATIVE);
        if (! File::exists($path)) {
            return false;
        }
        if ($botId === null) {
            return true;
        }
        $payload = json_decode((string) File::get($path), true);

        return is_array($payload) && (int) ($payload['bot_id'] ?? 0) === $botId;
    }

    /**
     * Cron (CLI user) убивает чужие www-data/старые poll и поднимает свежий код.
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

        $botId = isset($payload['bot_id']) ? (int) $payload['bot_id'] : 0;
        if ($botId <= 0) {
            return null;
        }

        $bot = Bot::query()->find($botId);
        if ($bot === null) {
            return null;
        }

        $before = $this->pollPids($botId);
        $this->stopProcessesHard($before);
        usleep(300_000);
        // Если PID всё ещё жив — принудительно стартуем не получится; логируем.
        $still = $this->pollPids($botId);
        if ($still !== []) {
            Log::warning('telegram_strong_level_deferred_restart_still_alive', [
                'bot_id' => $botId,
                'pids' => $still,
            ]);
        } else {
            $this->startPoll($botId);
        }

        // CLI-cron может убить impulse-live от user o — подхватываем свежий код алертов.
        $restartLive = (bool) ($payload['restart_live'] ?? false);
        $live = null;
        if ($restartLive) {
            $live = $this->restartLiveScanIfNeeded($bot);
        }

        $result = $this->status($botId);
        $result['live'] = $live;
        Log::info('telegram_strong_level_deferred_restart_done', [
            'bot_id' => $botId,
            'status' => $result,
            'restart_live' => $restartLive,
        ]);

        return $result;
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
        return $this->stopProcessesHard($this->pollPids($botId));
    }

    /**
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

        $log = storage_path('logs/telegram-strong-level-poll-'.$botId.'.log');
        File::ensureDirectoryExists(dirname($log));

        return ArtisanProcessFinder::spawnBackground([
            ArtisanProcessFinder::cliPhpBinary(),
            'artisan',
            'telegram:strong-level-poll',
            '--bot='.$botId,
        ], $log);
    }

    public static function settingsLabelForBotType(string $type): string
    {
        return match ($type) {
            'strong_trend_telegram' => 'Strong Trend Telegram',
            'strong_trend_demarker' => 'Strong Trend + DeMarker',
            \App\Support\DemarkerDualFastConfig::BOT_TYPE => 'Dual DeMarker Fast',
            'sweep_raid_liquidity' => 'Sweep / Raid Liquidity',
            'engulfing_liquidity' => 'Engulfing Liquidity',
            'smc_flow' => 'SMC Flow',
            'candle_reversal_confirm' => 'Candle Reversal + Confirm',
            'rsi_contrtrend' => 'RSI Contrtrend',
            'bollinger_contrtrend' => 'Bollinger Contrtrend',
            'pinbar_trade' => 'PinBar Trade',
            'williams_range_trade' => 'Williams Range Trade',
            'three_soldier' => 'Three Soldier',
            \App\Support\MaxBotConfig::BOT_TYPE => 'MaxBot',
            \App\Support\ImpulseLvl1minConfig::BOT_TYPE => \App\Support\ImpulseLvl1minConfig::TITLE,
            \App\Support\ImpulseLvl1minConfig::BOT_TYPE_S15 => \App\Support\ImpulseLvl1minConfig::TITLE_S15,
            'cci_trade' => 'CCI Trade',
            'rsi_trade' => 'RSI Trade',
            'rvi_trade' => 'RVI Trade',
            default => 'Strong Level',
        };
    }
}
