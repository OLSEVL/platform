@extends('layouts.app')
@section('title', $bot->name.' — Keltner')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Keltner Channel</p>
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
            <h2 class="text-base font-semibold text-white">Keltner Channel</h2>
            <p class="text-[11px] text-zinc-500 mt-0.5">EMA ± ATR×множитель (сглаженный ATR)</p>
        </div>
        <form method="post" action="{{ route('bots.indicators.keltner.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs text-zinc-400">EMA Period</label>
                    <input type="number" name="ema_period" min="1" max="500" required value="{{ old('ema_period', $p['ema_period']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('ema_period')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">ATR Period</label>
                    <input type="number" name="atr_period" min="1" max="500" required value="{{ old('atr_period', $p['atr_period']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('atr_period')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">ATR EMA Period</label>
                    <input type="number" name="atr_ema_period" min="1" max="500" required value="{{ old('atr_ema_period', $p['atr_ema_period']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('atr_ema_period')])
                </div>
                <div>
                    <label class="block text-xs text-zinc-400">ATR multiplier</label>
                    <input type="number" name="atr_coef" step="any" min="0.01" max="50" required value="{{ old('atr_coef', $p['atr_coef']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('atr_coef')])
                </div>
            </div>
            <div class="space-y-2 rounded border border-zinc-700/60 p-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_up" value="1" class="rounded border-zinc-600" @checked(old('show_up', $p['show_up'] ? '1' : '') === '1' || (old('show_up') === null && $p['show_up']))> Up Channel</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_up')])
                <input type="text" name="color_up" value="{{ old('color_up', $p['color_up']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_up')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_mid" value="1" class="rounded border-zinc-600" @checked(old('show_mid', $p['show_mid'] ? '1' : '') === '1' || (old('show_mid') === null && $p['show_mid']))> Middle EMA</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_mid')])
                <input type="text" name="color_mid" value="{{ old('color_mid', $p['color_mid']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_mid')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_down" value="1" class="rounded border-zinc-600" @checked(old('show_down', $p['show_down'] ? '1' : '') === '1' || (old('show_down') === null && $p['show_down']))> Down Channel</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_down')])
                <input type="text" name="color_down" value="{{ old('color_down', $p['color_down']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_down')])
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
