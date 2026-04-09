<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Платформа')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-zinc-100 h-dvh min-h-dvh flex flex-col overflow-hidden antialiased">
    <nav class="bg-zinc-900 border-b border-zinc-800 shadow-lg shadow-black/20 shrink-0">
        <div class="max-w-7xl mx-auto px-4 py-2 flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="text-zinc-200 hover:text-white transition-colors">Главная</a>
            <a href="{{ route('instruments.index') }}" class="text-zinc-200 hover:text-white transition-colors">Инструменты</a>
            <a href="{{ route('settings.index') }}" class="text-zinc-200 hover:text-white transition-colors">Настройки</a>
            <form action="{{ route('logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit" class="text-zinc-400 hover:text-zinc-200 text-sm transition-colors">Выход</button>
            </form>
        </div>
    </nav>
    <main class="@yield('main_class', 'max-w-7xl mx-auto py-8 px-4 w-full flex-1 min-h-0 overflow-y-auto')">
        @yield('content')
    </main>
</body>
</html>
