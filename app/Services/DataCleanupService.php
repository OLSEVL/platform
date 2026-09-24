<?php

namespace App\Services;

use App\Models\InstrumentCandle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Централизованная очистка исторических данных платформы.
 *
 * Удаляет записи старше заданного окна (свечи, сигналы/сделки ботов, LLM-логи и пр.).
 * Используется из глобальных «Настроек» и из настроек конкретного бота.
 */
class DataCleanupService
{
    /** Допустимые окна хранения в UI «Удалить все данные» (0 = удалить всё). */
    public const ALLOWED_RETENTION_HOURS = [0, 1, 3, 6, 12, 24, 48];

    /**
     * @deprecated Используйте allowedAutoCleanupRetentionHours() — список дней 1–MAX_AUTO_CLEANUP_RETENTION_DAYS.
     * Оставлен для совместимости старых ссылок на константу в коде/доках.
     */
    public const ALLOWED_AUTO_CLEANUP_RETENTION_HOURS = [1, 3, 6, 12, 24, 48];

    public const SETTING_KEY_AUTO_CLEANUP_RETENTION_HOURS = 'data_auto_cleanup_retention_hours';

    public const DEFAULT_RETENTION_HOURS = 24;

    /** Пока настройка не задана в БД — сохраняем прежнее поведение cron (свечи старше 24 ч.). */
    public const DEFAULT_AUTO_CLEANUP_RETENTION_HOURS = 24;

    /** Максимум дней в селекте «Автоматически удалять данные старше» (и потолок графика при выкл. очистке). */
    public const MAX_AUTO_CLEANUP_RETENTION_DAYS = 70;

    /**
     * Размер одного DELETE для свечей.
     * Один монолитный DELETE по миллионам строк блокировал InnoDB на минуты
     * (php-fpm/nginx → upstream timeout, UI «не открывается»).
     */
    public const CANDLE_DELETE_BATCH_SIZE = 5000;

    /**
     * Пауза между батчами свечей (мкс) — даём место concurrent SELECT/INSERT инжеста и ботов.
     */
    public const CANDLE_DELETE_BATCH_SLEEP_US = 50_000;

    /**
     * Батч для таблиц ботов (обычно меньше candles, но тот же паттерн безопаснее).
     */
    public const BOT_DATA_DELETE_BATCH_SIZE = 2000;

    /**
     * Разрешённые окна авто-очистки: сутки 1–MAX_AUTO_CLEANUP_RETENTION_DAYS (в часах) + короткие 1/3/6/12 ч.
     * Порядок: сначала длиннее (больше истории), потом короткие часы.
     *
     * @return list<int>
     */
    public static function allowedAutoCleanupRetentionHours(): array
    {
        $hours = [];
        // От большего к меньшему: 32 дня … 1 день, затем короткие часовые окна.
        for ($day = self::MAX_AUTO_CLEANUP_RETENTION_DAYS; $day >= 1; $day--) {
            $hours[] = $day * 24;
        }
        foreach ([12, 6, 3, 1] as $shortHours) {
            $hours[] = $shortHours;
        }

        return $hours;
    }

    /**
     * Пары value→подпись для селекта «Автоматически удалять данные старше».
     *
     * @return array<int, string> hours => label
     */
    public static function autoCleanupRetentionOptions(): array
    {
        $options = [];
        foreach (self::allowedAutoCleanupRetentionHours() as $hours) {
            $options[$hours] = (new self)->autoCleanupRetentionLabel($hours);
        }

        return $options;
    }

    /**
     * Только свечи — для hourly-планировщика `candles:prune-old`.
     *
     * @return array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, backtest_runs: int, retention_hours: int}
     */
    public function purgeCandlesOlderThan(int $hours): array
    {
        return $this->purgeOlderThan($hours, botId: null, includeCandles: true, includeBotData: false);
    }

