@extends('layouts.app')
@section('title', $bot->name.' — Liquidity Swings')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Liquidity Swings (LuxAlgo)</p>
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

    {{-- Панель в стиле TradingView: вкладки «Аргументы» / «Стиль». Intrabar Precision в Pine на сервере не реализуем. --}}
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a] shadow-2xl shadow-black/50">
        <div class="flex items-center justify-between border-b border-zinc-700 bg-[#1e2028] px-4 py-3">
            <h2 class="text-base font-semibold tracking-tight text-white">Liquidity Swings</h2>
            <span class="text-[10px] uppercase tracking-wide text-zinc-500">настройки</span>
        </div>

        <div class="flex gap-0 border-b border-zinc-700 bg-[#1a1c22] px-2">
            <button type="button" class="ls-tab-btn border-b-2 border-white px-3 py-2.5 text-sm font-medium text-white" data-panel="ls-panel-args">
                Аргументы
            </button>
            <button type="button" class="ls-tab-btn border-b-2 border-transparent px-3 py-2.5 text-sm text-zinc-400 hover:text-zinc-200" data-panel="ls-panel-style">
                Стиль
            </button>
        </div>

        <form method="post" action="{{ route('bots.indicators.liquidity-swings.update', $bot) }}" id="ls-form">
            @csrf
            @method('PUT')

            <div class="max-h-[min(68vh,520px)] overflow-y-auto px-4 py-4 space-y-4 text-sm text-zinc-200">
                <div id="ls-panel-args" class="ls-tab-panel space-y-4">
                    <div>
                        <label for="ls-length" class="block text-xs font-medium text-zinc-400">Pivot Lookback</label>
                        <input type="number" name="length" id="ls-length" required min="1" max="500"
                            value="{{ old('length', $ls['length']) }}"
                            class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                    </div>
                    <div>
                        <label for="ls-area" class="block text-xs font-medium text-zinc-400">Swing Area</label>
                        <select name="area" id="ls-area" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                            <option value="wick" @selected(old('area', $ls['area']) === 'wick')>Wick Extremity</option>
                            <option value="full" @selected(old('area', $ls['area']) === 'full')>Full Range</option>
                        </select>
                    </div>
                    <div class="rounded border border-zinc-700/80 bg-zinc-900/50 px-3 py-2 text-xs text-zinc-500">
                        <span class="font-medium text-zinc-400">Intrabar Precision</span> — в оригинале LuxAlgo использует младший таймфрейм;
                        на платформе расчёт только по барам текущего графика, опция отключена.
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label for="ls-filter-by" class="block text-xs font-medium text-zinc-400">Filter Areas By</label>
                            <select name="filter_by" id="ls-filter-by" class="mt-1 rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                                <option value="count" @selected(old('filter_by', $ls['filter_by']) === 'count')>Count</option>
                                <option value="volume" @selected(old('filter_by', $ls['filter_by']) === 'volume')>Volume</option>
                            </select>
                        </div>
                        <div class="flex-1 min-w-[6rem] max-w-xs">
                            <label for="ls-filter-value" class="block text-xs font-medium text-zinc-400">Порог</label>
                            <input type="number" name="filter_value" id="ls-filter-value" required step="any"
                                value="{{ old('filter_value', $ls['filter_value']) }}"
                                class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                        </div>
                    </div>
                </div>

                @php
                    $highLineVal = (string) old('color_high_line', $ls['color_high_line']);
                    $highLineHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $highLineVal) ? $highLineVal : '#ef4444';
                    $lowLineVal = (string) old('color_low_line', $ls['color_low_line']);
                    $lowLineHex = preg_match('/^#[0-9A-Fa-f]{6}$/', $lowLineVal) ? $lowLineVal : '#14b8a6';
                @endphp
                <div id="ls-panel-style" class="ls-tab-panel hidden space-y-4">
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="show_swing_high" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('show_swing_high', $ls['show_swing_high'] ? '1' : '') === '1' || (old('show_swing_high') === null && $ls['show_swing_high']))>
                            <span class="text-zinc-200">Swing High</span>
                        </label>
                        <div class="grid gap-3 pl-6 sm:grid-cols-2">
                            <div>
                                <label for="ls-ch-line" class="block text-[11px] text-zinc-500">Линия (#hex)</label>
                                <input type="color" name="color_high_line" id="ls-ch-line" value="{{ $highLineHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                                <p class="mt-1 text-[10px] text-zinc-500">Нестандартный цвет линии — через JSON внизу.</p>
                            </div>
                            <div>
                                <label for="ls-ch-fill" class="block text-[11px] text-zinc-500">Area (заливка)</label>
                                <input type="text" name="color_high_fill" id="ls-ch-fill" required maxlength="64"
                                    value="{{ old('color_high_fill', $ls['color_high_fill']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-200"
                                    placeholder="rgba(...)">
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2 rounded border border-zinc-700/60 bg-zinc-900/30 p-3">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" name="show_swing_low" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('show_swing_low', $ls['show_swing_low'] ? '1' : '') === '1' || (old('show_swing_low') === null && $ls['show_swing_low']))>
                            <span class="text-zinc-200">Swing Low</span>
                        </label>
                        <div class="grid gap-3 pl-6 sm:grid-cols-2">
                            <div>
                                <label for="ls-cl-line" class="block text-[11px] text-zinc-500">Линия (#hex)</label>
                                <input type="color" name="color_low_line" id="ls-cl-line" value="{{ $lowLineHex }}"
                                    class="mt-1 h-10 w-full max-w-[8rem] cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                                <p class="mt-1 text-[10px] text-zinc-500">Нестандартный цвет — через JSON.</p>
                            </div>
                            <div>
                                <label for="ls-cl-fill" class="block text-[11px] text-zinc-500">Area (заливка)</label>
                                <input type="text" name="color_low_fill" id="ls-cl-fill" required maxlength="64"
                                    value="{{ old('color_low_fill', $ls['color_low_fill']) }}"
                                    class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1.5 font-mono text-xs text-zinc-200">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="ls-label-size" class="block text-xs font-medium text-zinc-400">Labels Size</label>
                        <select name="label_size" id="ls-label-size" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2 text-zinc-100">
                            <option value="tiny" @selected(old('label_size', $ls['label_size'] ?? 'tiny') === 'tiny')>Tiny</option>
                            <option value="small" @selected(old('label_size', $ls['label_size'] ?? 'tiny') === 'small')>Small</option>
                            <option value="normal" @selected(old('label_size', $ls['label_size'] ?? 'tiny') === 'normal')>Normal</option>
                        </select>
                    </div>
                </div>
            </div>

            <details class="border-t border-zinc-700 bg-black/25 px-4 py-3">
                <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-300">Режим эксперта (JSON)</summary>
                <p class="mt-2 text-[11px] text-zinc-500">
                    Полный merge с дефолтами сервиса. Ключи см. <code class="text-zinc-400">LiquiditySwingsIndicatorService::DEFAULT_PARAMS</code>.
                </p>
                <textarea name="expert_settings_json" rows="8"
                    class="mt-2 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-200">{{ old('expert_settings_json', json_encode($ls, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
                @error('expert_settings_json')
                    <p class="mt-1 text-xs text-rose-400">{{ $message }}</p>
                @enderror
                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-zinc-400">
                    <input type="checkbox" name="use_expert_json" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('use_expert_json'))>
                    Сохранить из JSON вместо формы
                </label>
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
    const buttons = document.querySelectorAll('.ls-tab-btn');
    const panels = document.querySelectorAll('.ls-tab-panel');
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
