@extends('layouts.app')
@section('title', $bot->name.' — Parabolic BB')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Parabolic BB (OsEngine)</p>
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
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Parabolic BB</h2></div>
        <form method="post" action="{{ route('bots.indicators.parabolic-bollinger.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">length</label><input type="number" name="length" required value="{{ old('length', $p['length']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">deviation</label><input type="number" name="deviation" step="0.01" required value="{{ old('deviation', $p['deviation']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">volatility_period</label><input type="text" name="volatility_period" required value="{{ old('volatility_period', $p['volatility_period']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">averaging_period</label><input type="number" name="averaging_period" required value="{{ old('averaging_period', $p['averaging_period']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">volatility_multi</label><input type="number" name="volatility_multi" step="0.01" required value="{{ old('volatility_multi', $p['volatility_multi']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_up" value="1" class="rounded border-zinc-600" @checked(old('show_up', $p['show_up'] ? '1' : '') === '1' || (old('show_up') === null && $p['show_up']))> Up</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_down" value="1" class="rounded border-zinc-600" @checked(old('show_down', $p['show_down'] ? '1' : '') === '1' || (old('show_down') === null && $p['show_down']))> Down</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_parabolic" value="1" class="rounded border-zinc-600" @checked(old('show_parabolic', $p['show_parabolic'] ? '1' : '') === '1' || (old('show_parabolic') === null && $p['show_parabolic']))> Parabolic</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_volatility" value="1" class="rounded border-zinc-600" @checked(old('show_volatility', $p['show_volatility'] ? '1' : '') === '1' || (old('show_volatility') === null && $p['show_volatility']))> Volatility</label>
            <div><label class="block text-xs text-zinc-400">color_up</label><input type="text" name="color_up" value="{{ old('color_up', $p['color_up']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">color_down</label><input type="text" name="color_down" value="{{ old('color_down', $p['color_down']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">color_parabolic</label><input type="text" name="color_parabolic" value="{{ old('color_parabolic', $p['color_parabolic']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">color_volatility</label><input type="text" name="color_volatility" value="{{ old('color_volatility', $p['color_volatility']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">line_width</label><input type="number" name="line_width" required value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">point_radius</label><input type="number" name="point_radius" required value="{{ old('point_radius', $p['point_radius']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