    /**
     * Удаляет все данные старше указанного количества часов.
     *
     * @param  int|null  $botId  Если задан — чистим только данные этого бота (без свечей).
     * @param  bool  $includeCandles  Свечи общие для платформы; при очистке одного бота не трогаем.
     * @param  bool  $includeBotData  Сигналы/сделки/логи ботов; выключено для cron только по свечам.
     * @return array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, backtest_runs: int, retention_hours: int}
     */
    public function purgeOlderThan(
        int $hours,
        ?int $botId = null,
        bool $includeCandles = true,
        bool $includeBotData = true,
    ): array {
        $retentionHours = $this->normalizeRetentionHours($hours);
        // 0 ч. — полная очистка: удаляем все строки, без порога по времени.
        $purgeAll = $retentionHours === 0;
        $cutoff = now()->subHours($retentionHours);
        $cutoffTimestamp = $cutoff->getTimestamp();

        $deletedCandles = 0;
        $deletedLogs = 0;
        $deletedAnalysisRuns = 0;
        $deletedTrades = 0;
        $deletedSignals = 0;
        $deletedBotRuns = 0;
        $deletedBacktestRuns = 0;

        // Без одной большой транзакции: батчи коммитятся по одному, InnoDB не держит
        // undo-лог на миллионы строк и не блокирует UI/инжест на минуты.
        // Свечи общие для всей платформы — при очистке конкретного бота их не трогаем.
        if ($includeCandles && $botId === null) {
            $deletedCandles = $this->deleteCandlesInBatches(
                $purgeAll ? null : $cutoffTimestamp,
            );
        }

        if ($includeBotData) {
            if (Schema::hasTable('bot_llm_logs')) {
                $deletedLogs = $this->deleteByBotScope(
                    'bot_llm_logs',
                    'created_at',
                    $cutoff,
                    $botId,
                    $purgeAll,
                );
            }

            if (Schema::hasTable('bot_analysis_runs')) {
                $deletedAnalysisRuns = $this->deleteByBotScope(
                    'bot_analysis_runs',
                    'started_at',
                    $cutoff,
                    $botId,
                    $purgeAll,
                );
            }

            $deletedTrades = $this->deleteByBotScope(
                'bot_trades',
                'entry_time',
                $cutoff,
                $botId,
                $purgeAll,
            );

            $deletedSignals = $this->deleteByBotScope(
                'bot_signals',
                'signal_at',
                $cutoff,
                $botId,
                $purgeAll,
            );

            if (Schema::hasTable('bot_runs')) {
                $deletedBotRuns = $this->deleteByBotScope(
                    'bot_runs',
                    'started_at',
                    $cutoff,
                    $botId,
                    $purgeAll,
                );
            }

            // Прогоны бэктеста: сделки удаляются cascadeOnDelete с bot_backtest_runs.
            if (Schema::hasTable('bot_backtest_runs')) {
                $deletedBacktestRuns = $this->deleteByBotScope(
                    'bot_backtest_runs',
                    'created_at',
                    $cutoff,
                    $botId,
                    $purgeAll,
                );
            }
        }

        // Свечи удалены — старый min_ts в кэше бэктеста уже неверный.
        if ($includeCandles && $botId === null && $deletedCandles > 0) {
            app(CandleRangeCache::class)->rebuildAfterCleanup();
        }

        return [
            'candles' => $deletedCandles,
            'logs' => $deletedLogs,
            'analysis_runs' => $deletedAnalysisRuns,
            'trades' => $deletedTrades,
            'signals' => $deletedSignals,
            'bot_runs' => $deletedBotRuns,
            'backtest_runs' => $deletedBacktestRuns,
            'retention_hours' => $retentionHours,
        ];
    }

