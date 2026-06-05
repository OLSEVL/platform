<?php

namespace App\Support;

use App\Models\PocketInstrument;
use App\Models\ProxyEndpoint;

/**
 * Прокси для WebSocket Pocket Option (тот же, что у ingest).
 * Без прокси auth/ордера часто не проходят, хотя котировки через ingest идут.
 */
class PocketMirrorProxy
{
    public static function resolveForInstrument(?string $instrument): ?string
    {
        if ($instrument !== null && $instrument !== '') {
            $row = PocketInstrument::query()
                ->where('instrument', $instrument)
                ->with('proxyEndpoint')
                ->first();

            $url = $row?->proxyEndpoint?->url;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return ProxyEndpoint::query()
            ->readyForAssignment()
            ->orderBy('id')
            ->value('url');
    }
}
