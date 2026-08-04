@extends('layouts.app')
@section('title', $bot->name.' — SuperTrend')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · SuperTrend</p>
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
            <h2 class="text-base font-semibold text-white">SuperTrend</h2>
            <p class="text-[11px] text-zinc-500 mt-0.5">Trailing-stop на ATR; Center = текущий тренд</p>
        </div>
        <form method="post" action="{{ route('bots.indicators.supertrend.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-zinc-400">Length</label>
                    <input type="number" name="length" min="1" max="500" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Deviation factor</label>
                    <input type="number" name="deviation" step="any" min="0.01" max="50" required value="{{ old('deviation', $p['deviation']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('deviation')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">Candle Point</label>
                    <select name="candle_point" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
@foreach (['Median', 'Typical'] as $pt)
                            <option value="{{ $pt }}" @selected(old('candle_point', $p['candle_point']) === $pt)>{{ $pt }}</option>
                        @endforeach
                    </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('candle_point')])
                </div>
                <div class="flex items-end pb-2">
                    <label class="flex items-center gap-2"><input type="checkbox" name="use_wicks" value="1" class="rounded border-zinc-600" @checked(old('use_wicks', $p['use_wicks'] ? '1' : '') === '1' || (old('use_wicks') === null && $p['use_wicks']))> Use candle shadow</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('use_wicks')])
                </div>
            </div>
            <div class="space-y-2 rounded border border-zinc-700/60 p-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_center" value="1" class="rounded border-zinc-600" @checked(old('show_center', $p['show_center'] ? '1' : '') === '1' || (old('show_center') === null && $p['show_center']))> Center Line</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_center')])
                <input type="text" name="color_center" value="{{ old('color_center', $p['color_center']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_center')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_upper" value="1" class="rounded border-zinc-600" @checked(old('show_upper', $p['show_upper'] ? '1' : '') === '1' || (old('show_upper') === null && $p['show_upper']))> Upper channel</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_upper')])
                <input type="text" name="color_upper" value="{{ old('color_upper', $p['color_upper']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_upper')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_lower" value="1" class="rounded border-zinc-600" @checked(old('show_lower', $p['show_lower'] ? '1' : '') === '1' || (old('show_lower') === null && $p['show_lower']))> Lower channel</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_lower')])
                <input type="text" name="color_lower" value="{{ old('color_lower', $p['color_lower']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_lower')])
            </div>
            <div>
                <label class="block text-xs text-zinc-400">Line width</label>
                <input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
