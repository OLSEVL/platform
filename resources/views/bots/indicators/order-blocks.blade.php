@extends('layouts.app')
@section('title', $bot->name.' — ордерблоки')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Order Blocks &amp; Breaker Blocks (LuxAlgo)</p>
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
            <h2 class="text-base font-semibold tracking-tight text-white">Order Blocks &amp; Breaker Blocks</h2>
            <span class="text-[10px] uppercase tracking-wide text-zinc-500">настройки</span>
        </div>

        <div class="flex gap-0 border-b border-zinc-700 bg-[#1a1c22] px-2">
            <button type="button" class="ob-tab-btn border-b-2 border-white px-3 py-2.5 text-sm font-medium text-white" data-panel="ob-panel-args">
                Аргументы
            </button>
            <button type="button" class="ob-tab-btn border-b-2 border-transparent px-3 py-2.5 text-sm text-zinc-400 hover:text-zinc-200" data-panel="ob-panel-style">
                Стиль
            </button>
        </div>

        <form method="post" action="{{ route('bots.indicators.order-blocks.update', $bot) }}" id="ob-form">
            @csrf
            @method('PUT')

            <div class="max-h-[min(68vh,520px)] overflow-y-auto px-4 py-4 space-y-4 text-sm text-zinc-200">
                <div id="ob-panel-args" class="ob-tab-panel space-y-4">
                    <div>
                        <label for="ob-length" class="block text-xs font-medium text-zinc-400">Swing Lookback</label>
                        <input type="number" name="length" id="ob-length" required min="3" max="100"
                            value="{{ old('length', $ob['length']) }}"
                            class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ob-show-bull" class="block text-xs font-medium text-zinc-400">Show Last Bullish OB</label>
                            <input type="number" name="show_bull" id="ob-show-bull" required min="0" max="20"
                                value="{{ old('show_bull', $ob['show_bull']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_bull')])
                        </div>
                        <div>
                            <label for="ob-show-bear" class="block text-xs font-medium text-zinc-400">Show Last Bearish OB</label>
                            <input type="number" name="show_bear" id="ob-show-bear" required min="0" max="20"
                                value="{{ old('show_bear', $ob['show_bear']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_bear')])
                        </div>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" name="use_body" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('use_body', ($ob['use_body'] ?? false) ? '1' : '') === '1' || (old('use_body') === null && ($ob['use_body'] ?? false)))>
                        <span>Use Candle Body</span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('use_body')])
                    <p class="text-xs text-zinc-500">
                        Вместо high/low для границ OB используются max/min(open, close). Подписи polarity changes в Pine не перенесены.
                    </p>
                </div>

                @php
                    $bullBg = (string) old('color_bull_bg', $ob['color_bull_bg']);
                    $bullBgHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $bullBg) ? $bullBg : '#2157f3';
                    $bullBrkBg = (string) old('color_bull_break_bg', $ob['color_bull_break_bg']);
                    $bullBrkHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $bullBrkBg) ? $bullBrkBg : '#ff1100';
                    $bearBg = (string) old('color_bear_bg', $ob['color_bear_bg']);
                    $bearBgHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $bearBg) ? $bearBg : '#ff5d00';
                    $bearBrkBg = (string) old('color_bear_break_bg', $ob['color_bear_break_bg']);
                    $bearBrkHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $bearBrkBg) ? $bearBrkBg : '#0cb51a';
                @endphp
                <div id="ob-panel-style" class="ob-tab-panel hidden space-y-4">
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <p class="text-xs font-medium text-zinc-300">Bullish OB</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="ob-bull-bg" class="block text-[11px] text-zinc-500">Заливка</label>
                                <input type="color" name="color_bull_bg" id="ob-bull-bg" value="{{ $bullBgHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_bg')])
                            </div>
                            <div>
                                <label for="ob-bull-alpha" class="block text-[11px] text-zinc-500">Прозрачность %</label>
                                <input type="number" name="color_bull_bg_alpha" id="ob-bull-alpha" required min="0" max="100"
                                    value="{{ old('color_bull_bg_alpha', $ob['color_bull_bg_alpha']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_bg_alpha')])
                            </div>
                            <div>
                                <label for="ob-bull-border" class="block text-[11px] text-zinc-500">Граница (#hex)</label>
                                <input type="color" name="color_bull_border" id="ob-bull-border" value="{{ $bullBgHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_border')])
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <p class="text-xs font-medium text-zinc-300">Bullish Break</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="ob-bull-brk-bg" class="block text-[11px] text-zinc-500">Заливка</label>
                                <input type="color" name="color_bull_break_bg" id="ob-bull-brk-bg" value="{{ $bullBrkHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_break_bg')])
                            </div>
                            <div>
                                <label for="ob-bull-brk-alpha" class="block text-[11px] text-zinc-500">Прозрачность %</label>
                                <input type="number" name="color_bull_break_bg_alpha" id="ob-bull-brk-alpha" required min="0" max="100"
                                    value="{{ old('color_bull_break_bg_alpha', $ob['color_bull_break_bg_alpha']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_break_bg_alpha')])
                            </div>
                            <div>
                                <label for="ob-bull-brk-border" class="block text-[11px] text-zinc-500">Граница (#hex)</label>
                                <input type="color" name="color_bull_break_border" id="ob-bull-brk-border" value="{{ $bullBrkHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bull_break_border')])
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <p class="text-xs font-medium text-zinc-300">Bearish OB</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="ob-bear-bg" class="block text-[11px] text-zinc-500">Заливка</label>
                                <input type="color" name="color_bear_bg" id="ob-bear-bg" value="{{ $bearBgHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_bg')])
                            </div>
                            <div>
                                <label for="ob-bear-alpha" class="block text-[11px] text-zinc-500">Прозрачность %</label>
                                <input type="number" name="color_bear_bg_alpha" id="ob-bear-alpha" required min="0" max="100"
                                    value="{{ old('color_bear_bg_alpha', $ob['color_bear_bg_alpha']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_bg_alpha')])
                            </div>
                            <div>
                                <label for="ob-bear-border" class="block text-[11px] text-zinc-500">Граница (#hex)</label>
                                <input type="color" name="color_bear_border" id="ob-bear-border" value="{{ $bearBgHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_border')])
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <p class="text-xs font-medium text-zinc-300">Bearish Break</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="ob-bear-brk-bg" class="block text-[11px] text-zinc-500">Заливка</label>
                                <input type="color" name="color_bear_break_bg" id="ob-bear-brk-bg" value="{{ $bearBrkHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_break_bg')])
                            </div>
                            <div>
                                <label for="ob-bear-brk-alpha" class="block text-[11px] text-zinc-500">Прозрачность %</label>
                                <input type="number" name="color_bear_break_bg_alpha" id="ob-bear-brk-alpha" required min="0" max="100"
                                    value="{{ old('color_bear_break_bg_alpha', $ob['color_bear_break_bg_alpha']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 text-zinc-100">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_break_bg_alpha')])
                            </div>
                            <div>
                                <label for="ob-bear-brk-border" class="block text-[11px] text-zinc-500">Граница (#hex)</label>
                                <input type="color" name="color_bear_break_border" id="ob-bear-brk-border" value="{{ $bearBrkHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_bear_break_border')])
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <details class="border-t border-zinc-700 bg-black/25 px-4 py-3">
                <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-300">Режим эксперта (JSON)</summary>
                <p class="mt-2 text-[11px] text-zinc-500">
                    Полный merge с дефолтами сервиса. Ключи см. <code class="text-zinc-400">OrderBlocksIndicatorService::DEFAULT_PARAMS</code>.
                </p>
                <textarea name="expert_settings_json" rows="8"
                    class="mt-2 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-200">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('expert_settings_json')]){{ old('expert_settings_json', json_encode($ob, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
                @error('expert_settings_json')
                    <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
                @enderror
                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-zinc-400">
                    <input type="checkbox" name="use_expert_json" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('use_expert_json'))>
                    Сохранить из JSON вместо формы
                </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('use_expert_json')])
            </details>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-zinc-700 bg-[#1a1c22] px-4 py-3">
                <a href="{{ route('bots.show', $bot) }}" class="rounded border border-zinc-600 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800">
                    Отмена
                </a>
                <button type="submit" class="rounded bg-white px-5 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-200">
                    Сохранить
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.ob-tab-btn');
    const panels = document.querySelectorAll('.ob-tab-panel');
    function activate(id) {
        panels.forEach(function (p) {
            p.classList.toggle('hidden', p.id !== id);
        });
        buttons.forEach(function (b) {
            const on = b.getAttribute('data-panel') === id;
            b.classList.toggle('border-white', on);
            b.classList.toggle('border-transparent', !on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('font-medium', on);
            b.classList.toggle('text-zinc-400', !on);
        });
    }
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activate(btn.getAttribute('data-panel'));
        });
    });
})();
</script>
@endsection
