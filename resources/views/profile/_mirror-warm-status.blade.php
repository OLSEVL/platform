{{-- Статус sticky warm pool зеркала PO (торговый профиль / прокси зеркала). --}}
@php
    $warm = $mirrorWarmStatus ?? [];
    $state = (string) ($warm['state'] ?? 'cold');
    $stateClass = match ($state) {
        'warm' => 'text-emerald-400',
        'disabled', 'incomplete' => 'text-zinc-400',
        'error' => 'text-rose-400',
        default => 'text-amber-300',
    };
    $proxyLabel = null;
    if (!empty($warm['proxy_host'])) {
        $proxyLabel = $warm['proxy_host'];
        if (!empty($warm['proxy_port'])) {
            $proxyLabel .= ':'.$warm['proxy_port'];
        }
    }
@endphp
<div class="rounded border border-zinc-700 bg-zinc-950/50 px-4 py-3 text-xs space-y-1">
    <p class="font-medium text-zinc-300">Sticky warm (канал PO)</p>
    <p class="{{ $stateClass }}">
        {{ $warm['label'] ?? '—' }}
        @if ($proxyLabel)
            <span class="font-mono text-zinc-300"> · {{ $proxyLabel }}</span>
            @if (!empty($warm['proxy_endpoint_id']))
                <span class="text-zinc-500">#{{ $warm['proxy_endpoint_id'] }}</span>
            @endif
        @endif
    </p>
    @if (!empty($warm['detail']))
        <p class="text-zinc-500">{{ $warm['detail'] }}</p>
    @endif
</div>