    /**
     * Нормализует окно хранения перед purge.
     *
     * Принимает оба списка:
     * - ALLOWED_RETENTION_HOURS — ручная «Удалить все данные» (в т.ч. 0 = всё);
     * - allowedAutoCleanupRetentionHours() — авто-очистка 1–70 дней и короткие часы.
     *
     * Раньше проверялся только первый список, поэтому значение из настроек вроде
     * «7 дней» (168 ч.) не входило в [0,1,3,6,12,24,48] и молча сбрасывалось на 24 ч.
     * Из‑за этого hourly data:auto-cleanup оставлял только ~сутки свечей.
     */
    public function normalizeRetentionHours(int $hours): int
    {
        if (in_array($hours, self::ALLOWED_RETENTION_HOURS, true)) {
            return $hours;
        }

        // Дни из селекта «Автоматически удалять данные старше» (24…MAX*24 и т.д.).
        if (in_array($hours, self::allowedAutoCleanupRetentionHours(), true)) {
            return $hours;
        }

        return self::DEFAULT_RETENTION_HOURS;
    }

    /**
     * Окно авто-очистки из app_settings: null — «ничего не удалять».
     */
    public function getAutoCleanupRetentionHours(): ?int
    {
        $row = DB::table('app_settings')
            ->where('key', self::SETTING_KEY_AUTO_CLEANUP_RETENTION_HOURS)
            ->value('value');

        // Пустая строка в БД — явное отключение авто-очистки пользователем.
        if ($row !== null && trim((string) $row) === '') {
            return null;
        }

        if ($row === null) {
            return self::DEFAULT_AUTO_CLEANUP_RETENTION_HOURS;
        }

        $hours = (int) $row;

        return in_array($hours, self::allowedAutoCleanupRetentionHours(), true)
            ? $hours
            : self::DEFAULT_AUTO_CLEANUP_RETENTION_HOURS;
    }

    public function isAutoCleanupEnabled(): bool
    {
        return $this->getAutoCleanupRetentionHours() !== null;
    }

    /**
     * Глубина истории главного графика (скролл влево), в секундах.
     *
     * Раньше на дашборде был хардкод 24ч — настройка «Автоматически удалять данные старше»
     * влияла только на cron purge, а UI всё равно не отдавал/не запрашивал старше суток.
     * Берём то же окно, что и авто-очистка; если очистка выключена — потолок MAX_AUTO_CLEANUP_RETENTION_DAYS
     * (как максимум в селекте), чтобы не тянуть бесконечный since из БД.
     */
    public function chartHistoryMaxSeconds(): int
    {
        $hours = $this->getAutoCleanupRetentionHours();
        if ($hours === null) {
            $hours = self::MAX_AUTO_CLEANUP_RETENTION_DAYS * 24;
        }

        // Минимум 1 час — иначе since ≈ now и график пустеет при сбое настройки.
        return max(3600, $hours * 3600);
    }

    /**
     * Сводка по таблице свечей для блока «Очистка данных» на /settings.
     *
     * Не делает COUNT/MIN/MAX по всей таблице: края из CandleRangeCache,
     * число строк — оценка InnoDB (или точный COUNT в sqlite-тестах).
     *
     * @return array{
     *     count: int,
     *     count_is_approximate: bool,
     *     depth_hours: float|null,
     *     span_hours: float|null,
     *     oldest_at: int|null,
     *     newest_at: int|null,
     * }
     */
    public function getCandlesStorageStats(): array
    {
        $table = (new InstrumentCandle)->getTable();
        if (! Schema::hasTable($table)) {
            return $this->emptyCandlesStorageStats();
        }

        $rangeCache = app(CandleRangeCache::class);
        $range = $rangeCache->s5Range();
        $oldest = $range['min_ts'];
        $newest = $range['max_ts'];
        if ($oldest === null || $newest === null) {
            return $this->emptyCandlesStorageStats();
        }

        $approx = $rangeCache->approximateRowCount();
        $countIsApprox = $rangeCache->rowCountIsApproximate();
        // TABLE_ROWS иногда 0 при живых данных — не прячем блок «история есть».
        $count = $approx > 0 ? $approx : 1;

        $now = time();

        return [
            'count' => $count,
            'count_is_approximate' => $countIsApprox,
            'depth_hours' => round(max(0, ($now - $oldest) / 3600), 1),
            'span_hours' => round(max(0, ($newest - $oldest) / 3600), 1),
            'oldest_at' => $oldest,
            'newest_at' => $newest,
        ];
    }

