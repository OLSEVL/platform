@extends('layouts.app')
@section('title', $bot->name.' — настройки')
@section('content')
@php
    $cfg = is_array($bot->config) ? $bot->config : [];
@endphp
<div class="max-w-2xl space-y-6">
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
                <p class="text-sm text-zinc-400">Feshan: мультитаймфрейм (4H/1H/30M/15M/5M + M1), имбаланс/поглощение по эвристикам движка. Экспирация по умолчанию 300 с (5 мин). Больше M1 в выборке — стабильнее агрегаты старших ТФ.</p>

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

                {{-- Триггеры Feshan: можно включать/выключать отдельные условия входа прямо из UI. --}}
                <div class="space-y-2 border-t border-zinc-800 pt-4">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">Триггеры входа Feshan</div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_bias_240" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_bias_240', $cfg['trigger_bias_240'] ?? true))>
                            4H bias (глобальный тренд)
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_bias_60" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_bias_60', $cfg['trigger_bias_60'] ?? true))>
                            1H bias (фильтр направления)
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_h1_candle" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_h1_candle', $cfg['trigger_h1_candle'] ?? true))>
                            Направление последней 1H свечи
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_m5_align" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_m5_align', $cfg['trigger_m5_align'] ?? true))>
                            5M alignment (2 из 3)
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_m1_candle" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_m1_candle', $cfg['trigger_m1_candle'] ?? true))>
                            Направление последней M1 свечи
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="trigger_absorption" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_absorption', $cfg['trigger_absorption'] ?? false))>
                            Поглощение (тень M1)
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-300 sm:col-span-2">
                            <input type="checkbox" name="trigger_imbalance" value="1" class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('trigger_imbalance', $cfg['trigger_imbalance'] ?? false))>
                            Имбаланс 5M (FVG)
                        </label>
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
