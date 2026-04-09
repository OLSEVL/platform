@extends('layouts.app')

@section('title', 'Настройки')

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-zinc-100 mb-6">Настройки</h1>

    @if(session('success'))
        <p class="mb-4 p-3 bg-emerald-950/60 border border-emerald-800 text-emerald-200 rounded">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="mb-4 p-3 bg-red-950/60 border border-red-800 text-red-200 rounded">{{ session('error') }}</p>
    @endif

    <div class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-5">
        <h2 class="text-lg font-semibold text-zinc-100 mb-2">Прокси Webshare</h2>
        <p class="text-sm text-zinc-400 mb-4">
            Кнопка загружает прокси из Webshare API и назначает их всем активным инструментам.
            Если прокси меньше, чем активных инструментов, назначение идёт по кругу.
        </p>
        <form action="{{ route('settings.proxies.refresh') }}" method="POST">
            @csrf
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 text-white font-medium">
                Обновить прокси
            </button>
        </form>
    </div>
</div>
@endsection
