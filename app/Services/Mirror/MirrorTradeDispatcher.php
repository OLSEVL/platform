<?php

namespace App\Services\Mirror;

use App\Jobs\MirrorBotTradeJob;
use App\Models\BotTrade;
use App\Services\Bots\BotTradeMirrorService;
use Illuminate\Support\Facades\Log;

/**
 * Отправка зеркала на PO: по умолчанию сразу после paper-сделки (sync),
 * без ожидания queue worker — иначе задержка минутами при backlog.
 */
class MirrorTradeDispatcher
{
    public function dispatch(int $botTradeId): void
    {
        if (config('services.mirror.dispatch', 'sync') === 'queue') {
            MirrorBotTradeJob::dispatch($botTradeId)->onQueue('mirror');

            return;
        }

        try {
            $trade = BotTrade::query()->find($botTradeId);
            if (! $trade) {
                return;
            }

            app(BotTradeMirrorService::class)->mirrorTrade($trade->fresh());

            $trade->refresh();
            $status = (string) (($trade->meta_json ?? [])['mirror_status'] ?? '');

            // Таймаут/502 proxy — повтор через очередь через несколько секунд.
            if ($status === 'failed') {
                $err = (string) (($trade->meta_json ?? [])['mirror_error'] ?? '');
                if ($this->shouldRetryViaQueue($err)) {
                    MirrorBotTradeJob::dispatch($botTradeId)
                        ->onQueue('mirror')
                        ->delay(now()->addSeconds(3));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Mirror sync failed, queued retry', [
                'trade_id' => $botTradeId,
                'error' => $e->getMessage(),
            ]);
            MirrorBotTradeJob::dispatch($botTradeId)->onQueue('mirror');
        }
    }

    private function shouldRetryViaQueue(string $error): bool
    {
        $needles = [
            'Timeout',
            'timeout',
            '502',
            '503',
            'Connection',
            'proxy',
            'not connected after auth',
        ];

        foreach ($needles as $needle) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
