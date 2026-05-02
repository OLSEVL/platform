@extends('layouts.app')
@section('title', $bot->name.' — настройки')
@section('content')
@php
    $cfg = is_array($bot->config) ? $bot->config : [];
    $openrouterModels = isset($openrouterModels) && is_array($openrouterModels) ? $openrouterModels : [];
    $selectedOpenRouterModel = old('model', $cfg['model'] ?? 'openai/gpt-5.2');
    // Отдельно выносим дефолтный prompt в переменную, чтобы не ломать Blade
    // сложной строкой с JSON-скобками внутри inline-выражения.
    $openrouterDefaultPrompt = <<<'PROMPT'
Ты торговый AI-ассистент для бинарных опционов.
Работаешь по MTF-логике: H1 -> M15 -> M5 -> M1.

Верни только JSON без markdown.
Формат:
{"side":"CALL|PUT|NONE","confidence":0.0,"analysis_text":"...","reasons_short":["..."],"features":{}}

Данные в запросе:
- closed_candles: M1
- mtf.h1_closed: H1
- mtf.m15_closed: M15
- mtf.m5_closed: M5

Обязательный алгоритм:
1) H1: определи bias = up | down | flat.
2) M15: определи структуру у уровня: support/resistance, retest/breakout/false_breakout/range.
3) M5: ищи подтверждение: rejection candle, breakout confirmation, возврат под/над уровень после ложного пробоя.
4) M1: только точка входа (тайминг), не источник основного bias.

Правила входа:
- CALL только если H1 bias up, M15 поддерживает long-сценарий и M5 дал подтверждение.
- PUT только если H1 bias down, M15 поддерживает short-сценарий и M5 дал подтверждение.
- Иначе side = "NONE".

Фильтры качества:
- Если H1 = flat и M15 = range без четкого края уровня -> NONE.
- Если M1 сигнал против H1+M15 -> NONE.
- Если подтверждений меньше 2 (на M15/M5) -> NONE.
- Если не уверен -> NONE и confidence <= 0.5.

Confidence:
- NONE: 0.0..0.5
- CALL/PUT: 0.55..1.0 только при подтвержденном MTF-согласовании.

Строгий формат explanation:
- analysis_text обязательно в виде:
  "H1: ...; M15: ...; M5: ...; M1: ...; setup: ..."
- reasons_short: 3-5 коротких пунктов, желательно в порядке H1 -> M15 -> M5 -> M1.

