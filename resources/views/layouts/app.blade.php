<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Платформа')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#1c202e] text-slate-100 h-dvh min-h-dvh flex flex-col overflow-hidden antialiased">
    <nav class="bg-[#23283a] border-b border-[#31384e] shadow-lg shadow-black/20 shrink-0">
        <div class="max-w-7xl mx-auto px-4 py-2 flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="text-slate-200 hover:text-white transition-colors">Главная</a>
            <a href="{{ route('bots.index') }}" class="text-slate-200 hover:text-white transition-colors">Обзор ботов</a>
            <a href="{{ route('bots.analytics') }}" class="text-slate-200 hover:text-white transition-colors">Аналитика ботов</a>
            <a href="{{ route('bots.live-trades') }}" class="text-slate-200 hover:text-white transition-colors">Лента сделок</a>
            <a href="{{ route('instruments.index') }}" class="text-slate-200 hover:text-white transition-colors">Инструменты</a>
            <a href="{{ route('settings.index') }}" class="text-slate-200 hover:text-white transition-colors">Настройки</a>
            <form action="{{ route('logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="text-slate-400 hover:text-slate-200 text-sm transition-colors">Выход</button>
            </form>
        </div>
    </nav>
    {{-- Базовый контейнер контента: во всю ширину экрана, с небольшими боковыми отступами для читаемости. --}}
    <main class="@yield('main_class', 'w-full max-w-none flex-1 min-h-0 overflow-y-auto py-8 px-3 sm:px-4')">
        @yield('content')
    </main>
</body>
</html>
