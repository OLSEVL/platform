@extends('profile._layout')
@section('title', 'Профиль — Прокси зеркала')

@section('profile_content')
<div class="rounded border border-zinc-800 bg-zinc-900/60 p-5 space-y-5">
    <div>
        <h2 class="text-lg font-medium text-slate-100">Прокси для зеркала</h2>
        <p class="mt-1 text-sm text-zinc-400">
            Ручной пул: случайный из отмеченных. Авто: каждый раз берётся active-прокси
            с минимальным числом пар (нагрузка). Dead в список не попадают.
        </p>
        @if ($mirrorProxyAuto && $autoLastId > 0 && $autoLastAt)
            <p class="mt-2 text-xs text-emerald-400/90">
                Последний авто-выбор:
                <span class="font-mono">#{{ $autoLastId }}</span>
                · {{ $autoLastAt->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}
            </p>
        @elseif ($mirrorProxyAuto)
            <p class="mt-2 text-xs text-zinc-500">
                Авто включён: подсвечены кандидаты с минимальной нагрузкой.
                Фактический выбор появится после первого зеркала / теста SSID.
            </p>
        @endif
    </div>

    @if ($errors->any())
        <div class="rounded border border-rose-800 bg-rose-950/40 px-4 py-3 text-sm text-rose-300">
            <p class="font-medium">Не удалось сохранить:</p>
            <ul class="mt-1 list-disc pl-5 text-xs">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.mirror-proxies.update') }}" class="space-y-4" id="mirror-proxies-form">
        @csrf
        @method('PUT')

        <label class="flex items-start gap-2 text-sm text-zinc-200 cursor-pointer">
            <input type="hidden" name="mirror_proxy_auto" value="0">
            <input type="checkbox" name="mirror_proxy_auto" id="mirror_proxy_auto" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-950 text-blue-600 focus:ring-blue-500"
                @checked($mirrorProxyAuto)>
            <span>
                <span class="font-medium">Авто: минимальная нагрузка</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                    Берётся active-прокси с наименьшим числом пар. Ручные галочки при этом недоступны;
                    в колонке «Авто» видно кандидатов и фактические выборы.
                </span>
            </span>
        </label>

        @if (empty($mirrorProxies))
            <p class="rounded border border-zinc-700 bg-zinc-950/50 px-3 py-2 text-xs text-zinc-500">
                Нет active-прокси в пуле. Добавьте их в настройках прокси.
            </p>
        @else
            <div class="overflow-x-auto rounded border border-zinc-700" id="mirror-proxy-table-wrap">
                <table class="min-w-full text-left text-xs text-zinc-300">
                    <thead class="bg-zinc-950/80 text-[11px] uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-3 py-1.5 font-medium">Использовать</th>
                            <th class="px-3 py-1.5 font-medium">Авто</th>
                            <th class="px-3 py-1.5 font-medium">Прокси</th>
                            <th class="px-3 py-1.5 font-medium text-right">Пар</th>
                            <th class="px-3 py-1.5 font-medium text-right">Ошибок</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800">
                        @foreach ($mirrorProxies as $proxy)
                            @php
                                $rowClass = 'bg-zinc-950/40 hover:bg-zinc-900/60';
                                if (!empty($proxy['is_auto_last'])) {
                                    $rowClass = 'bg-emerald-950/50 ring-1 ring-inset ring-emerald-700/60';
                                } elseif (!empty($proxy['is_auto_candidate'])) {
                                    $rowClass = 'bg-sky-950/35';
                                } elseif (!empty($proxy['auto_pick_count'])) {
                                    $rowClass = 'bg-zinc-900/70';
                                }
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="px-3 py-1.5">
                                    <input type="checkbox"
                                        name="mirror_proxy_endpoint_ids[]"
                                        value="{{ $proxy['id'] }}"
                                        class="mirror-proxy-cb rounded border-zinc-600 bg-zinc-950 text-blue-600 focus:ring-blue-500 disabled:opacity-40 disabled:cursor-not-allowed"
                                        @checked(in_array((int) $proxy['id'], $selectedMirrorProxyIds, true))
                                        @disabled($mirrorProxyAuto)>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    @if ($mirrorProxyAuto)
                                        @if (!empty($proxy['is_auto_last']))
                                            <span class="inline-flex items-center rounded bg-emerald-800/70 px-1.5 py-0.5 text-[10px] font-medium text-emerald-100"
                                                title="Последний фактический выбор авто">
                                                последний
                                                @if (!empty($proxy['auto_pick_count']) && $proxy['auto_pick_count'] > 1)
                                                    · {{ $proxy['auto_pick_count'] }}×
                                                @endif
                                            </span>
                                        @elseif (!empty($proxy['auto_pick_count']))
                                            <span class="inline-flex items-center rounded bg-zinc-700/80 px-1.5 py-0.5 text-[10px] text-zinc-200"
                                                title="Сколько раз выбирался авто недавно">
                                                был · {{ $proxy['auto_pick_count'] }}×
                                            </span>
                                        @elseif (!empty($proxy['is_auto_candidate']))
                                            <span class="inline-flex items-center rounded bg-sky-800/60 px-1.5 py-0.5 text-[10px] text-sky-100"
                                                title="Сейчас в пуле минимальной нагрузки">
                                                кандидат
                                            </span>
                                        @else
                                            <span class="text-zinc-600">—</span>
                                        @endif
                                    @else
                                        <span class="text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 font-mono text-[11px] text-zinc-200 whitespace-nowrap" title="{{ $proxy['url_masked'] }}">
                                    {{ $proxy['label'] }}{{ !empty($proxy['port']) ? ':'.$proxy['port'] : '' }}
                                    <span class="text-zinc-500"> · {{ $proxy['url_masked'] }}</span>
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">{{ $proxy['instruments_count'] }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums {{ $proxy['failure_count'] > 0 ? 'text-amber-300' : 'text-zinc-400' }}">
                                    {{ $proxy['failure_count'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <button type="submit"
            class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 transition-colors">
            Сохранить
        </button>
    </form>
</div>

<script>
(function () {
    const autoEl = document.getElementById('mirror_proxy_auto');
    if (!autoEl) return;

    function syncManualCheckboxes() {
        const disabled = autoEl.checked;
        document.querySelectorAll('.mirror-proxy-cb').forEach(function (cb) {
            cb.disabled = disabled;
        });
        // Не затемняем всю таблицу в авто — иначе плохо видно метки «последний / кандидат».
    }

    autoEl.addEventListener('change', syncManualCheckboxes);
    syncManualCheckboxes();
})();
</script>
@endsection
