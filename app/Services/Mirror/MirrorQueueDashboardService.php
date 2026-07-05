<?php

namespace App\Services\Mirror;

use App\Models\BotTrade;
use App\Models\UserTradingSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Снимок очереди зеркалирования PO: Laravel jobs + статус mirror по сделкам пользователя.
 */
class MirrorQueueDashboardService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingMirrorJobsByTradeId(): array
    {
        $map = [];
        foreach ($this->loadPendingMirrorJobRows() as $row) {
            $tradeId = $this->extractBotTradeIdFromJobPayload((string) $row->payload);
            if ($tradeId > 0) {
                $map[$tradeId] = $this->formatJobRow($row, $tradeId);
            }
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    public function pendingMirrorJobsList(): array
    {
        $list = [];
        foreach ($this->loadPendingMirrorJobRows() as $row) {
            $tradeId = $this->extractBotTradeIdFromJobPayload((string) $row->payload);
            if ($tradeId <= 0) {
                continue;
            }
            $list[] = $this->formatJobRow($row, $tradeId);
        }

        return $list;
    }

    /** @return array<int, array{failed_at: string|null, uuid: string|null}> */
    private function recentFailedMirrorJobsByTradeId(): array
    {
        $map = [];
        if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            return $map;
        }

        $rows = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['uuid', 'payload', 'failed_at']);

        foreach ($rows as $row) {
            $tradeId = $this->extractBotTradeIdFromJobPayload((string) $row->payload);
            if ($tradeId > 0 && ! isset($map[$tradeId])) {
                $map[$tradeId] = [
                    'failed_at' => $this->formatTimestamp($row->failed_at),
                    'uuid' => $row->uuid ?? null,
                ];
            }
        }

        return $map;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function loadPendingMirrorJobRows()
    {
        return DB::table('jobs')
            ->where('queue', 'default')
            ->orderBy('id')
            ->get(['id', 'payload', 'attempts', 'created_at', 'available_at', 'reserved_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJobRow(object $row, int $tradeId): array
    {
        $queuedAt = $this->formatTimestamp($row->created_at);
        $startedAt = $this->formatTimestamp($row->reserved_at);

        return [
            'job_id' => (int) $row->id,
            'trade_id' => $tradeId,
            'attempts' => (int) $row->attempts,
            'queued_at' => $queuedAt,
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_sec' => $this->durationSeconds($queuedAt, null),
            'available_at' => $this->formatTimestamp($row->available_at),
            'state' => $row->reserved_at ? 'processing' : 'queued',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshotForUser(int $userId): array
    {
        $settings = UserTradingSetting::query()->where('user_id', $userId)->first();
        $pendingByTrade = $this->pendingMirrorJobsByTradeId();
        $pendingJobs = $this->pendingMirrorJobsList();
        $failedByTrade = $this->recentFailedMirrorJobsByTradeId();

        $from = now()->subHours(6);
        $trades = BotTrade::query()
            ->with('bot:id,name,user_id')
            ->whereHas('bot', fn ($q) => $q->where('user_id', $userId))
            ->where('created_at', '>=', $from)
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $tradeRows = $trades->map(function (BotTrade $trade) use ($pendingByTrade, $failedByTrade, $settings) {
            $meta = is_array($trade->meta_json) ? $trade->meta_json : [];
            $mirrorStatus = (string) ($meta['mirror_status'] ?? '');
            $pending = $pendingByTrade[$trade->id] ?? null;
            $queueState = $this->resolveQueueState($trade, $mirrorStatus, $pending, $settings);

            $finishedAt = $this->formatTimestamp($meta['mirrored_at'] ?? null);
            if ($finishedAt === null && isset($failedByTrade[$trade->id])) {
                $finishedAt = $failedByTrade[$trade->id]['failed_at'];
            }

            // Пока job в очереди — берём время из jobs; иначе момент создания paper-сделки (~dispatch job).
            $queuedAt = $pending['queued_at'] ?? optional($trade->created_at)->format('Y-m-d H:i:s');
            $startedAt = $pending['started_at'] ?? null;

            return [
                'id' => $trade->id,
                'bot_name' => $trade->bot?->name ?? '—',
                'instrument' => $trade->instrument,
                'side' => $trade->side,
                'paper_status' => $trade->status,
                'entry_time' => optional($trade->entry_time)->format('Y-m-d H:i:s'),
                'expiry_time' => optional($trade->expiry_time)->format('Y-m-d H:i:s'),
                'expiry_ts' => optional($trade->expiry_time)?->timestamp,
                'mirror_status' => $mirrorStatus !== '' ? $mirrorStatus : null,
                'queue_state' => $queueState,
                'job_id' => $pending['job_id'] ?? null,
                'job_queued_at' => $queuedAt,
                'job_started_at' => $startedAt,
                'job_finished_at' => $finishedAt,
                'job_duration_sec' => $this->durationSeconds($queuedAt, $finishedAt),
                'mirror_latency_ms' => isset($meta['mirror_latency_ms']) ? (int) $meta['mirror_latency_ms'] : null,
                'mirror_dispatch' => $meta['mirror_dispatch'] ?? null,
                'pocket_order_id' => $meta['pocket_order_id'] ?? null,
                'mirror_is_demo' => isset($meta['mirror_is_demo']) ? (bool) $meta['mirror_is_demo'] : null,
                'mirror_error' => isset($meta['mirror_error']) ? mb_substr((string) $meta['mirror_error'], 0, 300) : null,
                'mirror_proxy_host' => isset($meta['mirror_proxy_host']) ? (string) $meta['mirror_proxy_host'] : null,
                'mirror_proxy_url' => isset($meta['mirror_proxy_url']) ? (string) $meta['mirror_proxy_url'] : null,
                'mirror_proxy_direct' => (bool) ($meta['mirror_proxy_direct'] ?? false),
                'mirrored_at' => $meta['mirrored_at'] ?? null,
                'confirmed' => ($meta['mirror_proxy_response']['confirmed'] ?? null),
            ];
        })->values();

        $proxy = $this->proxyHealth();

        $executedRecent = $trades->filter(
            fn (BotTrade $t) => ($t->meta_json['mirror_status'] ?? '') === 'executed'
        )->count();

        $failedRecent = $trades->filter(
            fn (BotTrade $t) => ($t->meta_json['mirror_status'] ?? '') === 'failed'
        )->count();

        $martingale = $settings?->martingaleStatus() ?? [
            'step' => 0,
            'total' => 0,
            'amount_label' => '—',
            'list' => '',
        ];

        return [
            'server_time' => now()->toIso8601String(),
            'from' => $from->toIso8601String(),
            'summary' => [
                'mirror_enabled' => (bool) ($settings?->mirror_trades_enabled),
                'mirror_ready' => $settings?->canMirrorTrades() ?? false,
                'is_demo' => (bool) ($settings?->is_demo ?? true),
                'order_amount' => $settings?->order_amount,
                'martingale_step' => $martingale['step'],
                'martingale_total' => $martingale['total'],
                'martingale_amount_label' => $martingale['amount_label'],
                'order_amounts_list' => $martingale['list'],
                'jobs_pending' => count($pendingJobs),
                'jobs_failed' => (int) DB::table('failed_jobs')->count(),
                'proxy_ok' => $proxy['ok'],
                'proxy_has_place_order' => $proxy['has_place_order'],
                'trades_shown' => $tradeRows->count(),
                'mirror_executed_recent' => $executedRecent,
                'mirror_failed_recent' => $failedRecent,
            ],
            'pending_jobs' => $pendingJobs,
            'trades' => $tradeRows,
        ];
    }

    /**
     * @param  array{job_id: int, state: string}|null  $pending
     */
    private function resolveQueueState(
        BotTrade $trade,
        string $mirrorStatus,
        ?array $pending,
        ?UserTradingSetting $settings,
    ): string {
        if ($pending !== null) {
            return $pending['state'];
        }

        if ($mirrorStatus === 'executed') {
            return 'done_ok';
        }
        if ($mirrorStatus === 'failed') {
            return 'done_fail';
        }
        if (str_starts_with($mirrorStatus, 'skipped_')) {
            return 'skipped';
        }

        if (! $settings?->mirror_trades_enabled) {
            return 'mirror_off';
        }

        if ($trade->status !== BotTrade::STATUS_OPEN) {
            return 'paper_closed';
        }

        if ($trade->expiry_time && $trade->expiry_time->isPast()) {
            return 'expired';
        }

        return 'pending';
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function durationSeconds(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            $sec = Carbon::parse($to)->diffInSeconds(Carbon::parse($from), false);

            return $sec >= 0 ? $sec : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractBotTradeIdFromJobPayload(string $payload): int
    {
        if (! str_contains($payload, 'MirrorBotTradeJob')) {
            return 0;
        }

        if (preg_match('/botTradeId\";i:(\d+)/', $payload, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/botTradeId";i:(\d+)/', $payload, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /** @return array{ok: bool, has_place_order: bool} */
    private function proxyHealth(): array
    {
        $baseUrl = rtrim((string) config('services.pocket_options_proxy.url', ''), '/');
        if ($baseUrl === '') {
            return ['ok' => false, 'has_place_order' => false];
        }

        try {
            $body = Http::timeout(3)->get("{$baseUrl}/")->json();
            $endpoints = json_encode($body['endpoints'] ?? [], JSON_UNESCAPED_UNICODE);

            return [
                'ok' => is_array($body),
                'has_place_order' => str_contains($endpoints, 'place-order'),
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'has_place_order' => false];
        }
    }
}
