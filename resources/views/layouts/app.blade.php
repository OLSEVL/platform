<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Платформа')</title>
    @include('partials.platform-timezone')
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body @class([
    'bg-[#1c202e] text-slate-100 h-dvh min-h-dvh flex flex-col antialiased',
    'overflow-hidden' => ! request()->boolean('embed'),
    'overflow-y-auto' => request()->boolean('embed'),
]) @if (request()->boolean('embed')) data-indicator-settings-embed="1" @endif>
    {{-- Режим embed используется единым модальным окном настроек индикаторов.
         Скрываем только глобальную навигацию: сами формы остаются теми же, с
         их CSRF-токенами, правилами валидации и страницей ошибок Laravel. --}}
    <nav @class([
        'bg-[#23283a] border-b border-[#31384e] shadow-lg shadow-black/20 shrink-0',
        'hidden' => request()->boolean('embed'),
    ])>
        {{-- Три колонки flex-1 / auto / flex-1: пункты меню по центру экрана, «Выход» у правого края. --}}
        <div class="w-full px-4 py-2 flex items-center">
            <div class="flex-1" aria-hidden="true"></div>
            <div class="flex items-center justify-center gap-6 flex-wrap">
                <a href="{{ route('dashboard') }}" class="text-slate-200 hover:text-white transition-colors">Главная</a>
                <a href="{{ route('bots.index') }}" class="text-slate-200 hover:text-white transition-colors">Обзор ботов</a>
                <a href="{{ route('bots.analytics') }}" class="text-slate-200 hover:text-white transition-colors">Аналитика ботов</a>
                <a href="{{ route('bots.live-trades') }}" class="text-slate-200 hover:text-white transition-colors">Лента сделок</a>
                <a href="{{ route('instruments.index') }}" class="text-slate-200 hover:text-white transition-colors">Инструменты</a>
                <a href="{{ route('settings.index') }}" class="text-slate-200 hover:text-white transition-colors">Настройки</a>
                <a href="{{ route('profile.trading.edit') }}" class="text-slate-200 hover:text-white transition-colors">Профиль</a>
                <a href="{{ route('profile.mirror-queue.index') }}" class="text-slate-200 hover:text-white transition-colors">Очередь PO</a>
            </div>
            <div class="flex-1 flex items-center justify-end">
                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="text-slate-400 hover:text-slate-200 text-sm transition-colors">Выход</button>
                </form>
            </div>
        </div>
    </nav>
    {{-- Базовый контейнер контента: во всю ширину экрана, с небольшими боковыми отступами для читаемости. --}}
    {{-- Обычная страница обязана сохранить свой @section('main_class'):
         графики задают здесь flex/overflow, от которых зависит высота canvas.
         Только iframe-настройки получают компактный независимый контейнер. --}}
    <main class="{{ request()->boolean('embed')
        ? 'w-full max-w-none flex-1 min-h-0 overflow-y-auto py-4 px-3 sm:px-4'
        : trim($__env->yieldContent('main_class', 'w-full max-w-none flex-1 min-h-0 overflow-y-auto py-8 px-3 sm:px-4')) }}">
        @yield('content')
    </main>
    @include('partials.server-clock-helper')
</body>
</html>
