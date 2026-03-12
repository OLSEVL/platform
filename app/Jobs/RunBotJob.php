<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\BotRun;
use App\Models\PocketInstrument;
use App\Services\CandleService;
use App\Services\PocketOptionsProxyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunBotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Bot $bot,
        public ?int $runId = null,
        public ?string $instrument = null,
        public ?int $timeframe = null
    ) {}

    public function handle(PocketOptionsProxyClient $proxy, CandleService $candleService): void
    {
        $run = $this->runId ? BotRun::find($this->runId) : $this->bot->runs()->create(['status' => BotRun::STATUS_QUEUED, 'run_token' => Str::random(48)]);
        if (! $run) {
            return;
        }
        $run->update(['status' => BotRun::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $instrument = $this->instrument ?? $this->bot->config['instrument'] ?? 'EURUSD';
            $timeframe = (int) ($this->timeframe ?? $this->bot->config['timeframe'] ?? 1);

            $pocketInstrument = PocketInstrument::where('instrument', $instrument)->first();
            if (! $pocketInstrument || ! $pocketInstrument->session_token) {
                $run->update(['status' => BotRun::STATUS_FAILED, 'error_message' => "У инструмента {$instrument} не заданы session_token и uid (настройте в разделе Инструменты).", 'finished_at' => now()]);
                return;
            }

            $candlesData = null;
            if ($candleService->hasFreshData($instrument)) {
                $candlesData = $candleService->getCandles($instrument, $timeframe, 100);
            }
            if (! $candlesData || empty($candlesData['candles'])) {
                $candlesData = $proxy->getCandles($instrument, $timeframe, $pocketInstrument->session_token, $pocketInstrument->uid ?? '');
            }

            $run->update([
                'status' => BotRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'analysis' => json_encode(['candles_count' => count($candlesData['candles'])]),
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => BotRun::STATUS_FAILED, 'error_message' => $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
