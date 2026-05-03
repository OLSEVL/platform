@extends('layouts.app')
@section('title', $bot->name.' — имбалансы (FVG)')
@section('content')
<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-100">Индикатор: имбалансы (FVG)</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $bot->name }}</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>

    <div class="rounded border border-zinc-800 bg-zinc-950/40 p-6 space-y-3 text-sm text-zinc-400">
        <p>
            Имбалансы (FVG) на графике бота приходят из того же расчёта структуры, что и ордерблоки.
        </p>
        <p>
            Персональных настроек для бота здесь пока нет; при появлении параметров они будут храниться отдельно
            (например в <code class="text-zinc-500">indicator_settings</code>).
        </p>
    </div>
</div>
@endsection
