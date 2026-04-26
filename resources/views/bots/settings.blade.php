@extends('layouts.app')
@section('title', $bot->name.' — настройки')
@section('content')
@php
    $cfg = is_array($bot->config) ? $bot->config : [];
@endphp
<div class="mx-auto w-full max-w-2xl space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-100">Настройки бота</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $bot->name }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">График</a>
            <a href="{{ route('bots.activity', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Сигналы и сделки</a>
            <a href="{{ route('bots.index') }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">К списку</a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="{{ route('bots.settings.update', $bot) }}" class="space-y-6 rounded border border-zinc-800 bg-zinc-950/40 p-6">
        @csrf
        @method('PUT')

        <div class="space-y-2">
            <label for="name" class="block text-sm font-medium text-zinc-300">Имя бота</label>
            <input type="text" name="name" id="name" value="{{ old('name', $bot->name) }}" required maxlength="255"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
            @error('name')
                <p class="text-sm text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" name="is_active" id="is_active" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('is_active', $bot->is_active))>
            <label for="is_active" class="text-sm text-zinc-300">Бот активен (участвует в плановом сканировании)</label>
        </div>

        <div class="flex items-start gap-2">
            {{-- Эта настройка управляет UX графика:
                 когда включена, на странице графика работает tradingview-подобный кроссхейр
                 (вертикальная/горизонтальная линии + подписи времени и цены под курсором).
                 По умолчанию флаг включен для новых/старых ботов, если ключ еще не задан в config. --}}
            <input type="checkbox" name="tv_crosshair_enabled" id="tv_crosshair_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('tv_crosshair_enabled', $cfg['tv_crosshair_enabled'] ?? true))>
            <label for="tv_crosshair_enabled" class="text-sm text-zinc-300">
                Кроссхейр как в TradingView (линии X/Y и подсказки цены/времени)
            </label>
        </div>

        <div class="space-y-1">
            <span class="block text-sm font-medium text-zinc-300">Тип стратегии</span>
            <p class="text-sm text-zinc-500 font-mono">{{ $bot->type }}</p>
        </div>

        @if ($bot->type === 'three_candles_in_row')
            <div class="space-y-4 border-t border-zinc-800 pt-4">
                <p class="text-sm text-zinc-400">Стратегия: три закрытые свечи подряд в одну сторону → сделка в противоположную на время экспирации (по умолчанию 60 с = 1 минута M1).</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек), для минутной свечи обычно 60</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.55) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

            </div>
        @elseif ($bot->type === 'feshan')
            <div class="space-y-4 border-t border-zinc-800 pt-4">
                <p class="text-sm text-zinc-400">Feshan v2: вход только по снятию ликвидности (liquidity sweep) на M1. Сделка открывается после ложного прокола локального high/low и возврата цены в диапазон.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 2500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек), основная по ТЗ 300 (5 мин)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="liquidity_lookback" class="block text-xs font-medium text-zinc-400">Окно liquidity sweep (M1 свечей)</label>
                        <input type="number" name="liquidity_lookback" id="liquidity_lookback" min="5" max="50" required
                            value="{{ old('liquidity_lookback', $cfg['liquidity_lookback'] ?? 20) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('liquidity_lookback')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="sweep_min_wick_ratio" class="block text-xs font-medium text-zinc-400">Мин. тень sweep (0.05–0.8)</label>
                        <input type="number" name="sweep_min_wick_ratio" id="sweep_min_wick_ratio" min="0.05" max="0.8" step="0.01" required
                            value="{{ old('sweep_min_wick_ratio', $cfg['sweep_min_wick_ratio'] ?? 0.35) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('sweep_min_wick_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="sweep_min_reclaim_ratio" class="block text-xs font-medium text-zinc-400">Мин. глубина возврата (0.05–0.8)</label>
                        <input type="number" name="sweep_min_reclaim_ratio" id="sweep_min_reclaim_ratio" min="0.05" max="0.8" step="0.01" required
                            value="{{ old('sweep_min_reclaim_ratio', $cfg['sweep_min_reclaim_ratio'] ?? 0.12) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('sweep_min_reclaim_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="entry_extrema_distance" class="block text-xs font-medium text-zinc-400">Близость к экстремуму (0.02–0.4)</label>
                        <input type="number" name="entry_extrema_distance" id="entry_extrema_distance" min="0.02" max="0.4" step="0.01" required
                            value="{{ old('entry_extrema_distance', $cfg['entry_extrema_distance'] ?? 0.12) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('entry_extrema_distance')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        @else
            <div class="space-y-2 border-t border-zinc-800 pt-4">
                <label for="config_json" class="block text-sm font-medium text-zinc-300">Конфигурация (JSON)</label>
                <textarea name="config_json" id="config_json" rows="14" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('config_json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') }}</textarea>
                @error('config_json')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="flex items-center gap-3 border-t border-zinc-800 pt-4">
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-400">
                Сохранить
            </button>
            <a href="{{ route('bots.show', $bot) }}" class="text-sm text-zinc-500 hover:text-zinc-300">Отмена</a>
        </div>
    </form>
</div>
@endsection
