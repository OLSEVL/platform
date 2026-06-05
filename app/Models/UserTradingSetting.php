<?php

namespace App\Models;

use App\Support\MartingaleAmountsParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTradingSetting extends Model
{
    protected $fillable = [
        'user_id',
        'pocket_ssid',
        'is_demo',
        'order_amount',
        'order_amounts',
        'martingale_step',
        'mirror_trades_enabled',
    ];

    protected function casts(): array
    {
        return [
            'pocket_ssid' => 'encrypted',
            'is_demo' => 'boolean',
            'order_amount' => 'float',
            'order_amounts' => 'array',
            'martingale_step' => 'integer',
            'mirror_trades_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<float> */
    public function orderAmountsList(): array
    {
        $raw = $this->order_amounts;
        if (! is_array($raw) || $raw === []) {
            $fallback = (float) ($this->order_amount ?? 0);

            return $fallback > 0 ? [$fallback] : [];
        }

        $amounts = [];
        foreach ($raw as $item) {
            if (is_numeric($item)) {
                $amounts[] = (float) $item;
            }
        }

        return $amounts;
    }

    public function orderAmountsInputString(): string
    {
        return MartingaleAmountsParser::formatListForInput($this->orderAmountsList());
    }

    /** Сумма ставки на PO для текущего шага мартингейла. */
    public function currentMirrorAmount(): float
    {
        $amounts = $this->orderAmountsList();
        if ($amounts === []) {
            return 0.0;
        }

        $step = max(0, (int) $this->martingale_step);
        $index = $step % count($amounts);

        return (float) $amounts[$index];
    }

    public function currentMirrorAmountLabel(): string
    {
        return \App\Models\BotTrade::formatMirrorAmountLabel($this->currentMirrorAmount());
    }

    /**
     * @return array{step: int, total: int, amount_label: string, list: string}
     */
    public function martingaleStatus(): array
    {
        $amounts = $this->orderAmountsList();
        $total = count($amounts);
        $step = $total > 0 ? max(0, min((int) $this->martingale_step, $total - 1)) : 0;

        return [
            'step' => $step,
            'total' => $total,
            'amount_label' => $total > 0
                ? \App\Models\BotTrade::formatMirrorAmountLabel($amounts[$step])
                : '—',
            'list' => MartingaleAmountsParser::formatListForInput($amounts),
        ];
    }

    public function syncPrimaryOrderAmountFromList(): void
    {
        $amounts = $this->orderAmountsList();
        $this->order_amount = $amounts !== [] ? (float) $amounts[0] : 0.0;
    }

    public function normalizeMartingaleStep(): void
    {
        $total = count($this->orderAmountsList());
        if ($total <= 0) {
            $this->martingale_step = 0;

            return;
        }

        $this->martingale_step = max(0, (int) $this->martingale_step) % $total;
    }

    /** SSID задан и можно пытаться зеркалировать сделки. */
    public function canMirrorTrades(): bool
    {
        return $this->mirror_trades_enabled
            && is_string($this->pocket_ssid)
            && trim($this->pocket_ssid) !== ''
            && $this->currentMirrorAmount() >= MartingaleAmountsParser::MIN_AMOUNT;
    }
}
