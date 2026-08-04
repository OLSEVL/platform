@extends('layouts.app')
@section('title', $bot->name.' — ADX')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · ADX — отдельная pane</p>
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
            <h2 class="text-base font-semibold text-white">ADX / +DI / −DI</h2>
        </div>
        <form method="post" action="{{ route('bots.indicators.adx.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs text-zinc-400">Length</label>
                <input type="number" name="length" min="1" max="500" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])
            </div>
            <div class="space-y-2 rounded border border-zinc-700/60 p-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_adx" value="1" class="rounded border-zinc-600" @checked(old('show_adx', $p['show_adx'] ? '1' : '') === '1' || (old('show_adx') === null && $p['show_adx']))> ADX</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_adx')])
                <input type="text" name="color_adx" value="{{ old('color_adx', $p['color_adx']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_adx')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_plus" value="1" class="rounded border-zinc-600" @checked(old('show_plus', $p['show_plus'] ? '1' : '') === '1' || (old('show_plus') === null && $p['show_plus']))> +DI</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_plus')])
                <input type="text" name="color_plus" value="{{ old('color_plus', $p['color_plus']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_plus')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_minus" value="1" class="rounded border-zinc-600" @checked(old('show_minus', $p['show_minus'] ? '1' : '') === '1' || (old('show_minus') === null && $p['show_minus']))> −DI</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_minus')])
                <input type="text" name="color_minus" value="{{ old('color_minus', $p['color_minus']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_minus')])
            </div>
            <div>
                <label class="block text-xs text-zinc-400">Line width</label>
                <input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])
            </div>
            <div class="rounded border border-zinc-700/60 p-3 space-y-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_levels" value="1" class="rounded border-zinc-600" @checked(old('show_levels', $p['show_levels'] ? '1' : '') === '1' || (old('show_levels') === null && $p['show_levels']))> Levels 20 / 25</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_levels')])
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs text-zinc-400">Weak (20)</label>
                        <input type="number" name="level_weak" step="any" value="{{ old('level_weak', $p['level_weak']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('level_weak')])
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400">Strong (25)</label>
                        <input type="number" name="level_strong" step="any" value="{{ old('level_strong', $p['level_strong']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('level_strong')])
                    </div>
                </div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
