<?php

namespace App\Console\Commands;

use App\Models\BotTrade;
use App\Services\Bots\BotTradeMirrorService;
use Illuminate\Console\Command;

class RetryMirrorOpenTradesCommand extends Command
{
    protected $signature = 'bots:mirror-retry-open {--limit=50}';

    protected $description = 'Повторить зеркалирование открытых сделок с mirror_status failed/skipped';

    public function handle(BotTradeMirrorService $mirror): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = BotTrade::query()
            ->where('status', BotTrade::STATUS_OPEN)
            ->where('expiry_time', '>', now())
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(function (BotTrade $trade): bool {
                $status = (string) (($trade->meta_json ?? [])['mirror_status'] ?? '');

                return $status !== 'executed';
            });

        $ok = 0;
        $fail = 0;
        foreach ($rows as $trade) {
            $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
            unset($meta['mirror_status'], $meta['mirror_error'], $meta['pocket_order_id'], $meta['mirrored_at'], $meta['mirror_proxy_response']);
            $meta['mirror_retry_allowed'] = true;
            $trade->meta_json = $meta;
            $trade->save();

            $mirror->mirrorTrade($trade->fresh());
            $trade->refresh();
            $st = (string) (($trade->meta_json ?? [])['mirror_status'] ?? '');
            if ($st === 'executed') {
                $ok++;
                $this->info("#{$trade->id} {$trade->instrument} → executed");
            } else {
                $fail++;
                $err = substr((string) (($trade->meta_json ?? [])['mirror_error'] ?? $st), 0, 120);
                $this->warn("#{$trade->id} {$trade->instrument} → {$st} {$err}");
            }
        }

        $this->info("Готово: executed={$ok}, остальные={$fail}");

        return self::SUCCESS;
    }
}
