@extends('layouts.app')
@section('title', $bot->name.' — Engulfing Liquidity')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Engulfing Liquidity</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded border border-rose-800/60 bg-rose-950/40 px-4 py-3 text-sm text-rose-200">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="post" action="{{ route('bots.indicators.engulfing-liquidity.update', $bot) }}"
        class="space-y-5 rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
        @csrf
        @method('PUT')
        <div>
            <h2 class="text-base font-semibold text-zinc-100">Поглощение (3 шага)</h2>
            <p class="mt-1 text-sm text-zinc-400">
                Метки ENGULF 1/2/3 на завершённых цепочках. Тот же детектор, что у rule-based бота
                <code class="text-zinc-300">engulfing_liquidity</code>.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['allow_classic', 'Классическое engulf', 'color_step1', 'Шаг 1 (контекст)'],
                ['allow_same_color', 'Same-color engulf', 'color_step2', 'Шаг 2 (реакция)'],
            ] as [$toggle, $label, $colorKey, $colorLabel])
                <div class="rounded border border-zinc-700 bg-zinc-950/40 p-3">
                    <input type="hidden" name="{{ $toggle }}" value="0">
                    <label class="flex items-center gap-2 text-sm text-zinc-200">
                        <input type="checkbox" name="{{ $toggle }}" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old($toggle, $eng[$toggle]))>
                        {{ $label }}
                    </label>
                    <div class="mt-2 flex items-center gap-2 text-xs text-zinc-400">
                        <span>{{ $colorLabel }}</span>
                        <input type="color" name="{{ $colorKey }}" value="{{ old($colorKey, $eng[$colorKey]) }}" class="h-7 w-12 cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded border border-zinc-700 bg-zinc-950/40 p-3">
                <input type="hidden" name="show_step_markers" value="0">
                <label class="flex items-center gap-2 text-sm text-zinc-200">
                    <input type="checkbox" name="show_step_markers" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('show_step_markers', $eng['show_step_markers']))>
                    Показывать шаги 1 и 2
                </label>
            </div>
            <div class="rounded border border-zinc-700 bg-zinc-950/40 p-3">
                <input type="hidden" name="show_entries" value="0">
                <label class="flex items-center gap-2 text-sm text-zinc-200">
                    <input type="checkbox" name="show_entries" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('show_entries', $eng['show_entries']))>
                    Показывать шаг 3 (вход)
                </label>
            </div>
        </div>

        <div class="flex flex-wrap gap-4 text-xs text-zinc-400">
            <label class="flex items-center gap-2">CALL (шаг 3)
                <input type="color" name="color_step3_call" value="{{ old('color_step3_call', $eng['color_step3_call']) }}" class="h-7 w-12 rounded border border-zinc-600 bg-zinc-900">
            </label>
            <label class="flex items-center gap-2">PUT (шаг 3)
                <input type="color" name="color_step3_put" value="{{ old('color_step3_put', $eng['color_step3_put']) }}" class="h-7 w-12 rounded border border-zinc-600 bg-zinc-900">
            </label>
        </div>

        <div class="space-y-1">
            <label for="eng-label-size" class="block text-xs font-medium text-zinc-400">Размер подписей</label>
            <select name="label_size" id="eng-label-size" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-950 px-3 py-2 text-zinc-100">
                @foreach (['tiny' => 'Tiny', 'small' => 'Small', 'normal' => 'Normal'] as $value => $title)
                    <option value="{{ $value }}" @selected(old('label_size', $eng['label_size']) === $value)>{{ $title }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
            <a href="{{ route('bots.show', $bot) }}" class="rounded border border-zinc-600 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-800">Отмена</a>
            <button type="submit" class="rounded bg-white px-5 py-2 text-sm font-medium text-zinc-900 hover:bg-zinc-200">Сохранить</button>
        </div>
    </form>
</div>
@endsection
