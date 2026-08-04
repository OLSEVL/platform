@extends('layouts.app')
@section('title', $bot->name.' — Pivot Floor')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Pivot Floor — оверлей</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Pivot Floor</h2></div>
        <form method="post" action="{{ route('bots.indicators.pivot-floor.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs text-zinc-400">Period</label>
                <select name="period" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
@foreach (['Daily', 'Weekly'] as $per)
                        <option value="{{ $per }}" @selected(old('period', $p['period']) === $per)>{{ $per }}</option>
                    @endforeach
                </select>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('period')])
            </div>
            <div class="space-y-2 rounded border border-zinc-700/60 p-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_p" value="1" class="rounded border-zinc-600" @checked(old('show_p', $p['show_p'] ? '1' : '') === '1' || (old('show_p') === null && $p['show_p']))> Pivot (P)</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_p')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_r1" value="1" class="rounded border-zinc-600" @checked(old('show_r1', $p['show_r1'] ? '1' : '') === '1' || (old('show_r1') === null && $p['show_r1']))> R1</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_r1')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_r2" value="1" class="rounded border-zinc-600" @checked(old('show_r2', $p['show_r2'] ? '1' : '') === '1' || (old('show_r2') === null && $p['show_r2']))> R2</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_r2')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_r3" value="1" class="rounded border-zinc-600" @checked(old('show_r3', $p['show_r3'] ? '1' : '') === '1' || (old('show_r3') === null && $p['show_r3']))> R3</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_r3')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_s1" value="1" class="rounded border-zinc-600" @checked(old('show_s1', $p['show_s1'] ? '1' : '') === '1' || (old('show_s1') === null && $p['show_s1']))> S1</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_s1')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_s2" value="1" class="rounded border-zinc-600" @checked(old('show_s2', $p['show_s2'] ? '1' : '') === '1' || (old('show_s2') === null && $p['show_s2']))> S2</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_s2')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_s3" value="1" class="rounded border-zinc-600" @checked(old('show_s3', $p['show_s3'] ? '1' : '') === '1' || (old('show_s3') === null && $p['show_s3']))> S3</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_s3')])
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label class="block text-xs text-zinc-400">Color P</label><input type="text" name="color_p" value="{{ old('color_p', $p['color_p']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_p')])</div>
                <div><label class="block text-xs text-zinc-400">Color R</label><input type="text" name="color_r" value="{{ old('color_r', $p['color_r']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_r')])</div>
                <div><label class="block text-xs text-zinc-400">Color S</label><input type="text" name="color_s" value="{{ old('color_s', $p['color_s']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_s')])</div>
            </div>
            <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-24 rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])</div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
