@extends('layouts.app')
@section('title', $bot->name.' — Mass Index')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Mass Index (OsEngine) — отдельная pane</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Mass Index</h2></div>
        <form method="post" action="{{ route('bots.indicators.mass-index.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Length EMA</label><input type="number" name="length_ema" min="1" max="500" required value="{{ old('length_ema', $p['length_ema']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Period sum</label><input type="number" name="period_sum" min="1" max="500" required value="{{ old('period_sum', $p['period_sum']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Level up (27)</label><input type="number" name="level_up" step="any" min="0" required value="{{ old('level_up', $p['level_up']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Level down (26.5)</label><input type="number" name="level_down" step="any" min="0" required value="{{ old('level_down', $p['level_down']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_mi" value="1" class="rounded border-zinc-600" @checked(old('show_mi', $p['show_mi'] ? '1' : '') === '1' || (old('show_mi') === null && $p['show_mi']))> MI line</label>
            <input type="text" name="color_mi" value="{{ old('color_mi', $p['color_mi']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
            <label class="flex items-center gap-2"><input type="checkbox" name="show_level_up" value="1" class="rounded border-zinc-600" @checked(old('show_level_up', $p['show_level_up'] ? '1' : '') === '1' || (old('show_level_up') === null && $p['show_level_up']))> Level up</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_level_down" value="1" class="rounded border-zinc-600" @checked(old('show_level_down', $p['show_level_down'] ? '1' : '') === '1' || (old('show_level_down') === null && $p['show_level_down']))> Level down</label>
            <input type="text" name="color_level" value="{{ old('color_level', $p['color_level']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
            <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
