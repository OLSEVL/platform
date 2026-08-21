<?php

namespace App\Services;

use App\Models\InstrumentCandle;
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
     * @deprecated Используйте allowedAutoCleanupRetentionHours() — список дней 1–30 динамический.
     * Оставлен для совместимости старых ссылок на константу в коде/доках.
     */
    public const ALLOWED_AUTO_CLEANUP_RETENTION_HOURS = [1, 3, 6, 12, 24, 48];

    public const SETTING_KEY_AUTO_CLEANUP_RETENTION_HOURS = 'data_auto_cleanup_retention_hours';

    public const DEFAULT_RETENTION_HOURS = 24;

    /** Пока настройка не задана в БД — сохраняем прежнее поведение cron (свечи старше 24 ч.). */
    public const DEFAULT_AUTO_CLEANUP_RETENTION_HOURS = 24;

    /**
     * Разрешённые окна авто-очистки: сутки 1–30 (в часах) + короткие 1/3/6/12 ч.
     * Порядок: сначала длиннее (больше истории), потом короткие часы.
     *
     * @return list<int>
     */
    public static function allowedAutoCleanupRetentionHours(): array
    {
        $hours = [];
        for ($day = 30; $day >= 1; $day--) {
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

        DB::transaction(function () use (
            $botId,
            $cutoff,
            $cutoffTimestamp,
            $includeCandles,
            $includeBotData,
            $purgeAll,
            &$deletedCandles,
            &$deletedLogs,
            &$deletedAnalysisRuns,
            &$deletedTrades,
            &$deletedSignals,
            &$deletedBotRuns,
            &$deletedBacktestRuns,
        ): void {
            // Свечи общие для всей платформы — при очистке конкретного бота их не трогаем.
            if ($includeCandles && $botId === null) {
                $deletedCandles = $purgeAll
                    ? InstrumentCandle::query()->delete()
                    : InstrumentCandle::query()
                        ->where('time', '<', $cutoffTimestamp)
                        ->delete();
            }

            if (! $includeBotData) {
                return;
            }

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
        });

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
     * Нормализует выбор пользователя до одного из разрешённых значений.
     */
    public function normalizeRetentionHours(int $hours): int
    {
        return in_array($hours, self::ALLOWED_RETENTION_HOURS, true)
            ? $hours
            : self::DEFAULT_RETENTION_HOURS;
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
     * Сутки: «1 день (24 часа)» … «30 дней (720 часов)»; короткие окна — в часах.
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

        // Полные сутки: 24, 48, … 720 → «N день/дня/дней (M час/часа/часов)».
        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);
            if ($days >= 1 && $days <= 30) {
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
     * Удаляет строки из таблицы бота с учётом опционального фильтра bot_id.
     */
    private function deleteByBotScope(
        string $table,
        string $timeColumn,
        CarbonInterface $cutoff,
        ?int $botId,
        bool $purgeAll = false,
    ): int {
        if ($purgeAll) {
            if ($botId === null) {
                return DB::delete("DELETE FROM {$table}");
            }

            return DB::delete(
                "DELETE FROM {$table} WHERE bot_id = ?",
                [$botId],
            );
        }

        if ($botId === null) {
            return DB::delete(
                "DELETE FROM {$table} WHERE {$timeColumn} < ?",
                [$cutoff],
            );
        }

        return DB::delete(
            "DELETE FROM {$table} WHERE bot_id = ? AND {$timeColumn} < ?",
            [$botId, $cutoff],
        );
    }
}
