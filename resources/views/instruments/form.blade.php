@extends('layouts.app')
@section('title', $instrument ? 'Редактировать' : 'Добавить')
@section('content')
<h1 class="text-2xl font-bold mb-6 text-zinc-100">{{ $instrument ? 'Редактировать' : 'Добавить' }} инструмент</h1>
@if($errors->any())
    <ul class="mb-4 p-3 bg-red-950/60 border border-red-900 text-red-200 rounded list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
<form method="POST" action="{{ $instrument ? route('instruments.update', $instrument) : route('instruments.store') }}" class="space-y-6">
    @csrf
    @if($instrument) @method('PUT') @endif
    <section class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-6 max-w-xl">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Инструмент (код) *</label>
                <input type="text" name="instrument" value="{{ old('instrument', $instrument?->instrument) }}" placeholder="EURUSD_otc" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 placeholder-zinc-500 focus:ring-2 focus:ring-blue-600 focus:border-transparent" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Название</label>
                <input type="text" name="display_name" value="{{ old('display_name', $instrument?->display_name) }}" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Тип *</label>
                <select name="type" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent" required>
                    @php $typeVal = (int) old('type', $instrument?->type ?? \App\Models\PocketInstrument::TYPE_CURRENCY); @endphp
                    <option value="{{ \App\Models\PocketInstrument::TYPE_CURRENCY }}" @selected($typeVal === \App\Models\PocketInstrument::TYPE_CURRENCY)>1 — Валютные пары</option>
                    <option value="{{ \App\Models\PocketInstrument::TYPE_CRYPTO }}" @selected($typeVal === \App\Models\PocketInstrument::TYPE_CRYPTO)>2 — Криптовалюты</option>
                    <option value="{{ \App\Models\PocketInstrument::TYPE_STOCK }}" @selected($typeVal === \App\Models\PocketInstrument::TYPE_STOCK)>3 — Акции</option>
                    <option value="{{ \App\Models\PocketInstrument::TYPE_COMMODITY }}" @selected($typeVal === \App\Models\PocketInstrument::TYPE_COMMODITY)>4 — Сырьевые товары</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Приоритет</label>
                <input type="number" name="priority" value="{{ old('priority', $instrument?->priority ?? 0) }}" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent">
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $instrument?->is_active ?? true) ? 'checked' : '' }} class="rounded border-zinc-600 bg-zinc-800 text-blue-500">
                <label for="is_active" class="text-sm text-zinc-300">Включён</label>
            </div>
        </div>
    </section>
    <section class="bg-zinc-900 border border-zinc-800 rounded-lg shadow-lg shadow-black/20 p-6 max-w-xl">
        <h2 class="text-lg font-semibold mb-2 text-zinc-100">Auth для пары (опционально)</h2>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Session token</label>
                <input type="password" name="session_token" placeholder="{{ $session_token_placeholder ?? '' }}" class="w-full px-3 py-2 rounded border border-zinc-600 bg-zinc-950 font-mono text-sm text-zinc-100 placeholder-zinc-500 focus:ring-2 focus:ring-blue-600 focus:border-transparent" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">Прокси (из пула)</label>
                {{-- Старое поле proxy_url удалено: выбираем endpoint из общего пула proxy_endpoints. --}}
                <select name="proxy_endpoint_id" class="w-full px-3 py-2 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent">
                    <option value="">Авто/не назначен</option>
                    @foreach(($proxyEndpoints ?? collect()) as $proxy)
                        <option value="{{ $proxy->id }}" {{ (string) old('proxy_endpoint_id', $instrument?->proxy_endpoint_id) === (string) $proxy->id ? 'selected' : '' }}>
                            [{{ $proxy->status }}] {{ $proxy->url }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-300 mb-1">UID</label>
                <input type="text" name="uid" value="{{ old('uid', $instrument?->uid) }}" class="w-full px-3 py-2 rounded border border-zinc-600 bg-zinc-950 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent" autocomplete="off">
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_demo" id="is_demo" value="1" {{ old('is_demo', $instrument?->is_demo) ? 'checked' : '' }} class="rounded border-zinc-600 bg-zinc-800 text-blue-500">
                <label for="is_demo" class="text-sm text-zinc-300">Демо</label>
            </div>
        </div>
    </section>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-500">Сохранить</button>
    <a href="{{ route('instruments.index') }}" class="ml-2 bg-zinc-800 text-zinc-200 px-4 py-2 rounded border border-zinc-600 hover:bg-zinc-700">Отмена</a>
</form>
@endsection
