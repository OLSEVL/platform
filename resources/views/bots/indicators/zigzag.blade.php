@extends('layouts.app')
@section('title', $bot->name.' — ZigZag')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            {{-- Описание: ломаная по значимым хай/лоу (ZigZag). --}}
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · ZigZag — структура волн</p>
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
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">ZigZag</h2></div>
        <form method="post" action="{{ route('bots.indicators.zigzag.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">length</label><input type="number" name="length" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])</div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_line" value="1" class="rounded border-zinc-600" @checked(old('show_line', $p['show_line'] ? '1' : '') === '1' || (old('show_line') === null && $p['show_line']))> ZigZag Line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_line')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_highs" value="1" class="rounded border-zinc-600" @checked(old('show_highs', $p['show_highs'] ? '1' : '') === '1' || (old('show_highs') === null && $p['show_highs']))> Highs</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_highs')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_lows" value="1" class="rounded border-zinc-600" @checked(old('show_lows', $p['show_lows'] ? '1' : '') === '1' || (old('show_lows') === null && $p['show_lows']))> Lows</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_lows')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_points" value="1" class="rounded border-zinc-600" @checked(old('show_points', $p['show_points'] ? '1' : '') === '1' || (old('show_points') === null && $p['show_points']))> ZigZag Points</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_points')])
            <div><label class="block text-xs text-zinc-400">color_line</label><input type="text" name="color_line" value="{{ old('color_line', $p['color_line']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_line')])</div>
            <div><label class="block text-xs text-zinc-400">color_high</label><input type="text" name="color_high" value="{{ old('color_high', $p['color_high']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_high')])</div>
            <div><label class="block text-xs text-zinc-400">color_low</label><input type="text" name="color_low" value="{{ old('color_low', $p['color_low']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_low')])</div>
            <div><label class="block text-xs text-zinc-400">line_width</label><input type="number" name="line_width" required value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])</div>
            <div><label class="block text-xs text-zinc-400">point_radius</label><input type="number" name="point_radius" required value="{{ old('point_radius', $p['point_radius']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('point_radius')])</div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
