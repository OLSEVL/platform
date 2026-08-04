@extends('layouts.app')
@section('title', $bot->name.' — SMC Flow')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · SMC Flow</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded border border-rose-800/60 bg-rose-950/40 px-4 py-3 text-sm text-rose-200">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a] shadow-2xl shadow-black/50">
        <div class="flex items-center justify-between border-b border-zinc-700 bg-[#1e2028] px-4 py-3">
            <h2 class="text-base font-semibold tracking-tight text-white">SMC Flow</h2>
            <span class="text-[10px] uppercase tracking-wide text-zinc-500">SMC Flow · этап 6</span>
        </div>

        <form method="post" action="{{ route('bots.indicators.smc-flow.update', $bot) }}" id="smc-form">
            @csrf
            @method('PUT')

            <div class="max-h-[min(68vh,520px)] overflow-y-auto px-4 py-4 space-y-4 text-sm text-zinc-200">
                <p class="text-xs text-zinc-500">
                    LuxAlgo FVG и Order Blocks. Auto Fibs, сессии, Hull band — в соседних блоках настроек.
                </p>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="ut_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('ut_enabled', $smc['ut_enabled'] ? '1' : '') === '1' || (old('ut_enabled') === null && $smc['ut_enabled']))>
                    <span>Включить UT Bot на графике</span>
                </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('ut_enabled')])

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-ut-key" class="block text-xs font-medium text-zinc-400">UT Key Value</label>
                        <input type="number" name="ut_key_value" id="smc-ut-key" required min="0.1" max="100" step="0.1"
                            value="{{ old('ut_key_value', $smc['ut_key_value']) }}"
                            class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('ut_key_value')])
                    </div>
                    <div>
                        <label for="smc-ut-atr" class="block text-xs font-medium text-zinc-400">UT ATR Period</label>
                        <input type="number" name="ut_atr_period" id="smc-ut-atr" required min="1" max="500"
                            value="{{ old('ut_atr_period', $smc['ut_atr_period']) }}"
                            class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('ut_atr_period')])
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="hull_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('hull_enabled', $smc['hull_enabled'] ? '1' : '') === '1' || (old('hull_enabled') === null && $smc['hull_enabled']))>
                        <span>Включить Hull Suite</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_enabled')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-hull-mode" class="block text-xs font-medium text-zinc-400">Hull Variation</label>
                            <select name="hull_mode" id="smc-hull-mode" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="Hma" @selected(old('hull_mode', $smc['hull_mode']) === 'Hma')>Hma</option>
                                <option value="Thma" @selected(old('hull_mode', $smc['hull_mode']) === 'Thma')>Thma</option>
                                <option value="Ehma" @selected(old('hull_mode', $smc['hull_mode']) === 'Ehma')>Ehma</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_mode')])
                        </div>
                        <div>
                            <label for="smc-hull-len" class="block text-xs font-medium text-zinc-400">Hull Length</label>
                            <input type="number" name="hull_length" id="smc-hull-len" required min="2" max="500"
                                value="{{ old('hull_length', $smc['hull_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_length')])
                        </div>
                        <div>
                            <label for="smc-hull-mult" class="block text-xs font-medium text-zinc-400">Length multiplier</label>
                            <input type="number" name="hull_length_multiplier" id="smc-hull-mult" required min="0.1" max="5" step="0.1"
                                value="{{ old('hull_length_multiplier', $smc['hull_length_multiplier']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_length_multiplier')])
                        </div>
                        <div>
                            <label for="smc-hull-width" class="block text-xs font-medium text-zinc-400">Main line width</label>
                            <input type="number" name="hull_line_width" id="smc-hull-width" required min="1" max="6"
                                value="{{ old('hull_line_width', $smc['hull_line_width']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_line_width')])
                        </div>
                        <div>
                            <label for="smc-hull-sig-width" class="block text-xs font-medium text-zinc-400">Signal line width</label>
                            <input type="number" name="hull_signal_line_width" id="smc-hull-sig-width" required min="1" max="6"
                                value="{{ old('hull_signal_line_width', $smc['hull_signal_line_width']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_signal_line_width')])
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="hull_switch_color" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hull_switch_color', $smc['hull_switch_color'] ? '1' : '') === '1' || (old('hull_switch_color') === null && $smc['hull_switch_color']))>
                            <span>Цвет по тренду</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_switch_color')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="hull_show_signal_line" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hull_show_signal_line', $smc['hull_show_signal_line'] ? '1' : '') === '1' || (old('hull_show_signal_line') === null && $smc['hull_show_signal_line']))>
                            <span>Показывать SHULL (сигнальную линию)</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_show_signal_line')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="hull_show_band" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hull_show_band', ($smc['hull_show_band'] ?? true) ? '1' : '') === '1' || (old('hull_show_band') === null && ($smc['hull_show_band'] ?? true)))>
                            <span>Заливка band между MHULL и SHULL</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_show_band')])
                    </div>
                    <div>
                        <label for="smc-hull-band-trans" class="block text-xs font-medium text-zinc-400">Band transparency (0=opaque)</label>
                        <input type="number" name="hull_band_transparency" id="smc-hull-band-trans" required min="0" max="100"
                            value="{{ old('hull_band_transparency', $smc['hull_band_transparency'] ?? 40) }}"
                            class="mt-1 w-full max-w-[8rem] rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('hull_band_transparency')])
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="three_br_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('three_br_enabled', $smc['three_br_enabled'] ? '1' : '') === '1' || (old('three_br_enabled') === null && $smc['three_br_enabled']))>
                        <span>Включить Three Bar Reversal</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_enabled')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-3br-type" class="block text-xs font-medium text-zinc-400">Pattern type</label>
                            <select name="three_br_type" id="smc-3br-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="All" @selected(old('three_br_type', $smc['three_br_type']) === 'All')>All</option>
                                <option value="Normal" @selected(old('three_br_type', $smc['three_br_type']) === 'Normal')>Normal</option>
                                <option value="Enhanced" @selected(old('three_br_type', $smc['three_br_type']) === 'Enhanced')>Enhanced</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_type')])
                        </div>
                        <div>
                            <label for="smc-3br-max" class="block text-xs font-medium text-zinc-400">Max patterns</label>
                            <input type="number" name="three_br_max_patterns" id="smc-3br-max" required min="10" max="500"
                                value="{{ old('three_br_max_patterns', $smc['three_br_max_patterns']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_max_patterns')])
                        </div>
                        <div>
                            <label for="smc-3br-trend-type" class="block text-xs font-medium text-zinc-400">Trend type</label>
                            <select name="three_br_trend_type" id="smc-3br-trend-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="None" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'None')>None</option>
                                <option value="Moving Average Cloud" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'Moving Average Cloud')>Moving Average Cloud</option>
                                <option value="Supertrend" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'Supertrend')>Supertrend</option>
                                <option value="Donchian Channels" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'Donchian Channels')>Donchian Channels</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_trend_type')])
                        </div>
                        <div>
                            <label for="smc-3br-sr" class="block text-xs font-medium text-zinc-400">Support / Resistance</label>
                            <select name="three_br_sr_mode" id="smc-3br-sr" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="Level" @selected(old('three_br_sr_mode', $smc['three_br_sr_mode'] ?? 'Level') === 'Level')>Level</option>
                                <option value="Zone" @selected(old('three_br_sr_mode', $smc['three_br_sr_mode'] ?? 'Level') === 'Zone')>Zone</option>
                                <option value="None" @selected(old('three_br_sr_mode', $smc['three_br_sr_mode'] ?? 'Level') === 'None')>None</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_sr_mode')])
                        </div>
                        <div>
                            <label for="smc-3br-st-atr" class="block text-xs font-medium text-zinc-400">ST ATR length</label>
                            <input type="number" name="three_br_st_atr_period" id="smc-3br-st-atr" required min="1" max="100"
                                value="{{ old('three_br_st_atr_period', $smc['three_br_st_atr_period'] ?? 10) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_st_atr_period')])
                        </div>
                        <div>
                            <label for="smc-3br-st-factor" class="block text-xs font-medium text-zinc-400">ST factor</label>
                            <input type="number" name="three_br_st_factor" id="smc-3br-st-factor" required min="0.1" max="20" step="0.1"
                                value="{{ old('three_br_st_factor', $smc['three_br_st_factor'] ?? 3) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_st_factor')])
                        </div>
                        <div>
                            <label for="smc-3br-dc-len" class="block text-xs font-medium text-zinc-400">Donchian length</label>
                            <input type="number" name="three_br_dc_length" id="smc-3br-dc-len" required min="1" max="200"
                                value="{{ old('three_br_dc_length', $smc['three_br_dc_length'] ?? 13) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_dc_length')])
                        </div>
                        <div>
                            <label for="smc-3br-trend-filter" class="block text-xs font-medium text-zinc-400">Trend filter</label>
                            <select name="three_br_trend_filter" id="smc-3br-trend-filter" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="Aligned" @selected(old('three_br_trend_filter', $smc['three_br_trend_filter']) === 'Aligned')>Aligned</option>
                                <option value="Opposite" @selected(old('three_br_trend_filter', $smc['three_br_trend_filter']) === 'Opposite')>Opposite</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_trend_filter')])
                        </div>
                        <div>
                            <label for="smc-3br-ma-type" class="block text-xs font-medium text-zinc-400">MA type</label>
                            <select name="three_br_ma_type" id="smc-3br-ma-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
