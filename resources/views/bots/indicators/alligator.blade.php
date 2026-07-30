@extends('layouts.app')
@section('title', $bot->name.' — Alligator')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Alligator (OsEngine) — оверлей на цене</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Alligator</h2></div>
        <form method="post" action="{{ route('bots.indicators.alligator.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            @foreach (['jaw' => 'Jaw', 'teeth' => 'Teeth', 'lips' => 'Lips'] as $key => $label)
                <div class="rounded border border-zinc-700/60 p-3 space-y-2">
                    <div class="text-xs font-medium text-zinc-400">{{ $label }}</div>
                    <label class="flex items-center gap-2"><input type="checkbox" name="show_{{ $key }}" value="1" class="rounded border-zinc-600" @checked(old('show_'.$key, $p['show_'.$key] ? '1' : '') === '1' || (old('show_'.$key) === null && $p['show_'.$key]))> Показывать</label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="block text-xs text-zinc-500">Length</label><input type="number" name="length_{{ $key }}" min="1" max="500" required value="{{ old('length_'.$key, $p['length_'.$key]) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                        <div><label class="block text-xs text-zinc-500">Offset</label><input type="number" name="shift_{{ $key }}" min="0" max="500" required value="{{ old('shift_'.$key, $p['shift_'.$key]) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                    </div>
                    <input type="text" name="color_{{ $key }}" value="{{ old('color_'.$key, $p['color_'.$key]) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                </div>
            @endforeach
            <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
