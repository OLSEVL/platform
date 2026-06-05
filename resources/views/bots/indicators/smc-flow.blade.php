@extends('layouts.app')
@section('title', $bot->name.' — SMC Flow')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · SMC Flow (порт 3.txt)</p>
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
            <span class="text-[10px] uppercase tracking-wide text-zinc-500">UT Bot + Hull + 3BR · этап 3</span>
        </div>

        <form method="post" action="{{ route('bots.indicators.smc-flow.update', $bot) }}" id="smc-form">
            @csrf
            @method('PUT')

            <div class="max-h-[min(68vh,520px)] overflow-y-auto px-4 py-4 space-y-4 text-sm text-zinc-200">
                <p class="text-xs text-zinc-500">
                    Перенесены модули UT Bot, Hull Suite и Three Bar Reversal. Структура Mxwll, FVG LuxAlgo и остальные блоки Pine подключатся следующими этапами.
                </p>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="ut_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('ut_enabled', $smc['ut_enabled'] ? '1' : '') === '1' || (old('ut_enabled') === null && $smc['ut_enabled']))>
                    <span>Включить UT Bot на графике</span>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-ut-key" class="block text-xs font-medium text-zinc-400">UT Key Value</label>
                        <input type="number" name="ut_key_value" id="smc-ut-key" required min="0.1" max="100" step="0.1"
                            value="{{ old('ut_key_value', $smc['ut_key_value']) }}"
                            class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    </div>
                    <div>
                        <label for="smc-ut-atr" class="block text-xs font-medium text-zinc-400">UT ATR Period</label>
                        <input type="number" name="ut_atr_period" id="smc-ut-atr" required min="1" max="500"
                            value="{{ old('ut_atr_period', $smc['ut_atr_period']) }}"
                            class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="hull_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('hull_enabled', $smc['hull_enabled'] ? '1' : '') === '1' || (old('hull_enabled') === null && $smc['hull_enabled']))>
                        <span>Включить Hull Suite</span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-hull-mode" class="block text-xs font-medium text-zinc-400">Hull Variation</label>
                            <select name="hull_mode" id="smc-hull-mode" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                <option value="Hma" @selected(old('hull_mode', $smc['hull_mode']) === 'Hma')>Hma</option>
                                <option value="Thma" @selected(old('hull_mode', $smc['hull_mode']) === 'Thma')>Thma</option>
                                <option value="Ehma" @selected(old('hull_mode', $smc['hull_mode']) === 'Ehma')>Ehma</option>
                            </select>
                        </div>
                        <div>
                            <label for="smc-hull-len" class="block text-xs font-medium text-zinc-400">Hull Length</label>
                            <input type="number" name="hull_length" id="smc-hull-len" required min="2" max="500"
                                value="{{ old('hull_length', $smc['hull_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                        <div>
                            <label for="smc-hull-mult" class="block text-xs font-medium text-zinc-400">Length multiplier</label>
                            <input type="number" name="hull_length_multiplier" id="smc-hull-mult" required min="0.1" max="5" step="0.1"
                                value="{{ old('hull_length_multiplier', $smc['hull_length_multiplier']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                        <div>
                            <label for="smc-hull-width" class="block text-xs font-medium text-zinc-400">Main line width</label>
                            <input type="number" name="hull_line_width" id="smc-hull-width" required min="1" max="6"
                                value="{{ old('hull_line_width', $smc['hull_line_width']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                        <div>
                            <label for="smc-hull-sig-width" class="block text-xs font-medium text-zinc-400">Signal line width</label>
                            <input type="number" name="hull_signal_line_width" id="smc-hull-sig-width" required min="1" max="6"
                                value="{{ old('hull_signal_line_width', $smc['hull_signal_line_width']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="hull_switch_color" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hull_switch_color', $smc['hull_switch_color'] ? '1' : '') === '1' || (old('hull_switch_color') === null && $smc['hull_switch_color']))>
                            <span>Цвет по тренду</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="hull_show_signal_line" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hull_show_signal_line', $smc['hull_show_signal_line'] ? '1' : '') === '1' || (old('hull_show_signal_line') === null && $smc['hull_show_signal_line']))>
                            <span>Показывать SHULL (сигнальную линию)</span>
                        </label>
                    </div>
                </div>

                <div class="border-t border-zinc-700/70 pt-4 space-y-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="three_br_enabled" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('three_br_enabled', $smc['three_br_enabled'] ? '1' : '') === '1' || (old('three_br_enabled') === null && $smc['three_br_enabled']))>
                        <span>Включить Three Bar Reversal</span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="smc-3br-type" class="block text-xs font-medium text-zinc-400">Pattern type</label>
                            <select name="three_br_type" id="smc-3br-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                <option value="All" @selected(old('three_br_type', $smc['three_br_type']) === 'All')>All</option>
                                <option value="Normal" @selected(old('three_br_type', $smc['three_br_type']) === 'Normal')>Normal</option>
                                <option value="Enhanced" @selected(old('three_br_type', $smc['three_br_type']) === 'Enhanced')>Enhanced</option>
                            </select>
                        </div>
                        <div>
                            <label for="smc-3br-max" class="block text-xs font-medium text-zinc-400">Max patterns</label>
                            <input type="number" name="three_br_max_patterns" id="smc-3br-max" required min="10" max="500"
                                value="{{ old('three_br_max_patterns', $smc['three_br_max_patterns']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                        <div>
                            <label for="smc-3br-trend-type" class="block text-xs font-medium text-zinc-400">Trend type</label>
                            <select name="three_br_trend_type" id="smc-3br-trend-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                <option value="None" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'None')>None</option>
                                <option value="Moving Average Cloud" @selected(old('three_br_trend_type', $smc['three_br_trend_type']) === 'Moving Average Cloud')>Moving Average Cloud</option>
                            </select>
                        </div>
                        <div>
                            <label for="smc-3br-trend-filter" class="block text-xs font-medium text-zinc-400">Trend filter</label>
                            <select name="three_br_trend_filter" id="smc-3br-trend-filter" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                <option value="Aligned" @selected(old('three_br_trend_filter', $smc['three_br_trend_filter']) === 'Aligned')>Aligned</option>
                                <option value="Opposite" @selected(old('three_br_trend_filter', $smc['three_br_trend_filter']) === 'Opposite')>Opposite</option>
                            </select>
                        </div>
                        <div>
                            <label for="smc-3br-ma-type" class="block text-xs font-medium text-zinc-400">MA type</label>
                            <select name="three_br_ma_type" id="smc-3br-ma-type" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                @foreach (['SMA', 'EMA', 'HMA', 'RMA', 'WMA', 'VWMA'] as $maType)
                                    <option value="{{ $maType }}" @selected(old('three_br_ma_type', $smc['three_br_ma_type']) === $maType)>{{ $maType }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="smc-3br-ma-fast" class="block text-xs font-medium text-zinc-400">MA fast length</label>
                            <input type="number" name="three_br_ma_fast_length" id="smc-3br-ma-fast" required min="2" max="500"
                                value="{{ old('three_br_ma_fast_length', $smc['three_br_ma_fast_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                        <div>
                            <label for="smc-3br-ma-slow" class="block text-xs font-medium text-zinc-400">MA slow length</label>
                            <input type="number" name="three_br_ma_slow_length" id="smc-3br-ma-slow" required min="3" max="1000"
                                value="{{ old('three_br_ma_slow_length', $smc['three_br_ma_slow_length']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="three_br_show_levels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('three_br_show_levels', $smc['three_br_show_levels'] ? '1' : '') === '1' || (old('three_br_show_levels') === null && $smc['three_br_show_levels']))>
                        <span>Показывать уровни паттерна</span>
                    </label>
                </div>

                <label class="flex cursor-pointer items-center gap-2 text-zinc-500">
                    <input type="checkbox" name="ut_use_heikin_ashi" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500" disabled
                        @checked(old('ut_use_heikin_ashi', $smc['ut_use_heikin_ashi'] ? '1' : '') === '1')>
                    <span>UT Signals from Heikin Ashi (пока не считается на сервере)</span>
                </label>

                <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="show_ut_labels" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('show_ut_labels', $smc['show_ut_labels'] ? '1' : '') === '1' || (old('show_ut_labels') === null && $smc['show_ut_labels']))>
                        <span>Метки Buy / Sell</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 pl-6">
                        <input type="checkbox" name="keep_recent_only" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('keep_recent_only', $smc['keep_recent_only'] ? '1' : '') === '1' || (old('keep_recent_only') === null && $smc['keep_recent_only']))>
                        <span>Только последние сигналы</span>
                    </label>
                    <div class="pl-6">
                        <label for="smc-max-labels" class="block text-xs text-zinc-500">Макс. меток на сторону</label>
                        <input type="number" name="max_ut_labels" id="smc-max-labels" required min="1" max="10"
                            value="{{ old('max_ut_labels', $smc['max_ut_labels']) }}"
                            class="mt-1 w-full max-w-[8rem] rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="show_ut_trailing_stop" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('show_ut_trailing_stop', $smc['show_ut_trailing_stop'] ? '1' : '') === '1' || (old('show_ut_trailing_stop') === null && $smc['show_ut_trailing_stop']))>
                    <span>Линия ATR-трейлинга</span>
                </label>

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
                @endphp
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-buy" class="block text-xs text-zinc-500">Buy</label>
                        <input type="color" name="color_ut_buy" id="smc-buy" value="{{ $buyHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-sell" class="block text-xs text-zinc-500">Sell</label>
                        <input type="color" name="color_ut_sell" id="smc-sell" value="{{ $sellHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-trail-bull" class="block text-xs text-zinc-500">Трейл (бычий)</label>
                        <input type="color" name="color_ut_trail_bull" id="smc-trail-bull" value="{{ $trailBullHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-trail-bear" class="block text-xs text-zinc-500">Трейл (медвежий)</label>
                        <input type="color" name="color_ut_trail_bear" id="smc-trail-bear" value="{{ $trailBearHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="smc-hull-up" class="block text-xs text-zinc-500">Hull Up</label>
                        <input type="color" name="color_hull_up" id="smc-hull-up" value="{{ $hullUpHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-hull-down" class="block text-xs text-zinc-500">Hull Down</label>
                        <input type="color" name="color_hull_down" id="smc-hull-down" value="{{ $hullDownHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-hull-neutral" class="block text-xs text-zinc-500">Hull Neutral</label>
                        <input type="color" name="color_hull_neutral" id="smc-hull-neutral" value="{{ $hullNeutralHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="smc-3br-bull" class="block text-xs text-zinc-500">3BR Bull</label>
                        <input type="color" name="color_three_br_bull" id="smc-3br-bull" value="{{ $threeBrBullHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                    <div>
                        <label for="smc-3br-bear" class="block text-xs text-zinc-500">3BR Bear</label>
                        <input type="color" name="color_three_br_bear" id="smc-3br-bear" value="{{ $threeBrBearHex }}"
                            class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                </div>

                <div>
                    <label for="smc-label-size" class="block text-xs font-medium text-zinc-400">Размер меток</label>
                    <select name="label_size" id="smc-label-size" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        <option value="tiny" @selected(old('label_size', $smc['label_size']) === 'tiny')>Tiny</option>
                        <option value="small" @selected(old('label_size', $smc['label_size']) === 'small')>Small</option>
                        <option value="normal" @selected(old('label_size', $smc['label_size']) === 'normal')>Normal</option>
                    </select>
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
