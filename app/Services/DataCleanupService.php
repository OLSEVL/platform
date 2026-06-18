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

    public const DEFAULT_RETENTION_HOURS = 24;

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
