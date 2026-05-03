{{-- Вкладка «Стиль»: цвета, толщина и тип линий. --}}
@php
    $p = $pa;
    $styles = ['Solid' => 'Сплошная', 'Dotted' => 'Точки', 'Dashed' => 'Штрихи'];
@endphp

<div class="space-y-6">
    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Линии S&amp;R</h3>
        <div class="grid gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3 sm:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Цвет (#hex или #rrggbbaa)</span>
                <input type="text" name="sr_line_color" required maxlength="12"
                    value="{{ old('sr_line_color', $p['sr_line_color']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Толщина</span>
                <input type="number" name="sr_line_width" min="1" max="20" required
                    value="{{ old('sr_line_width', $p['sr_line_width']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Стиль</span>
                <select name="sr_line_style" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($styles as $val => $label)
                        <option value="{{ $val }}" @selected(old('sr_line_style', $p['sr_line_style']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Всплеск объёма — линии</h3>
        <div class="grid gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3 sm:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Цвет</span>
                <input type="text" name="v_spike_line_color" required maxlength="12"
                    value="{{ old('v_spike_line_color', $p['v_spike_line_color']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Толщина</span>
                <input type="number" name="v_spike_line_width" min="1" max="20" required
                    value="{{ old('v_spike_line_width', $p['v_spike_line_width']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Стиль</span>
                <select name="v_spike_line_style" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($styles as $val => $label)
                        <option value="{{ $val }}" @selected(old('v_spike_line_style', $p['v_spike_line_style']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">ATR — линии</h3>
        <div class="grid gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3 sm:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Цвет</span>
                <input type="text" name="atr_line_color" required maxlength="12"
                    value="{{ old('atr_line_color', $p['atr_line_color']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Толщина</span>
                <input type="number" name="atr_line_width" min="1" max="20" required
                    value="{{ old('atr_line_width', $p['atr_line_width']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Стиль</span>
                <select name="atr_line_style" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($styles as $val => $label)
                        <option value="{{ $val }}" @selected(old('atr_line_style', $p['atr_line_style']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Профиль объёма — цвета</h3>
        <div class="grid gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3 sm:grid-cols-2">
            @foreach ([
                'vp_vah_color' => 'VAH',
                'vp_poc_color' => 'POC',
                'vp_val_color' => 'VAL',
                'vp_label_color' => 'Подписи',
                'vp_avg_node_color' => 'Узел средний',
                'vp_high_node_color' => 'Узел высокий',
                'vp_low_node_color' => 'Узел низкий',
            ] as $key => $label)
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">{{ $label }}</span>
                    <input type="text" name="{{ $key }}" required maxlength="12"
                        value="{{ old($key, $p[$key]) }}"
                        class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-100">
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Supply / Demand</h3>
        <div class="rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Цвет зоны</span>
                <input type="text" name="sd_zone_color" required maxlength="12"
                    value="{{ old('sd_zone_color', $p['sd_zone_color']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-100">
            </label>
        </div>
    </div>
</div>