features (обязательно заполняй):
{
  "h1_bias":"up|down|flat",
  "m15_structure":"support|resistance|range|breakout|retest|false_breakout",
  "m5_trigger":"rejection|breakout_confirm|none",
  "entry_tf":"M1",
  "setup_type":"rejection|breakout_retest|false_breakout|none",
  "mtf_alignment_score": 0.0,
  "level_price": 0.0
}
PROMPT;
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
            <a href="{{ route('bots.logs', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Логи и метрики</a>
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
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
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
        @elseif ($bot->type === 'akim_trade_1m')
            <div class="space-y-4 border-t border-zinc-800 pt-4">
                <p class="text-sm text-zinc-400">AKIM TRADE 1M: самостоятельная стратегия с фильтром направления дня и входом от отката по M1, с пропуском мини-боковиков.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек), обычно 60</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.58) }}"
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
        @elseif ($bot->type === 'akim_trade_5s')
            <div class="space-y-4 border-t border-zinc-800 pt-4">
                <p class="text-sm text-zinc-400">AKIM TRADE 5S: самостоятельная секундная стратегия (S5) с быстрыми входами по локальному микро-тренду.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="50" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек), обычно 5</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="5" max="120" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 5) }}"
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
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="2" max="120" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 10) }}"
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
        @elseif ($bot->type === 'openrouter_llm')
            <div class="space-y-4 border-t border-zinc-800 pt-4">
                <p class="text-sm text-zinc-400">
                    OpenRouter LLM: полностью отдельный бот без алгоритмов прошлых стратегий.
                    Движок отправляет подготовленные свечи в модель и принимает решение по строго JSON-ответу.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 в выборке (count)</label>
                        <input type="number" name="count" id="count" min="20" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="candles_take" class="block text-xs font-medium text-zinc-400">Сколько последних свечей отправлять в LLM</label>
                        <input type="number" name="candles_take" id="candles_take" min="20" max="500" required
                            value="{{ old('candles_take', $cfg['candles_take'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('candles_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <div class="flex items-center gap-2">
                            {{-- Включает/выключает передачу старших ТФ в LLM:
                                 при включении модель получает M5/M15/H1 для фильтрации шума M1. --}}
                            <input type="checkbox"
                                   name="mtf_enabled"
                                   id="mtf_enabled"
                                   value="1"
                                   class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                   @checked(old('mtf_enabled', $cfg['mtf_enabled'] ?? true))>
                            <label for="mtf_enabled" class="text-xs font-medium text-zinc-300">
                                Использовать MTF-контекст (M5/M15/H1) для принятия решения
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">H1 баров для LLM</label>
                        <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 24) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                            </label>
                        </div>
                        @error('mtf_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">M5 баров для LLM</label>
                        <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 96) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">M15 баров для LLM</label>
                        <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 64) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.65) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 70) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="model" class="block text-xs font-medium text-zinc-400">Модель OpenRouter (provider/model)</label>
                        <select name="model" id="model"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100"
                            required>
                            @if ($openrouterModels === [])
                                {{-- Fallback: если API OpenRouter недоступен, не блокируем форму и оставляем текущее значение. --}}
                                <option value="{{ $selectedOpenRouterModel }}" selected>
                                    {{ $selectedOpenRouterModel }} (fallback)
                                </option>
                            @else
                                @php
                                    $hasSelectedInList = false;
                                @endphp
                                @foreach ($openrouterModels as $m)
                                    @php
                                        $modelId = (string) ($m['id'] ?? '');
                                        $modelName = (string) ($m['name'] ?? $modelId);
                                        if ($modelId === $selectedOpenRouterModel) {
                                            $hasSelectedInList = true;
                                        }
                                    @endphp
                                    @if ($modelId !== '')
                                        <option value="{{ $modelId }}" @selected($modelId === $selectedOpenRouterModel)>
                                            {{ $modelName }} — {{ $modelId }}
                                        </option>
                                    @endif
                                @endforeach
                                @if (! $hasSelectedInList && $selectedOpenRouterModel !== '')
                                    {{-- Если сохранённая модель уже исчезла из каталога, показываем её отдельным пунктом. --}}
                                    <option value="{{ $selectedOpenRouterModel }}" selected>
                                        {{ $selectedOpenRouterModel }} (custom)
                                    </option>
                                @endif
                            @endif
                        </select>
                        @if ($openrouterModels === [])
                            <p class="text-[11px] text-zinc-500">
                                Не удалось загрузить каталог моделей OpenRouter. Сохраняется текущее значение.
                            </p>
                        @endif
                        @error('model')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="temperature" class="block text-xs font-medium text-zinc-400">Temperature (0–2)</label>
                        <input type="number" name="temperature" id="temperature" min="0" max="2" step="0.01" required
                            value="{{ old('temperature', $cfg['temperature'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('temperature')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens" class="block text-xs font-medium text-zinc-400">Max tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" min="32" max="4096" required
                            value="{{ old('max_tokens', $cfg['max_tokens'] ?? 350) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('max_tokens')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="system_prompt" class="block text-xs font-medium text-zinc-400">System prompt</label>
                    <textarea name="system_prompt" id="system_prompt" rows="10" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt', $cfg['system_prompt'] ?? $openrouterDefaultPrompt) }}</textarea>
                    @error('system_prompt')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
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

    <form method="post"
          action="{{ route('bots.cleanup-recent', $bot) }}"
          class="inline-flex"
          onsubmit="return confirm('Удалить у этого бота все записи, которые старше 24 часов? Действие необратимо.');">
        @csrf
        {{-- Отдельная форма нужна, чтобы не конфликтовать с PUT-формой сохранения настроек.
             Кнопка запускает именно POST-очистку старых записей (trade/signal/llm logs). --}}
        <button type="submit"
                class="rounded bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400">
            Очистить всё старше 24 часов
        </button>
    </form>
</div>
@endsection
