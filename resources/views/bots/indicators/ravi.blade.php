@extends('layouts.app')
@section('title', $bot->name.' — RAVI')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · RAVI — отдельная pane</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">RAVI</h2></div>
        <form method="post" action="{{ route('bots.indicators.ravi.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Length slow</label><input type="number" name="length_slow" min="1" max="500" required value="{{ old('length_slow', $p['length_slow']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length_slow')])</div>
                <div><label class="block text-xs text-zinc-400">Length fast</label><input type="number" name="length_fast" min="1" max="500" required value="{{ old('length_fast', $p['length_fast']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length_fast')])</div>
            </div>
            <div>
                <label class="block text-xs text-zinc-400">Candle point</label>
                <select name="candle_point" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
@foreach (['Close','Open','High','Low','Median','Typical'] as $cp)
                        <option value="{{ $cp }}" @selected(old('candle_point', $p['candle_point']) === $cp)>{{ $cp }}</option>
                    @endforeach
                </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('candle_point')])
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Up line level</label><input type="number" name="up_line" step="any" required value="{{ old('up_line', $p['up_line']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('up_line')])</div>
                <div><label class="block text-xs text-zinc-400">Down line level</label><input type="number" name="down_line" step="any" required value="{{ old('down_line', $p['down_line']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('down_line')])</div>
            </div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_ravi" value="1" class="rounded border-zinc-600" @checked(old('show_ravi', $p['show_ravi'] ? '1' : '') === '1' || (old('show_ravi') === null && $p['show_ravi']))> RAVI line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_ravi')])
            <input type="text" name="color_ravi" value="{{ old('color_ravi', $p['color_ravi']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_ravi')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_up_line" value="1" class="rounded border-zinc-600" @checked(old('show_up_line', $p['show_up_line'] ? '1' : '') === '1' || (old('show_up_line') === null && $p['show_up_line']))> Up line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_up_line')])
            <input type="text" name="color_up_line" value="{{ old('color_up_line', $p['color_up_line']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_up_line')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_down_line" value="1" class="rounded border-zinc-600" @checked(old('show_down_line', $p['show_down_line'] ? '1' : '') === '1' || (old('show_down_line') === null && $p['show_down_line']))> Down line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_down_line')])
            <input type="text" name="color_down_line" value="{{ old('color_down_line', $p['color_down_line']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_down_line')])
            <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])</div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
