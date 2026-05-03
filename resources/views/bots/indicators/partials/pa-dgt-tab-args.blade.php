{{-- Вкладка «Аргументы»: числа, диапазоны, источники (как Inputs в TradingView). --}}
@php
    $p = $pa;
    $lk = ['visible' => 'Видимый диапазон', 'fixed' => 'Фиксированный диапазон'];
    $src = ['volume' => 'Объём', 'price' => 'Цена'];
    $lvl = ['High' => 'High', 'Close' => 'Close', 'Both' => 'Both'];
@endphp

<div class="space-y-6">
    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Поддержка / сопротивление</h3>
        <div class="space-y-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Диапазон lookback</span>
                <select name="sr_lookback_range" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($lk as $val => $label)
                        <option value="{{ $val }}" @selected(old('sr_lookback_range', $p['sr_lookback_range']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Фикс. lookback (бары)</span>
                <input type="number" name="sr_fixed_lookback_bars" min="10" max="5000" required
                    value="{{ old('sr_fixed_lookback_bars', $p['sr_fixed_lookback_bars']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Источник S&amp;R</span>
                <select name="sr_source" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($src as $val => $label)
                        <option value="{{ $val }}" @selected(old('sr_source', $p['sr_source']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Всплеск объёма</h3>
        <div class="space-y-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Порог × SMA объёма</span>
                <input type="number" name="v_spike_threshold" step="0.001" min="0.01" max="100" required
                    value="{{ old('v_spike_threshold', $p['v_spike_threshold']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Бычий уровень</span>
                    <select name="v_spike_bull_level" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                        @foreach ($lvl as $val => $label)
                            <option value="{{ $val }}" @selected(old('v_spike_bull_level', $p['v_spike_bull_level']) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Медвежий уровень</span>
                    <select name="v_spike_bear_level" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                        @foreach ($lvl as $val => $label)
                            <option value="{{ $val }}" @selected(old('v_spike_bear_level', $p['v_spike_bear_level']) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">ATR</h3>
        <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Длина</span>
                <input type="number" name="atr_length" min="1" max="500" required
                    value="{{ old('atr_length', $p['atr_length']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Множитель</span>
                <input type="number" name="atr_mult" step="0.001" min="0.01" max="100" required
                    value="{{ old('atr_mult', $p['atr_mult']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Бычий уровень</span>
                <select name="atr_bull_level" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($lvl as $val => $label)
                        <option value="{{ $val }}" @selected(old('atr_bull_level', $p['atr_bull_level']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Медвежий уровень</span>
                <select name="atr_bear_level" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($lvl as $val => $label)
                        <option value="{{ $val }}" @selected(old('atr_bear_level', $p['atr_bear_level']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Объём</h3>
        <div class="rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Длина SMA объёма</span>
                <input type="number" name="volume_sma_length" min="1" max="500" required
                    value="{{ old('volume_sma_length', $p['volume_sma_length']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Профиль объёма</h3>
        <div class="space-y-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Диапазон lookback VP</span>
                <select name="vp_lookback_range" class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @foreach ($lk as $val => $label)
                        <option value="{{ $val }}" @selected(old('vp_lookback_range', $p['vp_lookback_range']) === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Фикс. VP (бары)</span>
                    <input type="number" name="vp_fixed_lookback_bars" min="10" max="5000" required
                        value="{{ old('vp_fixed_lookback_bars', $p['vp_fixed_lookback_bars']) }}"
                        class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Уровней профиля</span>
                    <input type="number" name="vp_profile_levels" min="10" max="170" required
                        value="{{ old('vp_profile_levels', $p['vp_profile_levels']) }}"
                        class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                </label>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Смещение (бары)</span>
                    <input type="number" name="vp_horizontal_offset_bars" min="0" max="200" required
                        value="{{ old('vp_horizontal_offset_bars', $p['vp_horizontal_offset_bars']) }}"
                        class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-zinc-400">Value area %</span>
                    <input type="number" name="vp_value_area_pct" min="1" max="99" required
                        value="{{ old('vp_value_area_pct', $p['vp_value_area_pct']) }}"
                        class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                </label>
            </div>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Supply / Demand</h3>
        <div class="rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Низкий объём / порог %</span>
                <input type="number" name="sd_low_vol_ratio_pct" step="0.1" min="1" max="50" required
                    value="{{ old('sd_low_vol_ratio_pct', $p['sd_low_vol_ratio_pct']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Окраска свечей (VWCB)</h3>
        <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-700/60 bg-zinc-900/40 p-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Порог «высокий» × SMA</span>
                <input type="number" name="vwcb_high" step="0.001" min="0.01" max="20" required
                    value="{{ old('vwcb_high', $p['vwcb_high']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-400">Порог «низкий» × SMA</span>
                <input type="number" name="vwcb_low" step="0.001" min="0.01" max="20" required
                    value="{{ old('vwcb_low', $p['vwcb_low']) }}"
                    class="rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
            </label>
        </div>
    </div>
</div>
