@extends('layouts.app')
@section('title', $bot->name.' — Ichimoku')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Ichimoku (OsEngine) — оверлей на цене</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>
    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    <div class="overflow-hidden rounded-xl border border-zinc-600 bg-[#13151a]">
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Ichimoku</h2></div>
        <form method="post" action="{{ route('bots.indicators.ichimoku.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Tenkan Sen</label><input type="number" name="tenkan" min="1" max="500" required value="{{ old('tenkan', $p['tenkan']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Kijun Sen</label><input type="number" name="kijun" min="1" max="500" required value="{{ old('kijun', $p['kijun']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Senkou Span B</label><input type="number" name="senkou_b" min="1" max="500" required value="{{ old('senkou_b', $p['senkou_b']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Displacement</label><input type="number" name="displacement" min="0" max="500" required value="{{ old('displacement', $p['displacement']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Chinkou Span</label><input type="number" name="chinkou_span" min="0" max="500" required value="{{ old('chinkou_span', $p['chinkou_span']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            </div>
            @foreach (['tenkan','kijun','senkou_a','senkou_b','chinkou'] as $line)
                <label class="flex items-center gap-2"><input type="checkbox" name="show_{{ $line }}" value="1" class="rounded border-zinc-600" @checked(old('show_'.$line, $p['show_'.$line] ? '1' : '') === '1' || (old('show_'.$line) === null && $p['show_'.$line]))> {{ str_replace('_', ' ', ucfirst($line)) }}</label>
                <input type="text" name="color_{{ $line }}" value="{{ old('color_'.$line, $p['color_'.$line]) }}" class="w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs mb-2">
            @endforeach
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-xs text-zinc-400">Line width</label><input type="number" name="line_width" min="1" max="8" value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
                <div><label class="block text-xs text-zinc-400">Chinkou point radius</label><input type="number" name="chinkou_point_radius" min="1" max="8" value="{{ old('chinkou_point_radius', $p['chinkou_point_radius']) }}" class="mt-1 w-full rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            </div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
