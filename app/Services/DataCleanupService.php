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

    /** Допустимые окна для авто-очистки по крону (без полного стирания). */
    public const ALLOWED_AUTO_CLEANUP_RETENTION_HOURS = [1, 3, 6, 12, 24, 48];

    public const SETTING_KEY_AUTO_CLEANUP_RETENTION_HOURS = 'data_auto_cleanup_retention_hours';

    public const DEFAULT_RETENTION_HOURS = 24;

    /** Пока настройка не задана в БД — сохраняем прежнее поведение cron (свечи старше 24 ч.). */
    public const DEFAULT_AUTO_CLEANUP_RETENTION_HOURS = 24;

    /**
     * Только свечи — для hourly-планировщика `candles:prune-old`.
     *
     * @return array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, retention_hours: int}
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
     * @return array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, retention_hours: int}
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
        });

        return [
            'candles' => $deletedCandles,
            'logs' => $deletedLogs,
            'analysis_runs' => $deletedAnalysisRuns,
            'trades' => $deletedTrades,
            'signals' => $deletedSignals,
            'bot_runs' => $deletedBotRuns,
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

        return in_array($hours, self::ALLOWED_AUTO_CLEANUP_RETENTION_HOURS, true)
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
        $value = ($hours !== null && in_array($hours, self::ALLOWED_AUTO_CLEANUP_RETENTION_HOURS, true))
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

        return in_array($hours, self::ALLOWED_AUTO_CLEANUP_RETENTION_HOURS, true)
            ? $hours
            : null;
    }

    /**
     * Человекочитаемая подпись для UI и flash-сообщений.
     */
    public function autoCleanupRetentionLabel(?int $hours): string
    {
        if ($hours === null) {
            return 'Ничего не удалять';
        }

        return match ($hours) {
            1 => '1 час',
            3 => '3 часа',
            6 => '6 часов',
            12 => '12 часов',
            24 => '24 часа',
            48 => '48 часов',
            default => "{$hours} ч.",
        };
    }

    /**
     * Формирует человекочитаемое сообщение об итогах очистки.
     *
     * @param  array{candles: int, logs: int, analysis_runs: int, trades: int, signals: int, bot_runs: int, retention_hours: int}  $result
     */
    public function formatResultMessage(array $result, bool $includeCandles = true): string
    {
        $hours = (int) $result['retention_hours'];
        $llmTotal = (int) $result['logs'] + (int) $result['analysis_runs'];

        if ($includeCandles) {
            if ($hours === 0) {
                return sprintf(
                    'Удалены все данные: свечи=%d, LLM/анализ=%d, сделки=%d, сигналы=%d, запуски ботов=%d.',
                    (int) $result['candles'],
                    $llmTotal,
                    (int) $result['trades'],
                    (int) $result['signals'],
                    (int) $result['bot_runs'],
                );
            }

            return sprintf(
                'Удалены данные старше %d ч.: свечи=%d, LLM/анализ=%d, сделки=%d, сигналы=%d, запуски ботов=%d.',
                $hours,
                (int) $result['candles'],
                $llmTotal,
                (int) $result['trades'],
                (int) $result['signals'],
                (int) $result['bot_runs'],
            );
        }

        if ($hours === 0) {
            return sprintf(
                'Очистка выполнена (удалено всё): logs=%d, trades=%d, signals=%d, запуски=%d.',
                $llmTotal,
                (int) $result['trades'],
                (int) $result['signals'],
                (int) $result['bot_runs'],
            );
        }

        return sprintf(
            'Очистка выполнена (удалено старше %d ч.): logs=%d, trades=%d, signals=%d, запуски=%d.',
            $hours,
            $llmTotal,
            (int) $result['trades'],
            (int) $result['signals'],
            (int) $result['bot_runs'],
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
