@extends('layouts.app')
@section('title', $bot->name.' — Sweep / Raid Liquidity')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Sweep / Raid Liquidity</p>
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

    <form method="post" action="{{ route('bots.indicators.sweep-raid-liquidity.update', $bot) }}"
        class="space-y-5 rounded-xl border border-zinc-700 bg-zinc-900/50 p-5">
        @csrf
        @method('PUT')
        <div>
            <h2 class="text-base font-semibold text-zinc-100">Свип / рейд ликвидности</h2>
            <p class="mt-1 text-sm text-zinc-400">Отдельный слой фракталов, свипов, рейдов и первой откатной. Он использует тот же детектор, что и бот.</p>
        </div>

        <div class="space-y-1">
            <label for="srl-pivot-length" class="block text-xs font-medium text-zinc-400">Фрактал: баров слева/справа</label>
            <input type="number" name="pivot_length" id="srl-pivot-length" min="1" max="100" required
                value="{{ old('pivot_length', $srl['pivot_length']) }}"
                class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-950 px-3 py-2 text-zinc-100">
            <p class="text-[11px] text-zinc-500">Для совпадения отметок с ботом укажите то же значение, что в настройках бота.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['show_fractals', 'Фракталы', 'color_fractal_high', 'Фрактал high', 'color_fractal_low', 'Фрактал low'],
                ['show_sweeps', 'Свипы', 'color_sweep', 'Цвет свипа', null, null],
                ['show_raids', 'Рейды', 'color_raid', 'Цвет рейда', null, null],
                ['show_pullback_entries', 'Первая откатная (PB)', 'color_pullback', 'Цвет PB', null, null],
                ['show_breakout_entries', 'Пробой (BO)', 'color_breakout', 'Цвет пробоя', null, null],
            ] as [$toggle, $label, $firstColor, $firstLabel, $secondColor, $secondLabel])
                <div class="rounded border border-zinc-700 bg-zinc-950/40 p-3">
                    <input type="hidden" name="{{ $toggle }}" value="0">
                    <label class="flex items-center gap-2 text-sm text-zinc-200">
                        <input type="checkbox" name="{{ $toggle }}" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old($toggle, $srl[$toggle] ?? false))>
                        {{ $label }}
                    </label>
                    <div class="mt-2 flex items-center gap-2 text-xs text-zinc-400">
                        <span>{{ $firstLabel }}</span>
                        <input type="color" name="{{ $firstColor }}" value="{{ old($firstColor, $srl[$firstColor] ?? '#38bdf8') }}" class="h-7 w-12 cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                        @if ($secondColor)
                            <span>{{ $secondLabel }}</span>
                            <input type="color" name="{{ $secondColor }}" value="{{ old($secondColor, $srl[$secondColor]) }}" class="h-7 w-12 cursor-pointer rounded border border-zinc-600 bg-zinc-900">
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-1">
            <label for="srl-breakout-closes" class="block text-xs font-medium text-zinc-400">Закрытий за уровнем (пробой)</label>
            <input type="number" name="breakout_closes" id="srl-breakout-closes" min="1" max="5" required
                value="{{ old('breakout_closes', $srl['breakout_closes'] ?? 2) }}"
                class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-950 px-3 py-2 text-zinc-100">
            <p class="text-[11px] text-zinc-500">Сколько подряд close за FH/FL считать закреплением для метки BO.</p>
        </div>

        <div class="space-y-1">
            <label for="srl-label-size" class="block text-xs font-medium text-zinc-400">Размер подписей</label>
            <select name="label_size" id="srl-label-size" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-950 px-3 py-2 text-zinc-100">
                @foreach (['tiny' => 'Tiny', 'small' => 'Small', 'normal' => 'Normal'] as $value => $title)
                    <option value="{{ $value }}" @selected(old('label_size', $srl['label_size']) === $value)>{{ $title }}</option>
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
