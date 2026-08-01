@extends('layouts.app')
@section('title', $bot->name.' — Vol Average')
@section('content')
<div class="mx-auto w-full max-w-2xl space-y-4 pb-8">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-zinc-100 sm:text-2xl">Индикатор графика</h1>
            <p class="mt-0.5 text-sm text-zinc-500">{{ $bot->name }} · Vol Average (OsEngine)</p>
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
        <div class="border-b border-zinc-700 bg-[#1e2028] px-4 py-3"><h2 class="text-base font-semibold text-white">Vol Average</h2></div>
        <form method="post" action="{{ route('bots.indicators.volatility-average.update', $bot) }}" class="space-y-4 px-4 py-4 text-sm text-zinc-200">
            @csrf
            @method('PUT')
            <div><label class="block text-xs text-zinc-400">volatility_base_type</label><input type="text" name="volatility_base_type" required value="{{ old('volatility_base_type', $p['volatility_base_type']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">volatility_variable_type</label><input type="text" name="volatility_variable_type" required value="{{ old('volatility_variable_type', $p['volatility_variable_type']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <div><label class="block text-xs text-zinc-400">sma_len</label><input type="number" name="sma_len" required value="{{ old('sma_len', $p['sma_len']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_volatility" value="1" class="rounded border-zinc-600" @checked(old('show_volatility', $p['show_volatility'] ? '1' : '') === '1' || (old('show_volatility') === null && $p['show_volatility']))> Volatility</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_sma" value="1" class="rounded border-zinc-600" @checked(old('show_sma', $p['show_sma'] ? '1' : '') === '1' || (old('show_sma') === null && $p['show_sma']))> Sma</label>
            <div><label class="block text-xs text-zinc-400">color_volatility</label><input type="text" name="color_volatility" value="{{ old('color_volatility', $p['color_volatility']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">color_sma</label><input type="text" name="color_sma" value="{{ old('color_sma', $p['color_sma']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-2 py-1 text-xs"></div>
            <div><label class="block text-xs text-zinc-400">line_width</label><input type="number" name="line_width" required value="{{ old('line_width', $p['line_width']) }}" class="mt-1 w-full max-w-xs rounded border border-zinc-600 bg-zinc-900 px-3 py-2"></div>
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">Сохранить</button>
        </form>
    </div>
</div>
@endsection
