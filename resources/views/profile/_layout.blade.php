@extends('layouts.app')
@section('main_class', 'w-full max-w-none flex-1 min-h-0 overflow-y-auto py-4 px-1 sm:px-2')
@section('content')
<div class="w-full max-w-none mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-100">Профиль пользователя</h1>
        <p class="mt-1 text-sm text-zinc-400">Настройки аккаунта и зеркалирование сделок ботов на Pocket Option.</p>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row">
        <aside class="sm:w-36 shrink-0">
            <nav class="rounded border border-zinc-800 bg-zinc-900/60 p-2 space-y-1 text-sm">
                <a href="{{ route('profile.trading.edit') }}"
                    class="block rounded px-3 py-2 transition-colors {{ request()->routeIs('profile.trading.edit') || request()->routeIs('profile.trading.update') || request()->routeIs('profile.trading.test-connection') ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-300 hover:bg-zinc-800/80 hover:text-white' }}">
                    Торговля
                </a>
                <a href="{{ route('profile.mirror-queue.index') }}"
                    class="block rounded px-3 py-2 transition-colors {{ request()->routeIs('profile.mirror-queue.*') ? 'bg-zinc-800 text-white font-medium' : 'text-zinc-300 hover:bg-zinc-800/80 hover:text-white' }}">
                    Очередь PO
                </a>
            </nav>
        </aside>
        <div class="flex-1 min-w-0">
            @if (session('status'))
                <div class="mb-4 rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif
            @yield('profile_content')
        </div>
    </div>
</div>
@endsection
