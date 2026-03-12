<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Платформа')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-2 flex items-center gap-4">
            <a href="{{ route('dashboard') }}">Главная</a>
            <a href="{{ route('instruments.index') }}">Инструменты</a>
            <form action="{{ route('logout') }}" method="POST" class="inline">
                @csrf
                <button type="submit">Выход</button>
            </form>
        </div>
    </nav>
    <main class="max-w-7xl mx-auto py-8 px-4">
        @yield('content')
    </main>
</body>
</html>
