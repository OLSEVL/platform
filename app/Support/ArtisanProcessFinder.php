<?php

namespace App\Support;

/**
 * Поиск процессов по cmdline в /proc (как bots:scan и TelegramAnalystProcessManager).
 * Отдельный класс — единая точка для диагностики и будущих daemon-менеджеров.
 */
final class ArtisanProcessFinder
{
    /**
     * @return list<int>
     */
    public static function findArtisanPids(callable $matcher, ?int $exceptPid = null): array
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
            if (! self::cmdlineLooksLikePhpArtisan($cmdline)) {
                continue;
            }
            if ($matcher($cmdline)) {
                $pids[] = $pid;
            }
        }

        sort($pids);

        return self::refilterLivingPids($pids, static function (string $cmdline) use ($matcher): bool {
            return self::cmdlineLooksLikePhpArtisan($cmdline) && $matcher($cmdline);
        });
    }

    /**
     * Повторная проверка cmdline: процесс мог завершиться между glob и чтением,
     * из-за чего в списке мелькают «пустые» PID и статус Error/OK.
     *
     * @param  callable(string):bool  $matcher
     * @return list<int>
     */
    private static function refilterLivingPids(array $pids, callable $matcher): array
    {
        $living = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
            if ($cmdline === false || $cmdline === '') {
                continue;
            }
            $cmdline = str_replace("\0", ' ', $cmdline);
            if ($matcher($cmdline)) {
                $living[] = $pid;
            }
        }

        sort($living);

        return $living;
    }

    /**
     * Любой процесс (Python, bash и т.д.) по подстроке cmdline.
     *
     * @return list<int>
     */
    public static function findCommandPids(callable $matcher, ?int $exceptPid = null): array
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
            if (self::cmdlineIsShellWrapper($cmdline)) {
                continue;
            }
            if ($matcher($cmdline)) {
                $pids[] = $pid;
            }
        }

        sort($pids);

        return self::refilterLivingPids($pids, static function (string $cmdline) use ($matcher): bool {
            return ! self::cmdlineIsShellWrapper($cmdline) && $matcher($cmdline);
        });
    }

    /**
     * @return array{elapsed_sec: ?int, cpu_percent: ?float, mem_percent: ?float, cmdline: ?string}|null
     */
    public static function readPidMetrics(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }

        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (! is_readable($cmdlinePath)) {
            return null;
        }

        $cmdline = str_replace("\0", ' ', (string) file_get_contents($cmdlinePath));
        $cmdline = trim(preg_replace('/\s+/', ' ', $cmdline) ?? '');

        $elapsedSec = self::elapsedSecondsFromProc($pid);
        $cpuMem = self::cpuMemFromPs($pid);

        return [
            'elapsed_sec' => $elapsedSec,
            'cpu_percent' => $cpuMem['cpu_percent'] ?? null,
            'mem_percent' => $cpuMem['mem_percent'] ?? null,
            'cmdline' => $cmdline !== '' ? $cmdline : null,
        ];
    }

    /**
     * @return list<array{pid:int,elapsed_sec:?int,cpu_percent:?float,mem_percent:?float,cmdline:?string}>
     */
    public static function metricsForPids(array $pids): array
    {
        $rows = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            $metrics = self::readPidMetrics($pid);
            if ($metrics === null) {
                continue;
            }
            $rows[] = array_merge(['pid' => $pid], $metrics);
        }

        return $rows;
    }

    /** SIGTERM через posix_kill или kill -TERM. */
    public static function terminatePids(array $pids): int
    {
        $killed = 0;
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            if (self::signalTerm($pid)) {
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * SIGKILL для процессов, не завершившихся после SIGTERM (перезапуск из php-fpm).
     *
     * @param  list<int>  $pids
     */
    public static function killPids(array $pids): int
    {
        $killed = 0;
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            if (self::signalKill($pid)) {
                $killed++;
            }
        }

        return $killed;
    }

    /**
     * Ждём завершения PID (poll /proc), максимум $timeoutMs.
     *
     * @param  list<int>  $pids
     */
    public static function waitUntilPidsGone(array $pids, int $timeoutMs = 2000): void
    {
        if ($pids === [] || $timeoutMs <= 0) {
            return;
        }

        $deadline = microtime(true) + ($timeoutMs / 1000);
        do {
            $alive = [];
            foreach ($pids as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }
                if (is_readable("/proc/{$pid}/cmdline")) {
                    $alive[] = $pid;
                }
            }
            if ($alive === []) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
    }

    /**
     * PHP CLI для фоновых artisan-процессов. В php-fpm PHP_BINARY указывает на php-fpm — не подходит.
     */
    public static function cliPhpBinary(): string
    {
        $binary = PHP_BINARY;
        if (! str_contains(strtolower($binary), 'fpm')) {
            return $binary;
        }

        foreach (['/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php'] as $candidate) {
            if (is_executable($candidate) && ! str_contains($candidate, 'fpm')) {
                return $candidate;
            }
        }

        $which = trim((string) shell_exec('command -v php8.3 2>/dev/null'));
        if ($which !== '' && is_executable($which) && ! str_contains($which, 'fpm')) {
            return $which;
        }

        $which = trim((string) shell_exec('command -v php 2>/dev/null'));
        if ($which !== '' && is_executable($which) && ! str_contains($which, 'fpm')) {
            return $which;
        }

        return 'php';
    }

    /**
     * Лог daemon-процесса: создаём/дописываем через PHP, чтобы shell-redirect не падал
     * с «Permission denied», если файл создан другим пользователем (www-data vs CLI).
     */
    public static function ensureDaemonLogWritable(string $logPath): string
    {
        $dir = dirname($logPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (! is_file($logPath)) {
            @file_put_contents($logPath, '');
        }

        if (is_writable($logPath)) {
            return $logPath;
        }

        @chmod($logPath, 0666);
        if (is_writable($logPath)) {
            return $logPath;
        }

        $uid = function_exists('posix_getuid') ? (int) posix_getuid() : 0;
        $fallback = preg_replace('/\.log$/', '-u'.$uid.'.log', $logPath) ?: $logPath.'.u'.$uid;

        if (! is_file($fallback)) {
            @file_put_contents($fallback, '');
            @chmod($fallback, 0666);
        }

        return is_writable($fallback) ? $fallback : $logPath;
    }

    /**
     * @param  list<string>  $command
     */
    public static function spawnBackground(array $command, string $logPath): bool
    {
        $logPath = self::ensureDaemonLogWritable($logPath);

        $escaped = array_map('escapeshellarg', $command);
        $line = 'cd '.escapeshellarg(base_path()).' && nohup '.implode(' ', $escaped)
            .' >> '.escapeshellarg($logPath).' 2>&1 & echo $!';
        $output = [];
        $exitCode = 1;
        exec($line, $output, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        $pid = isset($output[0]) ? (int) trim((string) $output[0]) : 0;

        return $pid > 0 || $output !== [];
    }

    public static function tailLogFile(string $path, int $lines = 20): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $lines = max(1, min(100, $lines));
        $output = [];
        exec(sprintf('tail -n %d %s 2>/dev/null', $lines, escapeshellarg($path)), $output);

        return implode("\n", $output);
    }

    /** Парсинг etime из ps: mm:ss, hh:mm:ss, dd-hh:mm:ss. */
    public static function parseElapsedToSeconds(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }

        $days = 0;
        if (str_contains($raw, '-')) {
            [$dayPart, $raw] = explode('-', $raw, 2);
            $days = (int) $dayPart;
        }

        $parts = array_map('intval', explode(':', $raw));
        if ($parts === []) {
            return null;
        }

        if (count($parts) === 3) {
            return $days * 86400 + ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
        if (count($parts) === 2) {
            return $days * 86400 + ($parts[0] * 60) + $parts[1];
        }

        return $days * 86400 + $parts[0];
    }

    private static function elapsedSecondsFromProc(int $pid): ?int
    {
        $statPath = "/proc/{$pid}/stat";
        if (! is_readable($statPath)) {
            return null;
        }

        // Надёжнее ps: парсинг /proc/pid/stat ломается на comm с пробелами/скобками.
        $fromPs = self::cpuMemFromPs($pid)['elapsed_sec'] ?? null;
        if ($fromPs !== null) {
            return $fromPs;
        }

        $stat = (string) file_get_contents($statPath);
        if (! preg_match('/\s(\d+)\s(\d+)$/', $stat, $matches)) {
            return null;
        }

        $starttime = (int) $matches[2];
        $uptimeRaw = @file_get_contents('/proc/uptime');
        if ($uptimeRaw === false) {
            return null;
        }

        $uptimeSec = (float) explode(' ', trim($uptimeRaw))[0];
        $clkTck = 100;
        if (is_readable('/proc/self/auxv')) {
            // Fallback: типичное значение на Linux x86_64.
        }
        $bootClk = (int) round($uptimeSec * $clkTck);

        return max(0, (int) floor(($bootClk - $starttime) / $clkTck));
    }

    /**
     * @return array{elapsed_sec:?int,cpu_percent:?float,mem_percent:?float}
     */
    private static function cpuMemFromPs(int $pid): array
    {
        $output = [];
        exec(sprintf('ps -p %d -o etime=,pcpu=,pmem= --no-headers 2>/dev/null', $pid), $output);

        if ($output === []) {
            return ['elapsed_sec' => null, 'cpu_percent' => null, 'mem_percent' => null];
        }

        $line = trim((string) $output[0]);
        $parts = preg_split('/\s+/', $line) ?: [];

        return [
            'elapsed_sec' => isset($parts[0]) ? self::parseElapsedToSeconds($parts[0]) : null,
            'cpu_percent' => isset($parts[1]) ? (float) $parts[1] : null,
            'mem_percent' => isset($parts[2]) ? (float) $parts[2] : null,
        ];
    }

    private static function signalTerm(int $pid): bool
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

    private static function signalKill(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            $sig = defined('SIGKILL') ? (int) constant('SIGKILL') : 9;
            if (@posix_kill($pid, $sig)) {
                return true;
            }
        }

        exec(sprintf('kill -KILL %d 2>/dev/null', $pid), $_, $exitCode);

        return $exitCode === 0;
    }

    /** Реальный PHP artisan, а не bash/cursor с текстом «php artisan» в cmdline. */
    private static function cmdlineLooksLikePhpArtisan(string $cmdline): bool
    {
        if (self::cmdlineIsShellWrapper($cmdline)) {
            return false;
        }

        return (bool) preg_match('#(?:^|/)(php\d*(?:\.\d+)?)\s+artisan\s+#', $cmdline);
    }

    /** Оболочки, в cmdline которых может быть текст чужих команд (Cursor sandbox, bash -c). */
    private static function cmdlineIsShellWrapper(string $cmdline): bool
    {
        return (bool) preg_match('#(?:^|/)(bash|sh|cursorsandbox)\b#', $cmdline);
    }
}
