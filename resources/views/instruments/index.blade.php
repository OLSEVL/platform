@extends('layouts.app')
@section('title', 'Инструменты')
@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold">Инструменты</h1>
    <a href="{{ route('instruments.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Добавить</a>
</div>
@if(session('success'))
    <p class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('success') }}</p>
@endif
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Инструмент</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Название</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Приоритет</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Свои auth</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Действия</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($instruments as $i)
            <tr>
                <td class="px-6 py-4 font-mono">{{ $i->instrument }}</td>
                <td class="px-6 py-4">{{ $i->display_name ?? '—' }}</td>
                <td class="px-6 py-4">{{ $i->priority }}</td>
                <td class="px-6 py-4">{{ $i->is_active ? 'Вкл' : 'Выкл' }}</td>
                <td class="px-6 py-4">{{ $i->session_token ? 'Да' : 'Нет' }}</td>
                <td class="px-6 py-4 text-right">
                    <a href="{{ route('instruments.edit', $i) }}" class="text-gray-600 hover:underline mr-2">Изменить</a>
                    <form action="{{ route('instruments.destroy', $i) }}" method="POST" class="inline" onsubmit="return confirm('Удалить?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-red-600 hover:underline">Удалить</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">Нет инструментов. <a href="{{ route('instruments.create') }}" class="text-blue-600 hover:underline">Добавить</a></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
