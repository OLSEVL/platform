@extends('layouts.app')
@section('title', $bot->name.' — Price Action (DGT)')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Price Action — S&amp;R by DGT</p>
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

    {{-- Панель в стиле модального окна TradingView: тёмный фон, вкладки, прокрутка. --}}
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a] shadow-2xl shadow-black/50">
        <div class="flex items-center justify-between border-b border-zinc-700 bg-[#1e2028] px-4 py-3">
            <h2 class="text-base font-semibold tracking-tight text-white">S&amp;R by DGT</h2>
            <span class="text-[10px] uppercase tracking-wide text-zinc-500">настройки</span>
        </div>

        <div class="flex gap-0 border-b border-zinc-700 bg-[#1a1c22] px-2">
            <button type="button" class="pa-tab-btn border-b-2 border-white px-3 py-2.5 text-sm font-medium text-white" data-panel="pa-panel-args">
                Аргументы
            </button>
            <button type="button" class="pa-tab-btn border-b-2 border-transparent px-3 py-2.5 text-sm text-zinc-400 hover:text-zinc-200" data-panel="pa-panel-style">
                Стиль
            </button>
            <button type="button" class="pa-tab-btn border-b-2 border-transparent px-3 py-2.5 text-sm text-zinc-400 hover:text-zinc-200" data-panel="pa-panel-vis">
                Видимость
            </button>
        </div>

        <form method="post" action="{{ route('bots.indicators.price-action-dgt.update', $bot) }}" id="pa-dgt-form">
            @csrf
            @method('PUT')

            <div class="max-h-[min(68vh,520px)] overflow-y-auto px-4 py-4">
                <div id="pa-panel-args" class="pa-tab-panel">
                    @include('bots.indicators.partials.pa-dgt-tab-args', ['pa' => $pa])
                </div>
                <div id="pa-panel-style" class="pa-tab-panel hidden">
                    @include('bots.indicators.partials.pa-dgt-tab-style', ['pa' => $pa])
                </div>
                <div id="pa-panel-vis" class="pa-tab-panel hidden">
                    @include('bots.indicators.partials.pa-dgt-tab-visibility', ['pa' => $pa])
                </div>
            </div>

            <details class="border-t border-zinc-700 bg-black/25 px-4 py-3">
                <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-300">Режим эксперта (JSON)</summary>
                <p class="mt-2 text-[11px] text-zinc-500">
                    Включите «Сохранить из JSON», чтобы записать настройки из поля ниже вместо формы (полный merge с дефолтами сервиса).
                </p>
                <textarea name="expert_settings_json" rows="8"
                    class="mt-2 w-full rounded border border-zinc-700 bg-zinc-900 px-2 py-1.5 font-mono text-[11px] text-zinc-200">{{ old('expert_settings_json', json_encode($pa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
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
    const buttons = document.querySelectorAll('.pa-tab-btn');
    const panels = document.querySelectorAll('.pa-tab-panel');
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