    /**
     * Подпись глубины истории свечей для UI (например «~64 часа» или «7 дней (168 часов)»).
     *
     * @param  array{count?: int, depth_hours?: float|null}  $stats
     */
    public function candlesStorageDepthLabel(array $stats): string
    {
        $hours = $stats['depth_hours'] ?? null;
        if ($hours === null || ($stats['count'] ?? 0) === 0) {
            return 'нет данных';
        }

        $rounded = max(1, (int) round($hours));
        if ($rounded >= 24 && $rounded % 24 === 0) {
            return sprintf(
                '%s (%s)',
                self::pluralizeDays(intdiv($rounded, 24)),
                self::pluralizeHours($rounded)
            );
        }

        return '~'.self::pluralizeHours($rounded);
    }

    /**
     * Дата/время старейшей свечи в часовом поясе приложения.
     *
     * @param  array{oldest_at?: int|null}  $stats
     */
    public function candlesStorageOldestLabel(array $stats): ?string
    {
        $oldestAt = $stats['oldest_at'] ?? null;
        if ($oldestAt === null) {
            return null;
        }

        return Carbon::createFromTimestamp($oldestAt)
            ->timezone(config('app.timezone'))
            ->format('d.m.Y H:i');
    }

    /**
     * @return array{count: int, count_is_approximate: bool, depth_hours: null, span_hours: null, oldest_at: null, newest_at: null}
     */
    private function emptyCandlesStorageStats(): array
    {
        return [
            'count' => 0,
            'count_is_approximate' => false,
            'depth_hours' => null,
            'span_hours' => null,
            'oldest_at' => null,
            'newest_at' => null,
        ];
    }

    /**
     * @param  int|null  $hours  null — отключить авто-очистку.
     */
    public function setAutoCleanupRetentionHours(?int $hours): void
    {
        $value = ($hours !== null && in_array($hours, self::allowedAutoCleanupRetentionHours(), true))
            ? (string) $hours
            : '';

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY_AUTO_CLEANUP_RETENTION_HOURS],
            [
                'value' => $value,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Нормализует значение из формы настроек: пустая строка → отключено.
     */
    public function normalizeAutoCleanupRetentionHours(string|int|null $raw): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $hours = (int) $raw;

        return in_array($hours, self::allowedAutoCleanupRetentionHours(), true)
            ? $hours
            : null;
    }

