<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow max-w-md w-full">
        <h1 class="text-2xl font-bold mb-6">Вход</h1>
        @if($errors->any())
            <ul class="mb-4 text-red-600 text-sm">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" class="w-full px-3 py-2 border rounded" required autofocus>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Пароль</label>
                <input type="password" name="password" class="w-full px-3 py-2 border rounded" required>
            </div>
            <div class="mb-4">
                <label><input type="checkbox" name="remember" class="rounded"> Запомнить</label>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">Войти</button>
        </form>
        <p class="mt-4 text-sm"><a href="{{ route('register') }}" class="text-blue-600">Регистрация</a></p>
    </div>
</body>
</html>
