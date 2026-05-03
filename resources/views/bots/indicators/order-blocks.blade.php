@extends('layouts.app')
@section('title', $bot->name.' — ордерблоки')
@section('content')
<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-100">Индикатор: ордерблоки</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $bot->name }}</p>
        </div>
        @include('bots.indicators._nav', ['bot' => $bot])
    </div>

    <div class="rounded border border-zinc-800 bg-zinc-950/40 p-6 space-y-3 text-sm text-zinc-400">
        <p>
            Зоны ордерблоков на графике бота строятся тем же детектором, что и на дашборде
            (<code class="text-zinc-500">CandleStructureZoneDetector</code>, ответ эндпоинта структуры).
        </p>
        <p>
            Отдельные поля настроек для этого бота пока не подключены: при необходимости их можно добавить в
            <code class="text-zinc-500">indicator_settings</code> отдельным ключом и учесть в API структуры.
        </p>
    </div>
</div>
@endsection
