<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotTrade extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const RESULT_PENDING = 'pending';

    public const RESULT_WIN = 'win';

    public const RESULT_LOSS = 'loss';

    public const RESULT_DRAW = 'draw';

    protected $fillable = [
        'bot_id',
        'bot_signal_id',
        'instrument',
        'side',
        'status',
        'result',
        'entry_time',
        'expiry_time',
        'settled_at',
        'entry_price',
        'expiry_price',
        'payout_snapshot',
        'pnl',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'entry_time' => 'datetime',
            'expiry_time' => 'datetime',
            'settled_at' => 'datetime',
            'entry_price' => 'float',
            'expiry_price' => 'float',
            'payout_snapshot' => 'float',
            'pnl' => 'float',
            'meta_json' => 'array',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(BotSignal::class, 'bot_signal_id');
    }

    public function llmLogs(): HasMany
    {
        return $this->hasMany(BotLlmLog::class, 'bot_trade_id');
    }

    /** Сумма ставки на PO ($) из meta зеркала; для старых сделок может быть null. */
    public function mirrorEntryAmount(): ?float
    {
        return self::mirrorAmountFromMeta(is_array($this->meta_json) ? $this->meta_json : null);
    }

    /** @param  array<string, mixed>|null  $meta */
    public static function mirrorAmountFromMeta(?array $meta): ?float
    {
        if ($meta === null) {
            return null;
        }
        if (isset($meta['mirror_amount']) && is_numeric($meta['mirror_amount'])) {
            return (float) $meta['mirror_amount'];
        }
        $proxy = $meta['mirror_proxy_response'] ?? null;
        if (is_array($proxy) && isset($proxy['amount']) && is_numeric($proxy['amount'])) {
            return (float) $proxy['amount'];
        }

        return null;
    }

    /** Подпись для таблиц: «$12.5» или «—», если зеркало не отправляло сумму. */
    public function mirrorEntryAmountLabel(): string
    {
        return self::formatMirrorAmountLabel($this->mirrorEntryAmount());
    }

    public static function formatMirrorAmountLabel(?float $amount): string
    {
        if ($amount === null || $amount <= 0) {
            return '—';
        }

        $formatted = number_format($amount, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return '$'.$formatted;
    }
}
