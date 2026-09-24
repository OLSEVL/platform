@extends('layouts.app')
@section('title', $bot->name.' — Strat Assistant')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Strat Assistant — оверлей The Strat</p>
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
            <h2 class="text-base font-semibold text-white">Strat Assistant</h2>
            <p class="mt-0.5 text-[11px] text-zinc-500">Маркеры 1 / 2 / 3 и комбо 22R, 212C, 212R, 312, 32. Не торгует — только чтение баров после алерта бота.</p>
        </div>
        <form method="post" action="{{ route('bots.indicators.strat-assistant.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="space-y-2 rounded border border-zinc-700/60 p-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="show_types" value="1" class="rounded border-zinc-600" @checked(old('show_types', $p['show_types'] ? '1' : '') === '1' || (old('show_types') === null && $p['show_types']))> Типы баров (1 / 2 / 3)</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_types')])
                <label class="flex items-center gap-2"><input type="checkbox" name="show_combos" value="1" class="rounded border-zinc-600" @checked(old('show_combos', $p['show_combos'] ? '1' : '') === '1' || (old('show_combos') === null && $p['show_combos']))> Комбо (22R / 212 / 312 / 32)</label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('show_combos')])
                <label class="block text-xs text-zinc-400">Inside (1)</label>
                <input type="text" name="color_inside" value="{{ old('color_inside', $p['color_inside']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_inside')])
                <label class="block text-xs text-zinc-400">2 up</label>
                <input type="text" name="color_two_up" value="{{ old('color_two_up', $p['color_two_up']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_two_up')])
                <label class="block text-xs text-zinc-400">2 down</label>
                <input type="text" name="color_two_down" value="{{ old('color_two_down', $p['color_two_down']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_two_down')])
                <label class="block text-xs text-zinc-400">Outside (3)</label>
                <input type="text" name="color_outside" value="{{ old('color_outside', $p['color_outside']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_outside')])
                <label class="block text-xs text-zinc-400">Комбо вверх</label>
                <input type="text" name="color_combo_bull" value="{{ old('color_combo_bull', $p['color_combo_bull']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_combo_bull')])
                <label class="block text-xs text-zinc-400">Комбо вниз</label>
                <input type="text" name="color_combo_bear" value="{{ old('color_combo_bear', $p['color_combo_bear']) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs">
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::indicator('color_combo_bear')])
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
