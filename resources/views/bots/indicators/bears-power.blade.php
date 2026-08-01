@extends('layouts.app')
@section('title', $bot->name.' — Bears Power')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Bears Power (OsEngine) — гистограмма</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Bears Power</h2></div>
        <form method="post" action="{{ route('bots.indicators.bears-power.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">Period (EMA)</label><input type="number" name="period" min="1" max="500" required value="{{ old('period', $p['period']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_histogram" value="1" class="rounded border-zinc-600" @checked(old('show_histogram', $p['show_histogram'] ? '1' : '') === '1' || (old('show_histogram') === null && $p['show_histogram']))> Гистограмма</label>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Цвет ≥0</label><input type="text" name="color_histogram" value="{{ old('color_histogram', $p['color_histogram']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
                <div><label class="block text-xs text-zinc-400">Цвет &lt;0</label><input type="text" name="color_histogram_neg" value="{{ old('color_histogram_neg', $p['color_histogram_neg']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