    /**
     * Человекочитаемая подпись для UI и flash-сообщений.
     * Сутки: «1 день (24 часа)» … «70 дней (1680 часов)»; короткие окна — в часах.
     */
    public function autoCleanupRetentionLabel(?int $hours): string
    {
        if ($hours === null) {
            return 'Ничего не удалять';
        }

        // Краткоживущие окна (меньше суток) оставляем как «N час(а/ов)».
        if ($hours < 24) {
            return match ($hours) {
                1 => '1 час',
                3 => '3 часа',
                6 => '6 часов',
                12 => '12 часов',
                default => self::pluralizeHours($hours),
            };
        }

        // Полные сутки: 24, 48, … MAX*24 → «N день/дня/дней (M час/часа/часов)».
        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);
            if ($days >= 1 && $days <= self::MAX_AUTO_CLEANUP_RETENTION_DAYS) {
                return sprintf(
                    '%s (%s)',
                    self::pluralizeDays($days),
                    self::pluralizeHours($hours)
                );
            }
        }

        return self::pluralizeHours($hours);
    }

    /** «1 день» / «2 дня» / «5 дней». */
    private static function pluralizeDays(int $days): string
    {
        $n = abs($days) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $days.' дней';
        }
        if ($n1 === 1) {
            return $days.' день';
        }
        if ($n1 >= 2 && $n1 <= 4) {
            return $days.' дня';
        }

        return $days.' дней';
    }

    /** «1 час» / «2 часа» / «5 часов» / «24 часа». */
    private static function pluralizeHours(int $hours): string
    {
        $n = abs($hours) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $hours.' часов';
        }
        if ($n1 === 1) {
            return $hours.' час';
        }
        if ($n1 >= 2 && $n1 <= 4) {
            return $hours.' часа';
        }

        return $hours.' часов';
    }

    /**
     * Формирует человекочитаемое сообщение об итогах очистки.
     *
     * @param  array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, backtest_runs?: int, retention_hours: int}  $result
     */
    public function formatResultMessage(array $result, bool $includeCandles = true): string
    {
        $hours = (int) $result['retention_hours'];
        $llmTotal = (int) $result['logs'] + (int) $result['analysis_runs'];
        $backtests = (int) ($result['backtest_runs'] ?? 0);

        if ($includeCandles) {
            if ($hours === 0) {
                return sprintf(
                    'Удалены все данные: свечи=%d, LLM/анализ=%d, сделки=%d, сигналы=%d, запуски ботов=%d, бэктесты=%d.',
                    (int) $result['candles'],
                    $llmTotal,
                    (int) $result['trades'],
                    (int) $result['signals'],
                    (int) $result['bot_runs'],
                    $backtests,
                );
            }

            return sprintf(
                'Удалены данные старше %d ч.: свечи=%d, LLM/анализ=%d, сделки=%d, сигналы=%d, запуски ботов=%d, бэктесты=%d.',
                $hours,
                (int) $result['candles'],
                $llmTotal,
                (int) $result['trades'],
                (int) $result['signals'],
                (int) $result['bot_runs'],
                $backtests,
            );
        }

        if ($hours === 0) {
            return sprintf(
                'Очистка выполнена (удалено всё): logs=%d, trades=%d, signals=%d, запуски=%d, бэктесты=%d.',
                $llmTotal,
                (int) $result['trades'],
                (int) $result['signals'],
                (int) $result['bot_runs'],
                $backtests,
            );
        }

        return sprintf(
            'Очистка выполнена (удалено старше %d ч.): logs=%d, trades=%d, signals=%d, запуски=%d, бэктесты=%d.',
            $hours,
            $llmTotal,
            (int) $result['trades'],
            (int) $result['signals'],
            (int) $result['bot_runs'],
            $backtests,
        );
    }

    /**
     * Считает текущее число строк по каждой таблице, которую затрагивает очистка одного бота.
     * Используется на странице настроек бота — чтобы видеть объём данных до purge.
     *
     * @return array{
     *     signals: int,
     *     trades: int,
     *     analysis_runs: int,
     *     llm_logs: int,
     *     bot_runs: int,
     *     backtest_runs: int,
     *     backtest_trades: int,
     *     total: int
     * }
     */
    public function countBotScopedRows(int $botId): array
    {
        $signals = $this->countByBotScope('bot_signals', $botId);
        $trades = $this->countByBotScope('bot_trades', $botId);
        $analysisRuns = Schema::hasTable('bot_analysis_runs')
            ? $this->countByBotScope('bot_analysis_runs', $botId)
            : 0;
        $llmLogs = Schema::hasTable('bot_llm_logs')
            ? $this->countByBotScope('bot_llm_logs', $botId)
            : 0;
        $botRuns = Schema::hasTable('bot_runs')
            ? $this->countByBotScope('bot_runs', $botId)
            : 0;
        $backtestRuns = Schema::hasTable('bot_backtest_runs')
            ? $this->countByBotScope('bot_backtest_runs', $botId)
            : 0;

        // Сделки бэктеста не имеют bot_id — считаем через join с прогонами этого бота.
        $backtestTrades = 0;
        if (Schema::hasTable('bot_backtest_trades') && Schema::hasTable('bot_backtest_runs')) {
            $backtestTrades = (int) DB::table('bot_backtest_trades')
                ->join('bot_backtest_runs', 'bot_backtest_runs.id', '=', 'bot_backtest_trades.bot_backtest_run_id')
                ->where('bot_backtest_runs.bot_id', $botId)
                ->count();
        }

        return [
            'signals' => $signals,
            'trades' => $trades,
            'analysis_runs' => $analysisRuns,
            'llm_logs' => $llmLogs,
            'bot_runs' => $botRuns,
            'backtest_runs' => $backtestRuns,
            'backtest_trades' => $backtestTrades,
            'total' => $signals + $trades + $analysisRuns + $llmLogs + $botRuns + $backtestRuns + $backtestTrades,
        ];
    }

    /**
     * Удаляет устаревшие (или все) свечи пакетами с LIMIT.
     *
     * Раньше один DELETE WHERE time < cutoff на ~десятках миллионов строк
     * держал InnoDB в состоянии updating 5+ минут → php-fpm max_children,
     * nginx upstream timeout, страница /bots «не открывается».
     *
     * @param  int|null  $cutoffTimestamp  unix-time; null = удалить всю таблицу батчами
     */
    private function deleteCandlesInBatches(?int $cutoffTimestamp): int
    {
        $total = 0;
        // LIMIT вшиваем как int: на части MySQL/PDO bound LIMIT в prepared statement ломается.
        $batchSize = max(1, (int) self::CANDLE_DELETE_BATCH_SIZE);

        do {
            // Явный LIMIT: короткий lock, коммит после каждого батча (autocommit).
            if ($cutoffTimestamp === null) {
                $deleted = (int) DB::affectingStatement(
                    "DELETE FROM `candles` LIMIT {$batchSize}",
                );
            } else {
                $deleted = (int) DB::affectingStatement(
                    "DELETE FROM `candles` WHERE `time` < ? LIMIT {$batchSize}",
                    [$cutoffTimestamp],
                );
            }

            $total += $deleted;

            // Между батчами отдаём CPU/IO инжесту и веб-запросам.
            if ($deleted > 0 && self::CANDLE_DELETE_BATCH_SLEEP_US > 0) {
                usleep(self::CANDLE_DELETE_BATCH_SLEEP_US);
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Удаляет строки из таблицы бота с учётом опционального фильтра bot_id.
     * Тоже батчами — на больших bot_trades монолитный DELETE тоже может зажать пул.
     */
    private function deleteByBotScope(
        string $table,
        string $timeColumn,
        CarbonInterface $cutoff,
        ?int $botId,
        bool $purgeAll = false,
    ): int {
        $total = 0;
        $batchSize = max(1, (int) self::BOT_DATA_DELETE_BATCH_SIZE);

        // Имена таблицы/колонки из нашего кода (не user input) — безопасны в SQL.
        do {
            if ($purgeAll) {
                if ($botId === null) {
                    $deleted = (int) DB::affectingStatement(
                        "DELETE FROM {$table} LIMIT {$batchSize}",
                    );
                } else {
                    $deleted = (int) DB::affectingStatement(
                        "DELETE FROM {$table} WHERE bot_id = ? LIMIT {$batchSize}",
                        [$botId],
                    );
                }
            } elseif ($botId === null) {
                $deleted = (int) DB::affectingStatement(
                    "DELETE FROM {$table} WHERE {$timeColumn} < ? LIMIT {$batchSize}",
                    [$cutoff],
                );
            } else {
                $deleted = (int) DB::affectingStatement(
                    "DELETE FROM {$table} WHERE bot_id = ? AND {$timeColumn} < ? LIMIT {$batchSize}",
                    [$botId, $cutoff],
                );
            }

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    /**
     * COUNT(*) по bot_id для таблиц с прямой привязкой к боту.
     */
    private function countByBotScope(string $table, int $botId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->where('bot_id', $botId)->count();
    }
}