@foreach (['SMA', 'EMA', 'HMA', 'RMA', 'WMA', 'VWMA'] as $maType)
                                    <option value="{{ $maType }}" @selected(old('three_br_ma_type', $smc['three_br_ma_type']) === $maType)>{{ $maType }}</option>
                                @endforeach
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_ma_type')])
                        </div>
                        <div>
                            <label for="smc-3br-ma-fast" class="block text-xs font-medium text-zinc-400">MA fast length</label>
                            <input type="number" name="three_br_ma_fast_length" id="smc-3br-ma-fast" required min="2" max="500"
                                value="{{ old('three_br_ma_fast_length', $smc['three_br_ma_fast_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_ma_fast_length')])
                        </div>
                        <div>
                            <label for="smc-3br-ma-slow" class="block text-xs font-medium text-zinc-400">MA slow length</label>
                            <input type="number" name="three_br_ma_slow_length" id="smc-3br-ma-slow" required min="3" max="1000"
                                value="{{ old('three_br_ma_slow_length', $smc['three_br_ma_slow_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_ma_slow_length')])
                        </div>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="three_br_show_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('three_br_show_levels', $smc['three_br_show_levels'] ? '1' : '') === '1' || (old('three_br_show_levels') === null && $smc['three_br_show_levels']))>
                        <span>Показывать уровни паттерна</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('three_br_show_levels')])
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <h3 class="text-sm font-medium text-zinc-300">Reversal Signals</h3>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="rs_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('rs_enabled', ($smc['rs_enabled'] ?? true) ? '1' : '') === '1' || (old('rs_enabled') === null && ($smc['rs_enabled'] ?? true)))>
                        <span>Показывать Reversal Signals (momentum 9)</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('rs_enabled')])
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="rs_sr_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('rs_sr_levels', ($smc['rs_sr_levels'] ?? true) ? '1' : '') === '1' || (old('rs_sr_levels') === null && ($smc['rs_sr_levels'] ?? true)))>
                        <span>Support &amp; Resistance levels</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('rs_sr_levels')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs text-zinc-500">Bull momentum</label>
                            <input type="color" name="color_rs_bull" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_rs_bull', $smc['color_rs_bull'] ?? '#089981')) ? old('color_rs_bull', $smc['color_rs_bull'] ?? '#089981') : '#089981' }}"
                                class="mt-1 h-10 w-full cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_rs_bull')])
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500">Bear momentum</label>
                            <input type="color" name="color_rs_bear" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_rs_bear', $smc['color_rs_bear'] ?? '#f23645')) ? old('color_rs_bear', $smc['color_rs_bear'] ?? '#f23645') : '#f23645' }}"
                                class="mt-1 h-10 w-full cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_rs_bear')])
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="mxwll_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('mxwll_enabled', $smc['mxwll_enabled'] ? '1' : '') === '1' || (old('mxwll_enabled') === null && $smc['mxwll_enabled']))>
                        <span>Включить Mxwll structure (BoS / CHoCH)</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_enabled')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="mxwll_show_externals" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('mxwll_show_externals', $smc['mxwll_show_externals'] ? '1' : '') === '1' || (old('mxwll_show_externals') === null && $smc['mxwll_show_externals']))>
                                <span>External structure</span>
                            </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_externals')])
                        </div>
                        <div>
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="mxwll_show_internals" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('mxwll_show_internals', $smc['mxwll_show_internals'] ? '1' : '') === '1' || (old('mxwll_show_internals') === null && $smc['mxwll_show_internals']))>
                                <span>Internal structure (I-BoS / I-CHoCH)</span>
                            </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_internals')])
                        </div>
                        <div>
                            <label for="smc-mxwll-ext-sens" class="block text-xs font-medium text-zinc-400">External sensitivity</label>
                            <select name="mxwll_ext_sensitivity" id="smc-mxwll-ext-sens" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
