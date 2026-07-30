@extends('layouts.app')
@section('title', $bot->name.' — MACD')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · MACD (OsEngine) — отдельная pane</p>
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
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3">
            <h2 class="text-base font-semibold text-white">MACD</h2>
        </div>
        <form method="post" action="{{ route('bots.indicators.macd.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="block text-xs text-zinc-400">Fast length</label>
                    <input type="number" name="length_fast" min="1" max="500" required value="{{ old('length_fast', $p['length_fast']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Slow length</label>
                    <input type="number" name="length_slow" min="1" max="500" required value="{{ old('length_slow', $p['length_slow']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Signal length</label>
                    <input type="number" name="length_signal" min="1" max="500" required value="{{ old('length_signal', $p['length_signal']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
            </div>
            <div class="space-y-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_macd" value="1" class="rounded border-zinc-600" @checked(old('show_macd', $p['show_macd'] ? '1' : '') === '1' || (old('show_macd') === null && $p['show_macd']))> Show MACD line</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="show_signal" value="1" class="rounded border-zinc-600" @checked(old('show_signal', $p['show_signal'] ? '1' : '') === '1' || (old('show_signal') === null && $p['show_signal']))> Show signal line</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="show_histogram" value="1" class="rounded border-zinc-600" @checked(old('show_histogram', $p['show_histogram'] ? '1' : '') === '1' || (old('show_histogram') === null && $p['show_histogram']))> Show histogram</label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-zinc-400">MACD color</label>
                    <input type="text" name="color_macd" value="{{ old('color_macd', $p['color_macd']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Signal color</label>
                    <input type="text" name="color_signal" value="{{ old('color_signal', $p['color_signal']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Histogram (+)</label>
                    <input type="text" name="color_histogram" value="{{ old('color_histogram', $p['color_histogram']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Histogram (−)</label>
                    <input type="text" name="color_histogram_neg" value="{{ old('color_histogram_neg', $p['color_histogram_neg']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Line width</label>
                    <input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                </div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
