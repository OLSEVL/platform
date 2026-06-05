<?php

namespace App\Jobs;

use App\Models\BotTrade;
use App\Services\Bots\BotTradeMirrorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class MirrorBotTradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** place-order через proxy: auth + ожидание ордера. */
    public int $timeout = 150;

    public function __construct(
        public int $botTradeId,
    ) {
        $this->onQueue('mirror');
    }

    public function handle(BotTradeMirrorService $mirrorService): void
    {
        $trade = BotTrade::query()->find($this->botTradeId);
        if (! $trade) {
            return;
        }

        $mirrorService->mirrorTrade($trade);
    }

    public function failed(?\Throwable $exception): void
    {
        $trade = BotTrade::query()->find($this->botTradeId);
        if (! $trade) {
            return;
        }

        $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
        if (($meta['mirror_status'] ?? '') === 'executed') {
            return;
        }

        $meta['mirror_status'] = 'failed';
        $meta['mirror_error'] = mb_substr(
            'Queue: '.($exception?->getMessage() ?? 'unknown'),
            0,
            2000
        );
        $meta['mirrored_at'] = Carbon::now()->toIso8601String();
        $trade->meta_json = $meta;
        $trade->save();
    }
}
