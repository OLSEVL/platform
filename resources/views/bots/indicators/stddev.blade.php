@extends('layouts.app')
@section('title', $bot->name.' — StdDev')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · StdDev — отдельная pane</p>
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
            <h2 class="text-base font-semibold text-white">StdDev</h2>
        </div>
        <form method="post" action="{{ route('bots.indicators.stddev.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-zinc-400">Period</label>
                    <input type="number" name="period" min="1" max="500" required value="{{ old('period', $p['period']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('period')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Candle point</label>
                    <select name="candle_point" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
@foreach (['Close','Typical','Median','Open','High','Low'] as $cp)
                            <option value="{{ $cp }}" @selected(old('candle_point', $p['candle_point']) === $cp)>{{ $cp }}</option>
                        @endforeach
                    </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('candle_point')])
                </div>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_line" value="1" class="rounded border-zinc-600" @checked(old('show_line', $p['show_line'] ? '1' : '') === '1' || (old('show_line') === null && $p['show_line']))> Показывать линию</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_line')])
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-zinc-400">Color</label>
                    <input type="text" name="color" value="{{ old('color', $p['color']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Line width</label>
                    <input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])
                </div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
