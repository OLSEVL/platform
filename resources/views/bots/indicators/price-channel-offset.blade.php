@extends('layouts.app')
@section('title', $bot->name.' — PC Offset')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · PC Offset</p>
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
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">PC Offset</h2></div>
        <form method="post" action="{{ route('bots.indicators.price-channel-offset.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">length</label><input type="number" name="length" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('length')])</div>
            <div><label class="block text-xs text-zinc-400">offset_percent</label><input type="number" name="offset_percent" step="0.01" required value="{{ old('offset_percent', $p['offset_percent']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('offset_percent')])</div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_up" value="1" class="rounded border-zinc-600" @checked(old('show_up', $p['show_up'] ? '1' : '') === '1' || (old('show_up') === null && $p['show_up']))> Up</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_up')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_down" value="1" class="rounded border-zinc-600" @checked(old('show_down', $p['show_down'] ? '1' : '') === '1' || (old('show_down') === null && $p['show_down']))> Down</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_down')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_up_border" value="1" class="rounded border-zinc-600" @checked(old('show_up_border', $p['show_up_border'] ? '1' : '') === '1' || (old('show_up_border') === null && $p['show_up_border']))> Up Border</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_up_border')])
            <label class="flex items-center gap-2"><input type="checkbox" name="show_down_border" value="1" class="rounded border-zinc-600" @checked(old('show_down_border', $p['show_down_border'] ? '1' : '') === '1' || (old('show_down_border') === null && $p['show_down_border']))> Down Border</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_down_border')])
            <div><label class="block text-xs text-zinc-400">color_up</label><input type="text" name="color_up" value="{{ old('color_up', $p['color_up']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_up')])</div>
            <div><label class="block text-xs text-zinc-400">color_down</label><input type="text" name="color_down" value="{{ old('color_down', $p['color_down']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_down')])</div>
            <div><label class="block text-xs text-zinc-400">color_up_border</label><input type="text" name="color_up_border" value="{{ old('color_up_border', $p['color_up_border']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_up_border')])</div>
            <div><label class="block text-xs text-zinc-400">color_down_border</label><input type="text" name="color_down_border" value="{{ old('color_down_border', $p['color_down_border']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_down_border')])</div>
            <div><label class="block text-xs text-zinc-400">line_width</label><input type="number" name="line_width" required value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('line_width')])</div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
