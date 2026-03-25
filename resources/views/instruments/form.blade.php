@extends('layouts.app')
@section('title', $instrument ? 'Редактировать' : 'Добавить')
@section('content')
<h1 class="text-2xl font-bold mb-6">{{ $instrument ? 'Редактировать' : 'Добавить' }} инструмент</h1>
@if($errors->any())
    <ul class="mb-4 p-3 bg-red-100 text-red-800 rounded list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
<form method="POST" action="{{ $instrument ? route('instruments.update', $instrument) : route('instruments.store') }}" class="space-y-6">
    @csrf
    @if($instrument) @method('PUT') @endif
    <section class="bg-white rounded-lg shadow p-6 max-w-xl">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Инструмент (код) *</label>
                <input type="text" name="instrument" value="{{ old('instrument', $instrument?->instrument) }}" placeholder="EURUSD_otc" class="w-full px-3 py-2 border rounded" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Название</label>
                <input type="text" name="display_name" value="{{ old('display_name', $instrument?->display_name) }}" class="w-full px-3 py-2 border rounded">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Приоритет</label>
                <input type="number" name="priority" value="{{ old('priority', $instrument?->priority ?? 0) }}" class="w-full px-3 py-2 border rounded">
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $instrument?->is_active ?? true) ? 'checked' : '' }} class="rounded">
                <label for="is_active" class="text-sm">Включён</label>
            </div>
        </div>
    </section>
    <section class="bg-white rounded-lg shadow p-6 max-w-xl">
        <h2 class="text-lg font-semibold mb-2">Auth для пары (опционально)</h2>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Session token</label>
                <input type="password" name="session_token" placeholder="{{ $session_token_placeholder ?? '' }}" class="w-full px-3 py-2 border rounded font-mono text-sm" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Proxy URL</label>
                <input type="text" name="proxy_url" value="{{ old('proxy_url', $instrument?->proxy_url) }}" placeholder="http://user:pass@127.0.0.1:8080" class="w-full px-3 py-2 border rounded font-mono text-sm" autocomplete="off">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">UID</label>
                <input type="text" name="uid" value="{{ old('uid', $instrument?->uid) }}" class="w-full px-3 py-2 border rounded" autocomplete="off">
            </div>
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_demo" id="is_demo" value="1" {{ old('is_demo', $instrument?->is_demo) ? 'checked' : '' }} class="rounded">
                <label for="is_demo" class="text-sm">Демо</label>
            </div>
        </div>
    </section>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Сохранить</button>
    <a href="{{ route('instruments.index') }}" class="ml-2 bg-gray-200 text-gray-800 px-4 py-2 rounded hover:bg-gray-300">Отмена</a>
</form>
@endsection
