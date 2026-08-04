@extends('layouts.app')
@section('title', $bot->name.' — COG')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · COG — отдельная pane</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Center of Gravity (COG)</h2></div>
        <form method="post" action="{{ route('bots.indicators.cog.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">Length</label><input type="number" name="length" min="1" max="500" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])</div>
            <div>
                <label class="block text-xs text-zinc-400">Candle point</label>
                <select name="candle_point" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
@foreach (['Close','Open','High','Low','Median','Typical'] as $cp)
                        <option value="{{ $cp }}" @selected(old('candle_point', $p['candle_point']) === $cp)>{{ $cp }}</option>
                    @endforeach
                </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('candle_point')])
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_cog" value="1" class="rounded border-zinc-600" @checked(old('show_cog', $p['show_cog'] ? '1' : '') === '1' || (old('show_cog') === null && $p['show_cog']))> COG line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_cog')])
            <input type="text" name="color_cog" value="{{ old('color_cog', $p['color_cog']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_cog')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_prev_cog" value="1" class="rounded border-zinc-600" @checked(old('show_prev_cog', $p['show_prev_cog'] ? '1' : '') === '1' || (old('show_prev_cog') === null && $p['show_prev_cog']))> Prev COG</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_prev_cog')])
            <input type="text" name="color_prev_cog" value="{{ old('color_prev_cog', $p['color_prev_cog']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_prev_cog')])
            <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])</div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
