<?php

namespace App\Jobs;

use App\Models\BotBacktestRun;
use App\Services\Bots\BotBacktestEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;

/**
 * Фоновый прогон backtest: candle-replay чанками по времени, чтобы не ловить
 * MaxAttemptsExceeded / TimeoutExceeded на «Все» парах.
 *
 * Дубли: статус COMPLETED/CANCELLED/FAILED — выход; QUEUED/RUNNING — resume с instruments_done.
 */
class RunBotBacktestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE_NAME = 'backtest';

    /** Жёсткий лимит worker; chunk внутри engine короче (~45 мин). */
    public int $timeout = 3600;

    /** Без ретраев: timeout → failed()/continue, не «attempted too many times». */
    public int $tries = 1;

    public bool $failOnTimeout = true;

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
        ], true)) {
            return;
        }

        @set_time_limit($this->timeout);

        $outcome = $engine->execute($run->fresh());

        // Остались пары — сразу следующий chunk (новый job, attempts с нуля).
        if ($outcome === 'continue') {
            self::dispatch($this->backtestRunId);
            Log::info('backtest.chunk_continue', ['run_id' => $this->backtestRunId]);
        }
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

        $msg = (string) ($e?->getMessage() ?? 'Job failed');
        $isTimeout = $e instanceof TimeoutExceededException
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'too many times');

        $params = is_array($run->params_json) ? $run->params_json : [];
        $all = array_values(array_filter(array_map('strval', $params['instruments'] ?? [])));
        $done = array_values(array_filter(array_map('strval', $params['instruments_done'] ?? [])));
        $remaining = array_values(array_diff($all, $done));

        // После таймаута: если есть незавершённые пары — продолжаем новым job, не failed.
        if ($isTimeout && $remaining !== []) {
            $run->update([
                'status' => BotBacktestRun::STATUS_QUEUED,
                'error_message' => 'Таймаут chunk’а — автопродолжение (осталось пар: '.count($remaining).').',
            ]);
            self::dispatch($this->backtestRunId);
            Log::warning('backtest.timeout_continue', [
                'run_id' => $this->backtestRunId,
                'remaining' => count($remaining),
            ]);

            return;
        }

        $friendly = $isTimeout
            ? 'Прогон прерван по таймауту/лимиту попыток. Сократите период или число пар и запустите снова.'
            : $msg;

        $run->update([
            'status' => BotBacktestRun::STATUS_FAILED,
            'error_message' => mb_substr($friendly, 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