@foreach ([10, 25, 50] as $sens)
                                    <option value="{{ $sens }}" @selected((int) old('mxwll_ext_sensitivity', $smc['mxwll_ext_sensitivity']) === $sens)>{{ $sens }}</option>
                                @endforeach
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_ext_sensitivity')])
                        </div>
                        <div>
                            <label for="smc-mxwll-int-sens" class="block text-xs font-medium text-zinc-400">Internal sensitivity</label>
                            <select name="mxwll_int_sensitivity" id="smc-mxwll-int-sens" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
@foreach ([3, 5, 8] as $sens)
                                    <option value="{{ $sens }}" @selected((int) old('mxwll_int_sensitivity', $smc['mxwll_int_sensitivity']) === $sens)>{{ $sens }}</option>
                                @endforeach
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_int_sensitivity')])
                        </div>
                        <div>
                            <label for="smc-mxwll-ext-str" class="block text-xs font-medium text-zinc-400">External filter</label>
                            <select name="mxwll_ext_structure" id="smc-mxwll-ext-str" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
@foreach (['All', 'BoS', 'CHoCH'] as $opt)
                                    <option value="{{ $opt }}" @selected(old('mxwll_ext_structure', $smc['mxwll_ext_structure']) === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_ext_structure')])
                        </div>
                        <div>
                            <label for="smc-mxwll-int-str" class="block text-xs font-medium text-zinc-400">Internal filter</label>
                            <select name="mxwll_int_structure" id="smc-mxwll-int-str" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
@foreach (['All', 'BoS', 'CHoCH'] as $opt)
                                    <option value="{{ $opt }}" @selected(old('mxwll_int_structure', $smc['mxwll_int_structure']) === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_int_structure')])
                        </div>
                        <div>
                            <label for="smc-mxwll-max" class="block text-xs font-medium text-zinc-400">Max events</label>
                            <input type="number" name="mxwll_max_events" id="smc-mxwll-max" required min="20" max="500"
                                value="{{ old('mxwll_max_events', $smc['mxwll_max_events']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_max_events')])
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_hh_lh" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_hh_lh', $smc['mxwll_show_hh_lh'] ? '1' : '') === '1' || (old('mxwll_show_hh_lh') === null && $smc['mxwll_show_hh_lh']))>
                            <span>Метки HH / LH</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_hh_lh')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_hl_ll" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_hl_ll', $smc['mxwll_show_hl_ll'] ? '1' : '') === '1' || (old('mxwll_show_hl_ll') === null && $smc['mxwll_show_hl_ll']))>
                            <span>Метки HL / LL</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_hl_ll')])
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <h3 class="text-sm font-medium text-zinc-300">Mxwll — уровни 4H / 1D и Auto Fibs</h3>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_4h_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_4h_levels', ($smc['mxwll_show_4h_levels'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_4h_levels') === null && ($smc['mxwll_show_4h_levels'] ?? true)))>
                            <span>4H High/Low (240 баров)</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_4h_levels')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_4h_labels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_4h_labels', ($smc['mxwll_show_4h_labels'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_4h_labels') === null && ($smc['mxwll_show_4h_labels'] ?? true)))>
                            <span>Метки 240H/L</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_4h_labels')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_1d_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_1d_levels', ($smc['mxwll_show_1d_levels'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_1d_levels') === null && ($smc['mxwll_show_1d_levels'] ?? true)))>
                            <span>1D High/Low (1440 баров)</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_1d_levels')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_1d_labels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_1d_labels', ($smc['mxwll_show_1d_labels'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_1d_labels') === null && ($smc['mxwll_show_1d_labels'] ?? true)))>
                            <span>Метки 1DH/L</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_1d_labels')])
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_fibs" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_fibs', ($smc['mxwll_show_fibs'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_fibs') === null && ($smc['mxwll_show_fibs'] ?? true)))>
                            <span>Auto Fibs (горизонтали)</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_fibs')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="mxwll_show_main_leg" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mxwll_show_main_leg', ($smc['mxwll_show_main_leg'] ?? true) ? '1' : '') === '1' || (old('mxwll_show_main_leg') === null && ($smc['mxwll_show_main_leg'] ?? true)))>
                            <span>Main zigzag leg</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_show_main_leg')])
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-mxwll-level-col" class="block text-xs font-medium text-zinc-400">Цвет уровней 4H/1D</label>
                            <input type="color" name="color_mxwll_level" id="smc-mxwll-level-col"
                                value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_mxwll_level', $smc['color_mxwll_level'] ?? '#00ffff')) ? old('color_mxwll_level', $smc['color_mxwll_level'] ?? '#00ffff') : '#00ffff' }}"
                                class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_mxwll_level')])
                        </div>
                        <div>
                            <label for="smc-mxwll-fib-ext" class="block text-xs font-medium text-zinc-400">Fib extend (баров вправо)</label>
                            <input type="number" name="mxwll_fib_extend_bars" id="smc-mxwll-fib-ext" required min="0" max="50"
                                value="{{ old('mxwll_fib_extend_bars', $smc['mxwll_fib_extend_bars'] ?? 5) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('mxwll_fib_extend_bars')])
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <h3 class="text-sm font-medium text-zinc-300">Sessions (America/New_York)</h3>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="sessions_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('sessions_enabled', ($smc['sessions_enabled'] ?? true) ? '1' : '') === '1' || (old('sessions_enabled') === null && ($smc['sessions_enabled'] ?? true)))>
                        <span>Фон сессий NY / Asia / London</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('sessions_enabled')])
                    <div>
                        <label for="smc-sessions-trans" class="block text-xs font-medium text-zinc-400">Transparency (98 ≈ как Pine)</label>
                        <input type="number" name="sessions_transparency" id="smc-sessions-trans" required min="0" max="100"
                            value="{{ old('sessions_transparency', $smc['sessions_transparency'] ?? 98) }}"
                            class="mt-1 w-full max-w-[8rem] rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('sessions_transparency')])
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs text-zinc-500">NY</label>
                            <input type="color" name="color_session_ny" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_session_ny', $smc['color_session_ny'] ?? '#f24968')) ? old('color_session_ny', $smc['color_session_ny'] ?? '#f24968') : '#f24968' }}"
                                class="mt-1 h-10 w-full cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_session_ny')])
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500">Asia</label>
                            <input type="color" name="color_session_asia" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_session_asia', $smc['color_session_asia'] ?? '#14D990')) ? old('color_session_asia', $smc['color_session_asia'] ?? '#14D990') : '#14D990' }}"
                                class="mt-1 h-10 w-full cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_session_asia')])
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500">London</label>
                            <input type="color" name="color_session_london" value="{{ preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_session_london', $smc['color_session_london'] ?? '#F2B807')) ? old('color_session_london', $smc['color_session_london'] ?? '#F2B807') : '#F2B807' }}"
                                class="mt-1 h-10 w-full cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_session_london')])
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <h3 class="text-sm font-medium text-zinc-300">LuxAlgo FVG</h3>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="lux_fvg_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('lux_fvg_enabled', ($smc['lux_fvg_enabled'] ?? true) ? '1' : '') === '1' || (old('lux_fvg_enabled') === null && ($smc['lux_fvg_enabled'] ?? true)))>
                        <span>Показывать FVG</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_enabled')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-fvg-threshold" class="block text-xs font-medium text-zinc-400">Threshold %</label>
                            <input type="number" name="lux_fvg_threshold_pct" id="smc-fvg-threshold" required min="0" max="100" step="0.1"
                                value="{{ old('lux_fvg_threshold_pct', $smc['lux_fvg_threshold_pct'] ?? 0) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_threshold_pct')])
                        </div>
                        <div>
                            <label for="smc-fvg-extend" class="block text-xs font-medium text-zinc-400">Extend (баров)</label>
                            <input type="number" name="lux_fvg_extend_bars" id="smc-fvg-extend" required min="0" max="200"
                                value="{{ old('lux_fvg_extend_bars', $smc['lux_fvg_extend_bars'] ?? 20) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_extend_bars')])
                        </div>
                        <div>
                            <label for="smc-fvg-last" class="block text-xs font-medium text-zinc-400">Unmitigated levels</label>
                            <input type="number" name="lux_fvg_show_last" id="smc-fvg-last" required min="0" max="50"
                                value="{{ old('lux_fvg_show_last', $smc['lux_fvg_show_last'] ?? 0) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_show_last')])
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="lux_fvg_threshold_auto" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('lux_fvg_threshold_auto', ($smc['lux_fvg_threshold_auto'] ?? false) ? '1' : '') === '1')>
                            <span>Auto threshold</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_threshold_auto')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="lux_fvg_dynamic" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('lux_fvg_dynamic', ($smc['lux_fvg_dynamic'] ?? false) ? '1' : '') === '1')>
                            <span>Dynamic FVG</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_dynamic')])
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="lux_fvg_mitigation_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('lux_fvg_mitigation_levels', ($smc['lux_fvg_mitigation_levels'] ?? false) ? '1' : '') === '1')>
                            <span>Mitigation levels</span>
                        </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_fvg_mitigation_levels')])
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <h3 class="text-sm font-medium text-zinc-300">LuxAlgo Order Blocks</h3>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="lux_ob_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('lux_ob_enabled', ($smc['lux_ob_enabled'] ?? true) ? '1' : '') === '1' || (old('lux_ob_enabled') === null && ($smc['lux_ob_enabled'] ?? true)))>
                        <span>Показывать Order Blocks</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_enabled')])
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-ob-len" class="block text-xs font-medium text-zinc-400">Volume pivot length</label>
                            <input type="number" name="lux_ob_length" id="smc-ob-len" required min="1" max="50"
                                value="{{ old('lux_ob_length', $smc['lux_ob_length'] ?? 5) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_length')])
                        </div>
                        <div>
                            <label for="smc-ob-mit" class="block text-xs font-medium text-zinc-400">Mitigation</label>
                            <select name="lux_ob_mitigation" id="smc-ob-mit" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="Wick" @selected(old('lux_ob_mitigation', $smc['lux_ob_mitigation'] ?? 'Wick') === 'Wick')>Wick</option>
                                <option value="Close" @selected(old('lux_ob_mitigation', $smc['lux_ob_mitigation'] ?? 'Wick') === 'Close')>Close</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_mitigation')])
                        </div>
                        <div>
                            <label for="smc-ob-bull" class="block text-xs font-medium text-zinc-400">Bullish OB count</label>
                            <input type="number" name="lux_ob_bull_count" id="smc-ob-bull" required min="1" max="20"
                                value="{{ old('lux_ob_bull_count', $smc['lux_ob_bull_count'] ?? 3) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_bull_count')])
                        </div>
                        <div>
                            <label for="smc-ob-bear" class="block text-xs font-medium text-zinc-400">Bearish OB count</label>
                            <input type="number" name="lux_ob_bear_count" id="smc-ob-bear" required min="1" max="20"
                                value="{{ old('lux_ob_bear_count', $smc['lux_ob_bear_count'] ?? 3) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_bear_count')])
                        </div>
                        <div>
                            <label for="smc-ob-style" class="block text-xs font-medium text-zinc-400">Avg line style</label>
                            <select name="lux_ob_avg_line_style" id="smc-ob-style" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="solid" @selected(old('lux_ob_avg_line_style', $smc['lux_ob_avg_line_style'] ?? 'dotted') === 'solid')>Solid</option>
                                <option value="dashed" @selected(old('lux_ob_avg_line_style', $smc['lux_ob_avg_line_style'] ?? 'dotted') === 'dashed')>Dashed</option>
                                <option value="dotted" @selected(old('lux_ob_avg_line_style', $smc['lux_ob_avg_line_style'] ?? 'dotted') === 'dotted')>Dotted</option>
                            </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_avg_line_style')])
                        </div>
                        <div>
                            <label for="smc-ob-lw" class="block text-xs font-medium text-zinc-400">Avg line width</label>
                            <input type="number" name="lux_ob_avg_line_width" id="smc-ob-lw" required min="1" max="6"
                                value="{{ old('lux_ob_avg_line_width', $smc['lux_ob_avg_line_width'] ?? 1) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('lux_ob_avg_line_width')])
                        </div>
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2 text-zinc-500">
                    <input type="checkbox" name="ut_use_heikin_ashi" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500" disabled
                        @checked(old('ut_use_heikin_ashi', $smc['ut_use_heikin_ashi'] ? '1' : '') === '1')>
                    <span>UT Signals from Heikin Ashi (пока не считается на сервере)</span>
                </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('ut_use_heikin_ashi')])

                <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="show_ut_labels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('show_ut_labels', $smc['show_ut_labels'] ? '1' : '') === '1' || (old('show_ut_labels') === null && $smc['show_ut_labels']))>
                        <span>Метки Buy / Sell</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_ut_labels')])
                    <label class="flex cursor-pointer items-center gap-2 pl-6">
                        <input type="checkbox" name="keep_recent_only" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('keep_recent_only', $smc['keep_recent_only'] ? '1' : '') === '1' || (old('keep_recent_only') === null && $smc['keep_recent_only']))>
                        <span>Только последние сигналы</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('keep_recent_only')])
                    <div class="pl-6">
                        <label for="smc-max-labels" class="block text-xs text-zinc-500">Макс. меток на сторону</label>
                        <input type="number" name="max_ut_labels" id="smc-max-labels" required min="1" max="10"
                            value="{{ old('max_ut_labels', $smc['max_ut_labels']) }}"
                            class="mt-1 w-full max-w-[8rem] rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('max_ut_labels')])
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="show_ut_trailing_stop" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('show_ut_trailing_stop', $smc['show_ut_trailing_stop'] ? '1' : '') === '1' || (old('show_ut_trailing_stop') === null && $smc['show_ut_trailing_stop']))>
                    <span>Линия ATR-трейлинга</span>
                </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_ut_trailing_stop')])

                @php
                    $buyHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_ut_buy', $smc['color_ut_buy'])) ? old('color_ut_buy', $smc['color_ut_buy']) : '#22c55e';
                    $sellHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_ut_sell', $smc['color_ut_sell'])) ? old('color_ut_sell', $smc['color_ut_sell']) : '#ef4444';
                    $trailBullHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_ut_trail_bull', $smc['color_ut_trail_bull'])) ? old('color_ut_trail_bull', $smc['color_ut_trail_bull']) : '#22c55e';
                    $trailBearHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_ut_trail_bear', $smc['color_ut_trail_bear'])) ? old('color_ut_trail_bear', $smc['color_ut_trail_bear']) : '#ef4444';
                    $hullUpHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_hull_up', $smc['color_hull_up'])) ? old('color_hull_up', $smc['color_hull_up']) : '#00ff00';
                    $hullDownHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_hull_down', $smc['color_hull_down'])) ? old('color_hull_down', $smc['color_hull_down']) : '#ff0000';
                    $hullNeutralHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_hull_neutral', $smc['color_hull_neutral'])) ? old('color_hull_neutral', $smc['color_hull_neutral']) : '#ff9800';
                    $threeBrBullHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_three_br_bull', $smc['color_three_br_bull'])) ? old('color_three_br_bull', $smc['color_three_br_bull']) : '#2962ff';
                    $threeBrBearHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_three_br_bear', $smc['color_three_br_bear'])) ? old('color_three_br_bear', $smc['color_three_br_bear']) : '#ff9800';
                    $mxwllBullHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_mxwll_bull', $smc['color_mxwll_bull'])) ? old('color_mxwll_bull', $smc['color_mxwll_bull']) : '#14D990';
                    $mxwllBearHex = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) old('color_mxwll_bear', $smc['color_mxwll_bear'])) ? old('color_mxwll_bear', $smc['color_mxwll_bear']) : '#F24968';
                @endphp
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-buy" class="block text-xs text-zinc-500">Buy</label>
                        <input type="color" name="color_ut_buy" id="smc-buy" value="{{ $buyHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_ut_buy')])
                    </div>
                    <div>
                        <label for="smc-sell" class="block text-xs text-zinc-500">Sell</label>
                        <input type="color" name="color_ut_sell" id="smc-sell" value="{{ $sellHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_ut_sell')])
                    </div>
                    <div>
                        <label for="smc-trail-bull" class="block text-xs text-zinc-500">Трейл (бычий)</label>
                        <input type="color" name="color_ut_trail_bull" id="smc-trail-bull" value="{{ $trailBullHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_ut_trail_bull')])
                    </div>
                    <div>
                        <label for="smc-trail-bear" class="block text-xs text-zinc-500">Трейл (медвежий)</label>
                        <input type="color" name="color_ut_trail_bear" id="smc-trail-bear" value="{{ $trailBearHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_ut_trail_bear')])
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="smc-hull-up" class="block text-xs text-zinc-500">Hull Up</label>
                        <input type="color" name="color_hull_up" id="smc-hull-up" value="{{ $hullUpHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_hull_up')])
                    </div>
                    <div>
                        <label for="smc-hull-down" class="block text-xs text-zinc-500">Hull Down</label>
                        <input type="color" name="color_hull_down" id="smc-hull-down" value="{{ $hullDownHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_hull_down')])
                    </div>
                    <div>
                        <label for="smc-hull-neutral" class="block text-xs text-zinc-500">Hull Neutral</label>
                        <input type="color" name="color_hull_neutral" id="smc-hull-neutral" value="{{ $hullNeutralHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_hull_neutral')])
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-3br-bull" class="block text-xs text-zinc-500">3BR Bull</label>
                        <input type="color" name="color_three_br_bull" id="smc-3br-bull" value="{{ $threeBrBullHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_three_br_bull')])
                    </div>
                    <div>
                        <label for="smc-3br-bear" class="block text-xs text-zinc-500">3BR Bear</label>
                        <input type="color" name="color_three_br_bear" id="smc-3br-bear" value="{{ $threeBrBearHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_three_br_bear')])
                    </div>
                    <div>
                        <label for="smc-mxwll-bull" class="block text-xs text-zinc-500">Mxwll Bull</label>
                        <input type="color" name="color_mxwll_bull" id="smc-mxwll-bull" value="{{ $mxwllBullHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_mxwll_bull')])
                    </div>
                    <div>
                        <label for="smc-mxwll-bear" class="block text-xs text-zinc-500">Mxwll Bear</label>
                        <input type="color" name="color_mxwll_bear" id="smc-mxwll-bear" value="{{ $mxwllBearHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_mxwll_bear')])
                    </div>
                </div>

                <div>
                    <label for="smc-label-size" class="block text-xs font-medium text-zinc-400">Размер меток</label>
                    <select name="label_size" id="smc-label-size" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
<option value="tiny" @selected(old('label_size', $smc['label_size']) === 'tiny')>Tiny</option>
                        <option value="small" @selected(old('label_size', $smc['label_size']) === 'small')>Small</option>
                        <option value="normal" @selected(old('label_size', $smc['label_size']) === 'normal')>Normal</option>
                    </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('label_size')])
                </div>
            </div>

            <div class="border-t border-zinc-700 bg-[#1a1c22] px-4 py-3">
                <button type="submit"
                    class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/50">
                    Сохранить
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
