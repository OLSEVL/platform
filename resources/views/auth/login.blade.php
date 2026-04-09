<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center antialiased">
    <div class="bg-zinc-900 border border-zinc-800 p-8 rounded-lg shadow-xl shadow-black/40 max-w-md w-full mx-4">
        <h1 class="text-2xl font-bold mb-6 text-zinc-100">Вход</h1>
        @if($errors->any())
            <ul class="mb-4 text-red-400 text-sm">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-zinc-300">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent" required autofocus>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1 text-zinc-300">Пароль</label>
                <input type="password" name="password" class="w-full px-3 py-2 rounded bg-zinc-950 border border-zinc-600 text-zinc-100 focus:ring-2 focus:ring-blue-600 focus:border-transparent" required>
            </div>
            <div class="mb-4">
                <label class="text-zinc-300"><input type="checkbox" name="remember" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"> Запомнить</label>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-500">Войти</button>
        </form>
        <p class="mt-4 text-sm text-zinc-400"><a href="{{ route('register') }}" class="text-blue-400 hover:text-blue-300">Регистрация</a></p>
    </div>
</body>
</html>
