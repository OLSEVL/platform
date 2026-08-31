@extends('layouts.app')
@section('title', 'История сделок')
@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-semibold">История сделок</h1>
    <form method="GET" class="flex items-center gap-2 flex-wrap">
        @if(!empty($selectedTradeId))
            <input type="hidden" name="trade_id" value="{{ (int) $selectedTradeId }}">
        @endif
        <select name="bot_id" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все боты</option>
            @foreach($bots as $bot)
                <option value="{{ $bot->id }}" @selected($selectedBotId === $bot->id)>{{ $bot->name }}</option>
            @endforeach
        </select>
        <select name="result" class="rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm">
            <option value="">Все результаты</option>
            @foreach(['pending', 'win', 'loss', 'draw'] as $r)
                <option value="{{ $r }}" @selected($selectedResult === $r)>{{ $r }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded bg-zinc-800 px-3 py-1 text-sm hover:bg-zinc-700">Применить</button>
    </form>

    <div class="overflow-x-auto rounded border border-zinc-800">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-900 text-zinc-400 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Бот</th>
                    <th class="px-4 py-3 text-left">Инструмент</th>
                    <th class="px-4 py-3 text-left">Направление</th>
                    <th class="px-4 py-3 text-left">Сторона</th>
                    <th class="px-4 py-3 text-left">Цена входа</th>
                    <th class="px-4 py-3 text-left" title="Сумма ставки на Pocket Option при зеркалировании">Сумма ($)</th>
                    <th class="px-4 py-3 text-left" title="Момент сигнала и входа в paper (bots:scan)">Вход</th>
                    <th class="px-4 py-3 text-left" title="Запись в БД (обычно совпадает с входом)">Создана</th>
                    <th class="px-4 py-3 text-left" title="Когда ордер ушёл на Pocket Option">На PO</th>
                    <th class="px-4 py-3 text-left">Цена завершения</th>
                    <th class="px-4 py-3 text-left" title="Вход + длительность сделки (expiry_sec)">Экспирация</th>
                    <th class="px-4 py-3 text-left" title="Момент экспирации сделки (= expiry_time). Итог в БД пишется bots:settle сразу после экспирации.">Закрыта</th>
                    <th class="px-4 py-3 text-left">До конца</th>
                    <th class="px-4 py-3 text-left" title="Итог paper-сделки по свечам платформы">Paper</th>
                    <th class="px-4 py-3 text-left" title="Итог на Pocket Option (для мартингейла)">PO</th>
                    <th class="px-4 py-3 text-left">PnL</th>
                    <th class="px-4 py-3 text-left">Анализ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
                @forelse($trades as $t)
                    @php
                        // meta: mirrored_at, mirror_status, paper_result, mirror_po_* — колонки Paper/PO.
                        $meta = is_array($t->meta_json) ? $t->meta_json : [];
                        $paperResult = (string) ($meta['paper_result'] ?? $t->result ?? 'pending');
                        $poResult = isset($meta['mirror_po_result']) ? (string) $meta['mirror_po_result'] : null;
                        $poSource = isset($meta['mirror_po_result_source']) ? (string) $meta['mirror_po_result_source'] : null;
                        $poProfit = isset($meta['mirror_po_profit']) && is_numeric($meta['mirror_po_profit'])
                            ? (float) $meta['mirror_po_profit']
                            : null;
                        $mirrorStatus = isset($meta['mirror_status']) ? (string) $meta['mirror_status'] : null;
                        // PUT = вниз, CALL = вверх; иное оставляем как есть.
                        $directionLabel = match (strtoupper((string) $t->side)) {
                            'PUT' => 'ВНИЗ',
                            'CALL' => 'ВВЕРХ',
                            default => (string) $t->side,
                        };
                        $sideLabel = $directionLabel;
                        $mirroredAt = $meta['mirrored_at'] ?? null;
                        if (is_string($mirroredAt) && $mirroredAt !== '') {
                            try {
                                $mirroredAt = \Illuminate\Support\Carbon::parse($mirroredAt)->format('Y-m-d H:i:s');
                            } catch (\Throwable) {
                                $mirroredAt = substr($mirroredAt, 0, 19);
                            }
                        } else {
                            $mirroredAt = null;
                        }
                        $poTitle = ($poSource ? 'Источник: '.$poSource : 'Итог PO')
                            .($poProfit !== null ? ' profit='.$poProfit : '');
                    @endphp
                    <tr data-trade-id="{{ (int) $t->id }}"
                        class="hover:bg-zinc-900/50 @if(!empty($selectedTradeId) && (int) $selectedTradeId === (int) $t->id) bg-amber-900/40 ring-1 ring-amber-500/50 @endif">
                        <td class="px-4 py-3 text-zinc-300 font-mono">{{ (int) $t->id }}</td>
                        <td class="px-4 py-3 text-zinc-100">{{ $t->bot?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $t->instrument }}</td>
                        <td class="px-4 py-3 text-zinc-400 font-mono">{{ $directionLabel }}</td>
                        <td class="px-4 py-3 text-zinc-300">{{ $sideLabel }}</td>
                        <td class="px-4 py-3 text-zinc-300 font-mono">{{ $t->entry_price !== null ? number_format((float)$t->entry_price, 5) : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-200 font-mono tabular-nums">{{ $t->mirrorEntryAmountLabel() }}</td>
                        <td class="px-4 py-3 text-zinc-400 text-xs">
                            {{ optional($t->entry_time)->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-4 py-3 text-zinc-100">{{ optional($t->created_at)->format('Y-m-d H:i:s') }}</td>
                        <td class="px-4 py-3 text-zinc-400 text-xs">{{ $mirroredAt ?? '—' }}</td>
                        <td class="px-4 py-3 text-zinc-300 font-mono">{{ $t->expiry_price !== null ? number_format((float)$t->expiry_price, 5) : '—' }}</td>
                        <td class="px-4 py-3 text-zinc-400 text-xs">
                            {{ optional($t->expiry_time)->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-4 py-3 text-zinc-100">
                            @if($t->settled_at)
                                {{ $t->settled_at->format('Y-m-d H:i:s') }}
                            @else
                                <span class="text-zinc-500">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($t->status === 'open' && $t->expiry_time)
                                @php
                                    $left = max(0, now()->diffInSeconds($t->expiry_time, false));
                                    $mins = intdiv($left, 60);
                                    $secs = $left % 60;
                                @endphp
                                <span class="text-cyan-300 font-mono">{{ str_pad((string)$mins, 2, '0', STR_PAD_LEFT) }}:{{ str_pad((string)$secs, 2, '0', STR_PAD_LEFT) }}</span>
                            @elseif($t->status === 'open')
                                <span class="text-zinc-500">—</span>
                            @else
                                <span class="text-zinc-500">завершена</span>
                            @endif
                        </td>
                        {{-- Paper: итог по свечам платформы --}}
                        <td class="px-4 py-3" title="Paper (свечи платформы)">
                            @if($paperResult === 'win')
                                <span class="text-emerald-400">win</span>
                            @elseif($paperResult === 'loss')
                                <span class="text-rose-400">loss</span>
                            @elseif($paperResult === 'draw')
                                <span class="text-amber-400">draw</span>
                            @else
                                <span class="text-zinc-400">pending</span>
                            @endif
                        </td>
                        {{-- PO: только реальный successcloseOrder; po_assume_not_opened → «не подтверждено» --}}
                        <td class="px-4 py-3">
                            @if($poSource === 'po_assume_not_opened' || $mirrorStatus === 'assumed_not_opened')
                                <span class="text-amber-400" title="PO: не подтверждено открытие; мартингейл не изменяется">не подтверждено</span>
                            @elseif($poSource === 'paper_fallback')
                                <span class="text-amber-400" title="Не удалось получить successcloseOrder с PO; мартингейл по paper">ошибка</span>
                            @elseif($poResult === 'win')
                                <span class="text-emerald-400" title="{{ $poTitle }}">win</span>
                            @elseif($poResult === 'loss')
                                <span class="text-rose-400" title="{{ $poTitle }}">loss</span>
                            @elseif($poResult === 'draw')
                                <span class="text-amber-400" title="{{ $poTitle }}">draw</span>
                            @elseif($mirrorStatus === 'executed' && $t->status === 'open')
                                <span class="text-zinc-500" title="Ждём successcloseOrder после экспирации">…</span>
                            @elseif($mirrorStatus === 'executed')
                                <span class="text-zinc-600" title="PO-итог ещё не получен">—</span>
                            @else
                                <span class="text-zinc-600" title="Не зеркалилось на PO">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-300">{{ $t->pnl !== null ? number_format((float)$t->pnl, 4) : '—' }}</td>
                        <td class="px-4 py-3">
                            @if($t->bot)
                                {{-- Переход к "Сигнал → итог" с trade_id; на activity строка подсветится. --}}
                                <a href="{{ route('bots.activity', ['bot' => $t->bot->id, 'trade_id' => $t->id]) }}"
                                   class="inline-flex items-center rounded border border-zinc-700 bg-zinc-800 px-2 py-1 text-xs text-zinc-200 hover:bg-zinc-700">
                                    Открыть
                                </a>
                            @else
                                <span class="text-zinc-500 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="18" class="px-4 py-8 text-center text-zinc-500">Сделок пока нет.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $trades->links() }}
</div>
@if(!empty($selectedTradeId))
<script>
(function() {
    // После перехода из графика центрируем нужную строку, чтобы сделка была видна сразу.
    const targetId = @json((int) $selectedTradeId);
    const row = document.querySelector(`tr[data-trade-id="${targetId}"]`);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
})();
</script>
@endif
@endsection
