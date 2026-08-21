<?php

namespace App\Jobs;

use App\Models\BotBacktestRun;
use App\Services\Bots\BotBacktestEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Фоновый прогон backtest: тяжёлый candle-replay не должен блокировать HTTP.
 *
 * Очередь `backtest` — отдельный worker с большим timeout (см. start-all.sh).
 * Дубли не через ShouldBeUnique (file-cache ломается на mkdir у www-data),
 * а через проверку статуса run в handle().
 */
class RunBotBacktestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'backtest';

    /** Длинные прогоны «Все» пары: до часа (раньше 15–30 мин ловили TimeoutExceeded). */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $backtestRunId)
    {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(BotBacktestEngine $engine): void
    {
        $run = BotBacktestRun::query()->find($this->backtestRunId);
        if (! $run) {
            return;
        }
        if (in_array($run->status, [
            BotBacktestRun::STATUS_COMPLETED,
            BotBacktestRun::STATUS_CANCELLED,
            BotBacktestRun::STATUS_FAILED,
            BotBacktestRun::STATUS_RUNNING,
        ], true)) {
            // RUNNING: уже крутится другим worker — не стартуем второй раз.
            return;
        }

        // Подстраховка PHP max_execution_time внутри worker.
        @set_time_limit($this->timeout);

        $engine->execute($run);
    }

    public function failed(?\Throwable $e): void
    {
        $run = BotBacktestRun::query()->find($this->backtestRunId);
        if (! $run) {
            return;
        }
        if (in_array($run->status, [
            BotBacktestRun::STATUS_COMPLETED,
            BotBacktestRun::STATUS_CANCELLED,
        ], true)) {
            return;
        }
        $run->update([
            'status' => BotBacktestRun::STATUS_FAILED,
            'error_message' => mb_substr($e?->getMessage() ?? 'Job failed', 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
