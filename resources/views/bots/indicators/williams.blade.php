@extends('layouts.app')
@section('title', $bot->name.' — Williams %R')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Williams %R (OsEngine) — отдельная pane</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded border border-rose-800/60 bg-rose-950/40 px-4 py-3 text-sm text-rose-200">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Williams %R</h2></div>
        <form method="post" action="{{ route('bots.indicators.williams.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs text-zinc-400">Period</label>
                <input type="number" name="period" min="1" max="500" required value="{{ old('period', $p['period']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_line" value="1" class="rounded border-zinc-600" @checked(old('show_line', $p['show_line'] ? '1' : '') === '1' || (old('show_line') === null && $p['show_line']))> Показывать линию</label>
            <input type="text" name="color" value="{{ old('color', $p['color']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
            <div>
                <label class="block text-xs text-zinc-400">Line width</label>
                <input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
            </div>
            <div class="rounded border border-zinc-700/60 p-3 space-y-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_levels" value="1" class="rounded border-zinc-600" @checked(old('show_levels', $p['show_levels'] ? '1' : '') === '1' || (old('show_levels') === null && $p['show_levels']))> Уровни -20 / -80</label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="block text-xs text-zinc-400">Overbought (-20)</label><input type="number" name="level_overbought" step="any" value="{{ old('level_overbought', $p['level_overbought']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                    <div><label class="block text-xs text-zinc-400">Oversold (-80)</label><input type="number" name="level_oversold" step="any" value="{{ old('level_oversold', $p['level_oversold']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                </div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
