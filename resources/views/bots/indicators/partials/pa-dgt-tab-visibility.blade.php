{{-- Вкладка «Видимость»: что показывать на графике. --}}
@php $p = $pa; @endphp

<div class="space-y-2">
    @foreach ([
        'sr_lines' => 'Линии поддержки / сопротивления',
        'v_spike_markers' => 'Маркеры всплеска объёма',
        'v_spike_lines' => 'Линии всплеска объёма',
        'atr_markers' => 'Маркеры высокой волатильности (ATR)',
        'atr_lines' => 'Линии ATR',
        'volume_profile' => 'Профиль объёма',
        'vp_vah' => 'VAH (верх зоны стоимости)',
        'vp_poc' => 'POC (контрольная цена)',
        'vp_val' => 'VAL (низ зоны стоимости)',
        'vp_price_levels' => 'Подписи уровней VP',
        'supply_demand' => 'Зоны supply / demand',
        'vwcb' => 'Окраска свечей по объёму (VWCB)',
    ] as $key => $label)
        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-700/50 bg-zinc-900/30 px-3 py-2 hover:bg-zinc-800/40">
            {{-- hidden + checkbox: при ошибке валидации состояние чекбоксов сохраняется через old(). --}}
            <input type="hidden" name="{{ $key }}" value="0">
            <input type="checkbox" name="{{ $key }}" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((string) old($key, ! empty($p[$key]) ? '1' : '0') === '1')>
            <span class="text-sm text-zinc-200">{{ $label }}</span>
        </label>
    @endforeach
</div>
