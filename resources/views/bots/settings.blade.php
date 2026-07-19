@extends('layouts.app')
@section('title', $bot->name.' — настройки')
@section('content')
@php
    $cfg = is_array($bot->config) ? $bot->config : [];
    // price_action_dgt задаётся на отдельной странице индикатора (indicator_settings), в общий JSON не включаем.
    $configForJsonField = $cfg;
    unset($configForJsonField['price_action_dgt']);
    $openrouterModels = isset($openrouterModels) && is_array($openrouterModels) ? $openrouterModels : [];
    $selectedOpenRouterModel = old('model', $cfg['model'] ?? 'openai/gpt-5.2');
    // Отдельно выносим дефолтный prompt в переменную, чтобы не ломать Blade
    // сложной строкой с JSON-скобками внутри inline-выражения.
    $openrouterDefaultPrompt = \App\Support\OpenRouterLlmConfig::defaultTradingSystemPrompt();
    $openrouterAnalystDefaultPrompt = \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfg);
    $openrouterAnalystShortDefaultPrompt = \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfg);
    $analystPromptMode = old('analyst_prompt_mode', $cfg['analyst_prompt_mode'] ?? \App\Support\OpenRouterLlmConfig::ANALYST_PROMPT_MODE_FULL);
    $isChartAnalystBot = $bot->type === 'openrouter_llm_analyst';
    $analystSmcFlowEnabled = $isChartAnalystBot && \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($cfg);
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
            <a href="{{ route('bots.indicators.order-blocks.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ордерблоки</a>
            <a href="{{ route('bots.indicators.fvg.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Имбалансы</a>
            <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Price Action</a>
            <a href="{{ route('bots.indicators.liquidity-swings.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Liquidity Swings</a>
            <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMC Flow</a>
            @if(in_array($bot->type, ['strong_level', 'candle_reversal_confirm'], true))
            <a href="{{ route('bots.cards', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Карточки</a>
            @endif
            @unless($isChartAnalystBot)
            <a href="{{ route('bots.activity', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Сигналы и сделки</a>
            @endunless
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

        @unless($isChartAnalystBot)
        <div class="flex items-start gap-2">
            <input type="checkbox" name="is_active" id="is_active" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('is_active', $bot->is_active))>
            <label for="is_active" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Бот активен</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Участвует в плановом <code class="text-zinc-400">bots:scan</code> (cron каждую минуту). Выключенный бот не анализирует пары и не создаёт сигналы/сделки.</span>
            </label>
        </div>

        {{-- Per-bot зеркало: профиль «Включить зеркалирование» — общий гейт; этот чекбокс — по боту.
             По умолчанию выкл. (нет ключа в config = false). --}}
        <div class="flex items-start gap-2">
            <input type="hidden" name="mirror_trades_enabled" value="0">
            <input type="checkbox" name="mirror_trades_enabled" id="mirror_trades_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('mirror_trades_enabled', $cfg['mirror_trades_enabled'] ?? false))>
            <label for="mirror_trades_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Включить зеркалирование на PO</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                    Отправлять paper-сделки этого бота на Pocket Option (SSID и сумма — в Профиль → Торговля).
                    Нужен также общий переключатель зеркала в профиле. По умолчанию выключено.
                </span>
            </label>
        </div>
        @else
        <p class="text-sm text-zinc-500">
            Плановый <code class="text-zinc-400">bots:scan</code> для Chart Analyst не запускается.
            Статус <strong class="text-zinc-300">active</strong> включается автоматически при галочке «Гибридный режим» ниже (для списка ботов и аналитики).
        </p>
        @endunless

        <div class="flex items-start gap-2">
            {{-- Эта настройка управляет UX графика:
                 когда включена, на странице графика работает tradingview-подобный кроссхейр
                 (вертикальная/горизонтальная линии + подписи времени и цены под курсором).
                 По умолчанию флаг включен для новых/старых ботов, если ключ еще не задан в config. --}}
            <input type="checkbox" name="tv_crosshair_enabled" id="tv_crosshair_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('tv_crosshair_enabled', $cfg['tv_crosshair_enabled'] ?? true))>
            <label for="tv_crosshair_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Кроссхейр как в TradingView</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">На странице графика бота: вертикальная/горизонтальная линии под курсором и подписи цены/времени. На торговую логику не влияет.</span>
            </label>
        </div>

        @unless($isChartAnalystBot)
        <div class="flex items-start gap-2">
            {{-- Персональный флаг логирования для этого конкретного бота:
                 когда включен — движок пишет детальные bot_analysis_runs (status/skip_reason/decision/latency),
                 когда выключен — проходы не попадают в таблицу bot_analysis_runs.
                 Дефолт = true для обратной совместимости со старыми конфигами, где ключа ещё нет. --}}
            <input type="checkbox" name="analysis_runs_logging_enabled" id="analysis_runs_logging_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('analysis_runs_logging_enabled', $cfg['analysis_runs_logging_enabled'] ?? true))>
            <label for="analysis_runs_logging_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Диагностические логи scan</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Пишет в <code class="text-zinc-400">bot_analysis_runs</code> причину пропуска по каждой паре (not_enough_bars, strategy_no_signal, confidence_below_threshold, dedupe…). Смотреть на странице «Логи и метрики». Увеличивает нагрузку на БД.</span>
            </label>
        </div>
        @else
        <p class="text-sm text-zinc-500">История запросов сохраняется в <code class="text-zinc-400">bot_llm_logs</code> (страница «Логи и метрики»).</p>
        @endunless

        <div class="space-y-1">
            <span class="block text-sm font-medium text-zinc-300">Тип стратегии</span>
            <p class="text-sm text-zinc-500 font-mono">{{ $bot->type }}</p>
        </div>

        @if ($bot->type === 'three_candles_in_row')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Three Candles in Row</strong> — контртрендовая логика: три закрытые M1 подряд в одну сторону → сделка
                        <strong>в противоположную</strong> (ожидание отката/истощения импульса).
                    </p>
                    <p class="text-[11px] text-zinc-500">Цепочка: 3 бычьих M1 → PUT; 3 медвежьих → CALL. Вход на последней закрытой свече серии, экспирация — по полю ниже.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Сколько M1 загружать из БД. Для этой стратегии достаточно 30–120 баров (нужны только последние 3). Больший count почти не влияет на логику.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длительность paper-опциона и зеркала на PO. 60 с = 1 мин M1. Также окно dedupe — повторный сигнал по той же паре и стороне в это время не создаётся.</p>
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.55) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Внутренняя оценка силы серии (доля тела свечей, payout). Ниже порога — сигнал отбрасывается.</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пары с текущим payout на PO ниже порога не сканируются. 0 = все активные.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Если последняя M1 не обновлялась дольше N секунд — пара пропускается (нет живого потока котировок).</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела в диапазоне (0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01"
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Средняя доля тела/range по 3 свечам серии. Golden-сетап: 0.65 — отсекает слабые/доджи-импульсы.</p>
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="trade_hour_utc_from" class="block text-xs font-medium text-zinc-400">Час UTC с (пусто = 24/7)</label>
                        <input type="number" name="trade_hour_utc_from" id="trade_hour_utc_from" min="0" max="23" step="1"
                            value="{{ old('trade_hour_utc_from', $cfg['trade_hour_utc_from'] ?? '') }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Начало торгового окна включительно. Вместе с «по» задаёт часы, когда бот торгует.</p>
                        @error('trade_hour_utc_from')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="trade_hour_utc_to" class="block text-xs font-medium text-zinc-400">Час UTC по (включительно)</label>
                        <input type="number" name="trade_hour_utc_to" id="trade_hour_utc_to" min="0" max="23" step="1"
                            value="{{ old('trade_hour_utc_to', $cfg['trade_hour_utc_to'] ?? '') }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Golden: 7–21 UTC (EU/US сессии). Оба поля пустые = круглосуточно.</p>
                        @error('trade_hour_utc_to')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="blocked_instruments_text" class="block text-xs font-medium text-zinc-400">Blacklist инструментов</label>
                        <textarea name="blocked_instruments_text" id="blocked_instruments_text" rows="3"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('blocked_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['blocked_instruments'] ?? null)) }}</textarea>
                        <p class="text-[11px] text-zinc-500">По одному символу на строку. Golden: AUD-base пары (AUDCAD/AUDJPY/…), не GBPAUD — он как раз сильный.</p>
                        @error('blocked_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

            </div>
        @elseif ($bot->type === 'akim_trade_1m')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">AKIM TRADE 1M</strong> — вход по направлению дня: пробой раннего диапазона сессии задаёт bias,
                        затем откат к тренду с rejection-свечой на M1. Мини-боковики (узкий диапазон) пропускаются.
                    </p>
                    <p class="text-[11px] text-zinc-500">CALL: день вверх + медвежья свеча с нижней тенью у EMA21. PUT: день вниз + бычья с верхней тенью. Нужно ≥ 90 баров M1.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Минимум 90 M1 для расчёта «направления дня» и EMA21. Рекомендуется 120–180 баров (~2–3 часа).</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Обычно 60 с (1 мин). Сделка создаётся в момент scan при совпадении day-break + откат + EMA.</p>
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.58) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Зависит от качества тени отката, положения относительно EMA21 и payout. Дефолт 0.58.</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Экономический фильтр: пары с низким payout не сканируются.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пропуск пары, если ingest не обновлял текущую M1 дольше N секунд.</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        @elseif ($bot->type === 'candle_reversal_confirm')
            @include('bots._candle-reversal-level-settings')
        @elseif ($bot->type === 'smc_flow')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">SMC Flow</strong> — вход при совпадении трёх фильтров на последней закрытой M1:
                        <strong>UT Bot</strong> (свежий Buy/Sell) + <strong>3BR confirm</strong> в окне баров + <strong>LuxAlgo OB</strong> (цена в зоне).
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Параметры индикатора (UT, 3BR, OB, FVG…) —
                        <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-blue-400 hover:underline">настройки SMC Flow</a>.
                        Экспирация по умолчанию 3 мин.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 (count)</label>
                        <input type="number" name="count" id="count" min="80" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">История для расчёта UT, 3BR, OB. Минимум 80 баров. 500 — полный контекст индикатора, scan ~15–25 сек на пару.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="120" max="600" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длительность опциона и dedupe-окно. 180 = 3 мин (дефолт). Допустимо 120–600 с.</p>
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.58) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Зависит от свежести 3BR (0 баров = лучше), типа confirm/pattern и payout.</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пары с payout ниже порога не сканируются.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пропуск пар без свежего ingest.</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="smc_three_br_confirm_max_bars" class="block text-xs font-medium text-zinc-400">3BR: окно подтверждения (баров)</label>
                        <input type="number" name="smc_three_br_confirm_max_bars" id="smc_three_br_confirm_max_bars" min="1" max="120" required
                            value="{{ old('smc_three_br_confirm_max_bars', $cfg['smc_three_br_confirm_max_bars'] ?? 30) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">3BR confirm (?) или pattern (^/¡) должен быть не старше N M1-баров относительно UT-сигнала. Больше окно — больше сигналов, слабее связь с UT.</p>
                        @error('smc_three_br_confirm_max_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="smc_ob_touch_max_bars" class="block text-xs font-medium text-zinc-400">OB: касание зоны (баров назад)</label>
                        <input type="number" name="smc_ob_touch_max_bars" id="smc_ob_touch_max_bars" min="0" max="12"
                            value="{{ old('smc_ob_touch_max_bars', $cfg['smc_ob_touch_max_bars'] ?? 3) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">0 = close только на последней свече в bull/bear OB. 3 = цена касалась зоны на любом из последних 4 баров (мягче).</p>
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Фильтры SMC Flow</p>
                        <input type="hidden" name="smc_three_br_allow_pattern" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="smc_three_br_allow_pattern" id="smc_three_br_allow_pattern" value="1"
                            @checked(old('smc_three_br_allow_pattern', $cfg['smc_three_br_allow_pattern'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span>
                            <span class="text-sm font-medium text-zinc-200">3BR: учитывать pattern (^/¡)</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Если выкл. — только confirm (?). Pattern даёт больше сигналов, но слабее подтверждение.</span>
                        </span>
                        </label>
                        <input type="hidden" name="smc_require_ob" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="smc_require_ob" id="smc_require_ob" value="1"
                            @checked(old('smc_require_ob', $cfg['smc_require_ob'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Требовать LuxAlgo Order Block</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Цена в активной OB-зоне нужной стороны. Выкл. — вход по UT + 3BR без зоны накопления.</span>
                        </span>
                        </label>
                </div>
            </div>
        @elseif ($bot->type === 'openrouter_llm')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">OpenRouter LLM</strong> — торговые решения принимает нейросеть по JSON-контракту.
                        В запрос уходят свечи M1 + опционально M5/M15/H1; алгоритмы старых ботов не используются.
                    </p>
                    <p class="text-[11px] text-zinc-500">Нужен <code class="text-zinc-400">OPENROUTER_API_KEY</code> в .env. Логи запросов — «Логи и метрики» → bot_llm_logs.</p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные для модели</p>
                <p class="text-[11px] text-zinc-500 -mt-2">По каждому ТФ одно число = столько последних баров загружается из БД и передаётся в LLM.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1</label>
                        <input type="number" name="count" id="count" min="20" max="500" required
                            value="{{ old('count', $cfg['count'] ?? ($cfg['candles_take'] ?? 120)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Основной ряд для тайминга входа. 80–120 — баланс контекста и стоимости/latency. Больше 200 — дороже и медленнее.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="mtf_enabled" id="mtf_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                   @checked(old('mtf_enabled', $cfg['mtf_enabled'] ?? true))>
                            <span>
                                <span class="text-xs font-medium text-zinc-300">MTF-контекст (M5/M15/H1)</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Модель получает старшие ТФ для bias и структуры. Выкл. — только M1 (быстрее, но больше шума).</span>
                            </span>
                            </label>
                        @error('mtf_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 96) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Подтверждение сетапа, rejection/breakout на M5. ~96 баров = 8 часов.</p>
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 64) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Структура у уровня: range, retest, breakout. ~64 бара = 16 часов.</p>
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 24) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Старший bias (up/down/flat). 24 бара = сутки.</p>
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Scan и сделки</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длительность paper-опциона при CALL/PUT от модели. Также dedupe-окно между сигналами.</p>
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пропуск «застывших» пар.</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.65) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Модель возвращает confidence в JSON; ниже порога — сигнал не создаётся (даже если side = CALL/PUT).</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 70) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Экономический фильтр до вызова LLM (экономит API и время scan).</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Параметры OpenRouter</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1 sm:col-span-2">
                        <label for="model" class="block text-xs font-medium text-zinc-400">Модель (provider/model)</label>
                        @include('bots._openrouter-model-select', [
                            'openrouterModels' => $openrouterModels,
                            'selectedOpenRouterModel' => $selectedOpenRouterModel,
                        ])
                        <p class="text-[11px] text-zinc-500">Формат OpenRouter, напр. <code class="text-zinc-400">openai/gpt-5.2</code>. Vision не требуется — только JSON по свечам.</p>
                        @error('model')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="temperature" class="block text-xs font-medium text-zinc-400">Temperature (0–2)</label>
                        <input type="number" name="temperature" id="temperature" min="0" max="2" step="0.01" required
                            value="{{ old('temperature', $cfg['temperature'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">0 = детерминированные ответы (рекомендуется для торговли). Выше — больше вариативности и риск «NONE»/шума.</p>
                        @error('temperature')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens" class="block text-xs font-medium text-zinc-400">Max tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" min="32" max="4096" required
                            value="{{ old('max_tokens', $cfg['max_tokens'] ?? 350) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Лимит длины ответа. Для JSON-решения достаточно 300–500. Слишком мало — обрезанный JSON и ошибка парсинга.</p>
                        @error('max_tokens')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    @include('bots._openrouter-timeout-field', ['cfg' => $cfg, 'defaultTimeout' => 120])
                </div>

                <div class="space-y-1">
                    <label for="system_prompt" class="block text-xs font-medium text-zinc-400">System prompt</label>
                    <textarea name="system_prompt" id="system_prompt" rows="10" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt', $cfg['system_prompt'] ?? $openrouterDefaultPrompt) }}</textarea>
                    <p class="text-[11px] text-zinc-500">Инструкция модели: MTF-логика, формат JSON (<code class="text-zinc-400">side</code>, <code class="text-zinc-400">confidence</code>, <code class="text-zinc-400">features</code>). Меняйте осторожно — ломается парсер, если формат ответа не совпадает с контрактом.</p>
                    @error('system_prompt')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
            </div>
        @elseif ($bot->type === 'openrouter_llm_analyst')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Chart Analyst</strong> —
                    @if($analystSmcFlowEnabled)
                            ручной и Telegram-анализ с снимком SMC Flow в LLM + блок <code class="text-zinc-400">chart_levels</code> для графика.
                    @else
                            ручной анализ по кнопке «Сделать анализ» на графике (классический MTF без SMC в промпте).
                    @endif
                        Paper-сделки и плановый scan <strong>отключены</strong>.
                </p>
                    <p class="text-[11px] text-zinc-500">Ответ — текст на русском + уровни для оверлея. История в <code class="text-zinc-400">bot_llm_logs</code>.</p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные для модели</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1</label>
                        <input type="number" name="count" id="count" min="1" max="500" required
                            value="{{ old('count', $cfg['count'] ?? ($cfg['candles_take'] ?? 120)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Минутный ряд для структуры и тайминга. 120 баров = 2 часа контекста.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="s5_take" class="block text-xs font-medium text-zinc-400">Свечей S5</label>
                        <input type="number" name="s5_take" id="s5_take" min="1" max="1500" required
                            value="{{ old('s5_take', $cfg['s5_take'] ?? 240) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Секундные свечи ingest (шаг 5 сек) — микроструктура внутри текущей M1. 240 ≈ 20 мин. <strong>Не путать с M5</strong> (5 минут).</p>
                        @error('s5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="mtf_enabled" id="mtf_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mtf_enabled', $cfg['mtf_enabled'] ?? true))>
                            <span>
                                <span class="text-xs font-medium text-zinc-300">MTF M1/M5/M15/H1</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Старшие ТФ в запросе аналитика. Выкл. — только M1+S5 (быстрее, меньше контекста).</span>
                            </span>
                        </label>
                    </div>
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="1" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 96) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Локальная структура и сетапы 3–6 мин экспирации.</p>
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="1" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 64) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Уровни и зоны среднего масштаба.</p>
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="1" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 24) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Дневной/сессионный bias.</p>
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="model" class="block text-xs font-medium text-zinc-400">Модель OpenRouter</label>
                        @include('bots._openrouter-model-select', [
                            'openrouterModels' => $openrouterModels,
                            'selectedOpenRouterModel' => $selectedOpenRouterModel,
                        ])
                        <p class="text-[11px] text-zinc-500">Для длинного текста + chart_levels лучше модели с большим контекстом. Ответ не обязан быть строгим JSON (в отличие от trading LLM).</p>
                    </div>
                    <div class="space-y-1">
                        <label for="temperature" class="block text-xs font-medium text-zinc-400">Temperature</label>
                        <input type="number" name="temperature" id="temperature" min="0" max="2" step="0.01" required
                            value="{{ old('temperature', $cfg['temperature'] ?? 0.2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">0.1–0.3 — стабильный аналитический тон. 0 — максимально предсказуемо.</p>
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens" class="block text-xs font-medium text-zinc-400">Max tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" min="64" max="8192" required
                            value="{{ old('max_tokens', $cfg['max_tokens'] ?? 900) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Аналитик пишет развёрнутый текст + уровни. 900+ токенов. Мало — обрезанный ответ без chart_levels.</p>
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens_short" class="block text-xs font-medium text-zinc-400">Max tokens (краткий режим)</label>
                        <input type="number" name="max_tokens_short" id="max_tokens_short" min="1" required
                            value="{{ old('max_tokens_short', $cfg['max_tokens_short'] ?? \App\Support\OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Лимит генерации только для краткого режима (CALL/PUT/NONE + вероятность). Меньше токенов — быстрее ответ.</p>
                    </div>
                    @include('bots._openrouter-timeout-field', ['cfg' => $cfg, 'defaultTimeout' => 180])
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 border-t border-zinc-800 pt-4">System prompt</p>
                <div class="space-y-1">
                    <label for="analyst_prompt_mode" class="block text-xs font-medium text-zinc-400">Режим ответа</label>
                    <select name="analyst_prompt_mode" id="analyst_prompt_mode" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="full" @selected($analystPromptMode === 'full')>Полный — MTF-разбор + chart_levels</option>
                        <option value="short" @selected($analystPromptMode === 'short')>Краткий — CALL/PUT/NONE + вероятность успеха</option>
                    </select>
                    <p class="text-[11px] text-zinc-500">Краткий режим быстрее: короткий ответ без уровней на графике. Оба промпта сохраняются — переключение без потери текста.</p>
                    @error('analyst_prompt_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="system_prompt" class="block text-xs font-medium text-zinc-400">System prompt (полный)</label>
                    <textarea name="system_prompt" id="system_prompt" rows="12" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt', $cfg['system_prompt'] ?? $openrouterAnalystDefaultPrompt) }}</textarea>
                    <p class="text-[11px] text-zinc-500">MTF-разбор, рекомендация CALL/PUT/NONE, блок chart_levels. При SMC Flow — расширенный промпт при сбросе через artisan sync-prompt.</p>
                    @error('system_prompt')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="system_prompt_short" class="block text-xs font-medium text-zinc-400">System prompt (краткий)</label>
                    <textarea name="system_prompt_short" id="system_prompt_short" rows="10" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt_short', $cfg['system_prompt_short'] ?? $openrouterAnalystShortDefaultPrompt) }}</textarea>
                    <p class="text-[11px] text-zinc-500">Только вердикт: «Рекомендация: CALL/PUT/NONE», «Вероятность успеха: NN%», короткая причина. Без chart_levels. Лимит токенов берётся из поля «Max tokens (краткий режим)».</p>
                    @error('system_prompt_short')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 border-t border-zinc-800 pt-4">Telegram-меню пар</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout для меню (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Фильтр списка инструментов в Telegram-кнопках. 0 = все активные пары с ingest.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="allowed_instruments_text" class="block text-xs font-medium text-zinc-400">Whitelist инструментов</label>
                        <textarea name="allowed_instruments_text" id="allowed_instruments_text" rows="3"
                            placeholder="EURUSD_otc&#10;GBPUSD_otc"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('allowed_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['allowed_instruments'] ?? null)) }}</textarea>
                        <p class="text-[11px] text-zinc-500">По одному символу на строку. Пусто = без ограничения (кроме blacklist и min_payout). Точные имена как в PO, напр. <code class="text-zinc-400">EURUSD_otc</code>.</p>
                        @error('allowed_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="blocked_instruments_text" class="block text-xs font-medium text-zinc-400">Blacklist инструментов</label>
                        <textarea name="blocked_instruments_text" id="blocked_instruments_text" rows="2"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('blocked_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['blocked_instruments'] ?? null)) }}</textarea>
                        <p class="text-[11px] text-zinc-500">Исключить шумные или неликвидные пары из Telegram-меню. Имеет приоритет над whitelist.</p>
                        @error('blocked_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Гибрид (сигнал другого бота → этот Chart Analyst)</p>
                    <p class="text-[11px] text-zinc-500">
                        Когда выбранный бот даёт CALL/PUT, этот аналитик получает те же данные, что и обычный Chart Analyst (MTF/S5/SMC),
                        плюс полный decision source, и пишет результат в логи/Telegram.
                        Опционально — своя paper-сделка (и PO) на этом Chart Analyst, поверх сделки source.
                        Кнопка «Доп. анализ SMC Flow» у source-ботов работает параллельно.
                    </p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="hybrid_enabled" id="hybrid_enabled" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('hybrid_enabled', $cfg['hybrid_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Включить гибридный режим</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Нужны queue worker <code class="text-zinc-400">telegram-analyst</code> (при сохранении перезапускается сам). Бот станет <strong class="text-zinc-300">active</strong> в списке. Для Telegram-алертов — token и chat_id ниже.</span>
                        </span>
                    </label>
                    @error('hybrid_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    @php
                        $hybridSourceMode = old('hybrid_source_mode', $cfg['hybrid_source_mode'] ?? \App\Support\HybridAnalystConfig::SOURCE_MODE_FIXED);
                        $hybridSourceId = (int) old('hybrid_source_bot_id', $cfg['hybrid_source_bot_id'] ?? 0);
                        $hybridLeaderWindow = (int) old('hybrid_leader_window_sec', $cfg['hybrid_leader_window_sec'] ?? \App\Support\HybridAnalystConfig::LEADER_WINDOW_1H);
                        $hybridLeaderMinTrades = (int) old('hybrid_leader_min_trades', $cfg['hybrid_leader_min_trades'] ?? \App\Support\HybridAnalystConfig::LEADER_MIN_TRADES_DEFAULT);
                        $hybridLeaderId = (int) ($cfg['hybrid_leader_bot_id'] ?? 0);
                    @endphp
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            <label for="hybrid_source_mode" class="block text-xs font-medium text-zinc-400">Источник сигнала</label>
                            <select name="hybrid_source_mode" id="hybrid_source_mode" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="fixed" @selected($hybridSourceMode === 'fixed')>Фиксированный бот</option>
                                <option value="best_winrate" @selected($hybridSourceMode === 'best_winrate')>Лучший по paper winrate за период</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">
                                В режиме winrate гибрид слушает только текущего лидера среди <strong class="text-zinc-400">active</strong> торговых ботов (Chart Analyst исключён).
                                Пересчёт с кэшем ~{{ \App\Support\HybridAnalystConfig::LEADER_CACHE_TTL_SEC }} сек — на каждый сигнал БД не долбится.
                                @if($hybridLeaderId > 0)
                                    Текущий сохранённый лидер: <code class="text-zinc-400">#{{ $hybridLeaderId }}</code>.
                                @endif
                            </p>
                            @error('hybrid_source_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2" id="hybrid-source-bot-wrap">
                            <label for="hybrid_source_bot_id" class="block text-xs font-medium text-zinc-400">Бот первичного анализа</label>
                            <select name="hybrid_source_bot_id" id="hybrid_source_bot_id"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 disabled:opacity-50 disabled:cursor-not-allowed">
                                <option value="">— не выбран —</option>
                                @foreach ($hybridSourceBots ?? [] as $srcBot)
                                    <option value="{{ $srcBot->id }}" @selected($hybridSourceId === (int) $srcBot->id)>
                                        #{{ $srcBot->id }} {{ $srcBot->name }} ({{ $srcBot->type }}){{ $srcBot->is_active ? '' : ' · выкл' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-zinc-500" id="hybrid-source-bot-hint">Один конкретный бот. Сигналы других ботов игнорируются.</p>
                            @error('hybrid_source_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 hybrid-leader-only">
                            <label for="hybrid_leader_window_sec" class="block text-xs font-medium text-zinc-400">Окно рейтинга</label>
                            <select name="hybrid_leader_window_sec" id="hybrid_leader_window_sec" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1800" @selected($hybridLeaderWindow === 1800)>30 минут</option>
                                <option value="3600" @selected($hybridLeaderWindow === 3600)>1 час</option>
                                <option value="7200" @selected($hybridLeaderWindow === 7200)>2 часа</option>
                                <option value="10800" @selected($hybridLeaderWindow === 10800)>3 часа</option>
                                <option value="21600" @selected($hybridLeaderWindow === 21600)>6 часов</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">Paper winrate W/(W+L) по всем парам за это окно.</p>
                            @error('hybrid_leader_window_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 hybrid-leader-only">
                            <label for="hybrid_leader_min_trades" class="block text-xs font-medium text-zinc-400">Мин. сделок для рейтинга</label>
                            <input type="number" name="hybrid_leader_min_trades" id="hybrid_leader_min_trades" min="1" max="500" required
                                value="{{ $hybridLeaderMinTrades }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">Иначе бот с 1/1 = 100% не станет лидером. При нехватке данных / ничьей — остаётся прошлый лидер.</p>
                            @error('hybrid_leader_min_trades')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_trigger" class="block text-xs font-medium text-zinc-400">Когда запускать</label>
                            @php $hybridTrigger = old('hybrid_trigger', $cfg['hybrid_trigger'] ?? \App\Support\HybridAnalystConfig::TRIGGER_ON_SIGNAL); @endphp
                            <select name="hybrid_trigger" id="hybrid_trigger" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="on_signal" @selected($hybridTrigger === 'on_signal')>При каждом CALL/PUT (запись сигнала)</option>
                                <option value="on_po_mirror" @selected($hybridTrigger === 'on_po_mirror')>Когда paper-сделка уходит на PO (mirror)</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">
                                <strong>PO</strong> — только если у source включено зеркало сделок. Alert-only боты без сделки не попадут в этот режим.
                            </p>
                            @error('hybrid_trigger')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_publish" class="block text-xs font-medium text-zinc-400">Публикация в Telegram</label>
                            @php $hybridPublish = old('hybrid_publish', $cfg['hybrid_publish'] ?? \App\Support\HybridAnalystConfig::PUBLISH_ALWAYS); @endphp
                            <select name="hybrid_publish" id="hybrid_publish" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="always" @selected($hybridPublish === 'always')>Всегда (если LLM ответил)</option>
                                <option value="agree_source" @selected($hybridPublish === 'agree_source')>Только если LLM согласен с side source</option>
                                <option value="call_put_only" @selected($hybridPublish === 'call_put_only')>Только если LLM дал CALL или PUT</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">Фильтр по стороне. Лог на странице Chart Analyst пишется всегда.</p>
                            @error('hybrid_publish')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_min_success_percent" class="block text-xs font-medium text-zinc-400">Мин. вероятность успеха (%)</label>
                            <input type="number" name="hybrid_min_success_percent" id="hybrid_min_success_percent" min="0" max="100" required
                                value="{{ old('hybrid_min_success_percent', $cfg['hybrid_min_success_percent'] ?? 0) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">
                                Берётся строка <code class="text-zinc-400">Вероятность успеха: NN%</code> из ответа LLM.
                                В Telegram уходит только если NN ≥ порога. <strong>0</strong> = не фильтровать по вероятности.
                                Работает вместе с режимом публикации выше.
                            </p>
                            @error('hybrid_min_success_percent')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="space-y-3 border-t border-zinc-800 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Торговля гибрида</p>
                        <p class="text-[11px] text-zinc-500">
                            Те же фильтры, что для Telegram. Side — только из LLM (CALL/PUT). Экспирация — из source.
                            Сумма на PO — из профиля «Торговля». Сделка пишется на этот Chart Analyst (поверх source).
                        </p>
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="hybrid_paper_trades_enabled" value="0">
                            <input type="checkbox" name="hybrid_paper_trades_enabled" id="hybrid_paper_trades_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hybrid_paper_trades_enabled', $cfg['hybrid_paper_trades_enabled'] ?? false))>
                            <span>
                                <span class="text-sm font-medium text-zinc-300">Paper-сделки</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Создавать paper signal/trade на этом боте после успешного гибрид-фильтра.</span>
                            </span>
                        </label>
                        @error('hybrid_paper_trades_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="hybrid_mirror_trades_enabled" value="0">
                            <input type="checkbox" name="hybrid_mirror_trades_enabled" id="hybrid_mirror_trades_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hybrid_mirror_trades_enabled', $cfg['hybrid_mirror_trades_enabled'] ?? false))>
                            <span>
                                <span class="text-sm font-medium text-zinc-300">Зеркалировать на Pocket Option</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Как у других ботов: нужен paper + зеркало в профиле «Торговля». Без paper чекбокс выше PO не отправится.</span>
                            </span>
                        </label>
                        @error('hybrid_mirror_trades_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                <script>
                (function () {
                    // Режим source: fixed → селект бота активен; best_winrate → селект disabled.
                    const modeEl = document.getElementById('hybrid_source_mode');
                    const sourceEl = document.getElementById('hybrid_source_bot_id');
                    const hintEl = document.getElementById('hybrid-source-bot-hint');
                    const leaderBlocks = document.querySelectorAll('.hybrid-leader-only');
                    if (!modeEl || !sourceEl) return;

                    function sync() {
                        const auto = modeEl.value === 'best_winrate';
                        sourceEl.disabled = auto;
                        if (hintEl) {
                            hintEl.textContent = auto
                                ? 'Селект неактивен: source выбирается автоматически по лучшему paper winrate.'
                                : 'Один конкретный бот. Сигналы других ботов игнорируются.';
                        }
                        leaderBlocks.forEach(function (el) {
                            el.classList.toggle('opacity-50', !auto);
                        });
                    }
                    modeEl.addEventListener('change', sync);
                    sync();
                    // disabled select не уходит в POST — перед submit включаем, чтобы сохранить выбранный fixed id.
                    const form = modeEl.closest('form');
                    if (form) {
                        form.addEventListener('submit', function () {
                            sourceEl.disabled = false;
                        });
                    }
                })();
                </script>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram (Chart Analyst bot)</p>
                    <p class="text-[11px] text-zinc-500">
                        Long polling и queue worker (<code class="text-zinc-400">queue:work --queue=telegram-analyst</code>) — перезапуск кнопкой ниже после сохранения token/chat_id.
                    </p>
                    <p id="telegram-process-status-analyst" class="text-xs text-zinc-500">Проверка процессов…</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_analyst" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Включить Telegram-бота</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Интерактивное меню пар + запрос анализа по кнопке. Без галочки polling не стартует даже при сохранённом token.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_skip_user_question" id="telegram_skip_user_question_analyst" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_skip_user_question', $cfg['telegram_skip_user_question'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Сразу анализ без вопроса</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">После выбора инструмента не спрашивать дополнительный вопрос — сразу ставить анализ в очередь. Применяется сразу после сохранения (poll перечитывает config из БД). Перезапуск poll нужен только если опция не сработала.</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token_analyst',
                                'hint' => 'Токен от @BotFather. Пустое поле при сохранении — оставить текущий. После смены token нажмите «Перезапустить poll + queue».',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id_analyst" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id_analyst"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
                            <p class="text-[11px] text-zinc-500">Пусто = принимать команды из любого чата (удобно для личного бота). Число — ограничить одним пользователем/группой.</p>
                            @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn-analyst"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id
                            </button>
                            <button type="button" id="telegram-test-btn-analyst"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-analyst"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status-analyst" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Напишите боту
                        <a href="https://t.me/chart_analyct_smc_flow_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@chart_analyct_smc_flow_bot</a>
                        → /start, затем «Получить chat_id».
                    </p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn-analyst');
                const testBtn = document.getElementById('telegram-test-btn-analyst');
                const restartBtn = document.getElementById('telegram-restart-btn-analyst');
                const statusEl = document.getElementById('telegram-action-status-analyst');
                const processStatusEl = document.getElementById('telegram-process-status-analyst');
                const chatInput = document.getElementById('telegram_chat_id_analyst');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
                const testUrl = @json(route('bots.telegram.test', $bot));
                const analystStatusUrl = @json(route('bots.telegram.analyst-status', $bot));
                const analystRestartUrl = @json(route('bots.telegram.analyst-restart', $bot));

                function setStatus(text, isError) {
                    if (!statusEl) return;
                    statusEl.textContent = text || '';
                    statusEl.className = 'text-xs ' + (isError ? 'text-rose-400' : 'text-emerald-300');
                }

                function renderProcessStatus(data) {
                    if (!processStatusEl || !data) return;
                    const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
                    const queue = data.queue_running ? 'queue ✓' : 'queue ✗';
                    const color = data.poll_running && data.queue_running ? 'text-emerald-300' : 'text-amber-300';
                    processStatusEl.textContent = 'Процессы: ' + poll + ', ' + queue;
                    processStatusEl.className = 'text-xs ' + color;
                }

                async function refreshProcessStatus() {
                    if (!processStatusEl) return;
                    try {
                        const res = await fetch(analystStatusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (res.ok && data.ok) renderProcessStatus(data);
                        else processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', false);
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(analystRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', false);
                            renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Запрос getUpdates…', false);
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — напишите боту /start', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + ')', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @elseif ($bot->type === 'strong_level')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Strong Level</strong> — бот ищет <strong>сильные уровни</strong> (конфлюенс нескольких источников),
                        ждёт касание ценой и входит на <strong>отскок</strong> или <strong>пробой</strong> после подтверждения M5/M1.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Источники уровней: Price Action DGT (S/R, POC/VA), SMC Flow (rolling 4H/1D, reversal S/R, 3BR-зоны), swing H1/M15.
                        Параметры индикаторов — на отдельных страницах:
                        <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}" class="text-blue-400 hover:underline">Price Action DGT</a>,
                        <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-blue-400 hover:underline">SMC Flow</a>.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Цепочка решения: загрузка свечей → сбор уровней → кластеризация → фильтр силы/расстояния → режим (отскок/пробой) → подтверждение M5/M1 → paper-сделка.
                    </p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные и скан</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 (count)</label>
                        <input type="number" name="count" id="count" min="200" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Сколько минутных свечей загружать из БД на каждую пару за проход scan. Больше — точнее уровни DGT/SMC и swing, но дольше анализ (~20+ сек на пару). Рекомендуется 400–500.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_closed_bars" class="block text-xs font-medium text-zinc-400">Мин. закрытых баров</label>
                        <input type="number" name="min_closed_bars" id="min_closed_bars" min="80" max="2000" required
                            value="{{ old('min_closed_bars', $cfg['min_closed_bars'] ?? 200) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пара не анализируется, пока накоплено меньше закрытых M1. Нужен запас для rolling-уровней (4H/1D) и volume profile. Не ставьте ниже 150–200.</p>
                        @error('min_closed_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 82) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пары с текущим payout на Pocket Options ниже порога пропускаются целиком. 0 = все активные пары. Выше порог — меньше инструментов, но лучше математика бинарки.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Если последняя M1 не обновлялась дольше этого времени, пара считается «застывшей» и пропускается (нет актуального потока котировок).</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.58) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Внутренняя оценка качества сетапа (сила уровня, касания, режим, H1, payout). Сигнал ниже порога не создаёт сделку, даже если стратегия вернула CALL/PUT.</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Уровни и конфлюенс</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="min_level_strength" class="block text-xs font-medium text-zinc-400">Мин. сила уровня (0–100)</label>
                        <input type="number" name="min_level_strength" id="min_level_strength" min="40" max="95" step="1" required
                            value="{{ old('min_level_strength', $cfg['min_level_strength'] ?? 58) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Скоринг кластера: число источников (DGT, SMC, swing…), их вес, склейка линий, касания. 58 — сбалансировано. 68+ — редко (нужен 3+ источник). 52–55 — чаще сделки.</p>
                        @error('min_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_level_strength" class="block text-xs font-medium text-zinc-400">Макс. сила уровня (0 = выкл.)</label>
                        <input type="number" name="max_level_strength" id="max_level_strength" min="0" max="100" step="1" required
                            value="{{ old('max_level_strength', $cfg['max_level_strength'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Потолок силы. 90 отсекает «переоптимизированные» уровни с кучей касаний (часто пила). 0 = без потолка.</p>
                        @error('max_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_sources" class="block text-xs font-medium text-zinc-400">Мин. источников конфлюенса</label>
                        <input type="number" name="min_sources" id="min_sources" min="1" max="6" required
                            value="{{ old('min_sources', $cfg['min_sources'] ?? 1) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Сколько <em>разных</em> типов уровней должны совпасть в одной ценовой зоне. <strong>1</strong> — один сильный источник (чаще). <strong>2</strong> — классический конфлюенс (реже).</p>
                        @error('min_sources')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="cluster_atr_mult_m5" class="block text-xs font-medium text-zinc-400">Кластер уровней (× ATR M5)</label>
                        <input type="number" name="cluster_atr_mult_m5" id="cluster_atr_mult_m5" min="0.05" max="1" step="0.01" required
                            value="{{ old('cluster_atr_mult_m5', $cfg['cluster_atr_mult_m5'] ?? 0.28) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Радиус склейки близких цен в один кластер: distance ≤ ATR(M5) × множитель. Меньше — точнее уровень, сложнее набрать конфлюенс. Больше — шире зона, чаще совпадения.</p>
                        @error('cluster_atr_mult_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="touch_atr_mult_m1" class="block text-xs font-medium text-zinc-400">Зона касания (× ATR M1)</label>
                        <input type="number" name="touch_atr_mult_m1" id="touch_atr_mult_m1" min="0.05" max="1.5" step="0.01" required
                            value="{{ old('touch_atr_mult_m1', $cfg['touch_atr_mult_m1'] ?? 0.55) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Насколько близко текущая цена (close последней M1) должна быть к уровню. 0.55 × ATR(M1) ≈ 2–4 пункта на форексе. Главный рычаг частоты сигналов.</p>
                        @error('touch_atr_mult_m1')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Режим входа и подтверждение</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="entry_mode" class="block text-xs font-medium text-zinc-400">Режим входа</label>
                        @php $entryMode = old('entry_mode', $cfg['entry_mode'] ?? 'both'); @endphp
                        <select name="entry_mode" id="entry_mode" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="both" @selected($entryMode === 'both')>Отскок и пробой</option>
                            <option value="bounce" @selected($entryMode === 'bounce')>Только отскок</option>
                            <option value="breakout" @selected($entryMode === 'breakout')>Только пробой</option>
                        </select>
                        <p class="text-[11px] text-zinc-500">
                            <strong>Отскок</strong> — цена у уровня, M5 rejection (тень), M1 в сторону отскока.
                            <strong>Пробой</strong> — M5 закрылась за уровнем + фильтры ретеста/объёма.
                            На OTC чаще работает только отскок.
                        </p>
                        @error('entry_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="bounce_min_wick_ratio_m5" class="block text-xs font-medium text-zinc-400">Отскок: мин. тень M5 (0–1)</label>
                        <input type="number" name="bounce_min_wick_ratio_m5" id="bounce_min_wick_ratio_m5" min="0.15" max="0.8" step="0.01" required
                            value="{{ old('bounce_min_wick_ratio_m5', $cfg['bounce_min_wick_ratio_m5'] ?? 0.28) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Доля тени rejection-свечи M5 в диапазоне свечи. CALL — нижняя тень у support, PUT — верхняя у resistance. 0.35 = 35% (строго). 0.22–0.25 — мягче, больше входов.</p>
                        @error('bounce_min_wick_ratio_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec_bounce" class="block text-xs font-medium text-zinc-400">Экспирация отскок (сек)</label>
                        <input type="number" name="expiry_sec_bounce" id="expiry_sec_bounce" min="120" max="600" required
                            value="{{ old('expiry_sec_bounce', $cfg['expiry_sec_bounce'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длительность paper-опциона при сетапе «отскок». 180 = 3 мин. Также используется как окно dedupe (не дублировать сигнал на том же уровне в это время).</p>
                        @error('expiry_sec_bounce')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec_breakout" class="block text-xs font-medium text-zinc-400">Экспирация пробой (сек)</label>
                        <input type="number" name="expiry_sec_breakout" id="expiry_sec_breakout" min="120" max="600" required
                            value="{{ old('expiry_sec_breakout', $cfg['expiry_sec_breakout'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длительность опциона при сетапе «пробой». Обычно 4–5 мин — пробою нужно больше времени на продолжение движения.</p>
                        @error('expiry_sec_breakout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_flat_strength_extra" class="block text-xs font-medium text-zinc-400">H1 флэт: доп. сила уровня</label>
                        <input type="number" name="h1_flat_strength_extra" id="h1_flat_strength_extra" min="0" max="40" step="1" required
                            value="{{ old('h1_flat_strength_extra', $cfg['h1_flat_strength_extra'] ?? 4) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Когда H1 во флэте и <em>не</em> включён полный запрет flat — к min_level_strength добавляется этот запас. 15–40 — почти нет сигналов во флэте.</p>
                        @error('h1_flat_strength_extra')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Дополнительные фильтры</p>
                    <div class="space-y-3 text-sm text-zinc-300">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="require_h1_alignment" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('require_h1_alignment', ($cfg['require_h1_alignment'] ?? false) ? '1' : '') === '1' || (old('require_h1_alignment') === null && ($cfg['require_h1_alignment'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">H1 bias не против входа</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL запрещён при явном H1 down, PUT — при H1 up. Во флэте H1 нужна доп. сила (поле выше), если не включён полный запрет flat.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="block_h1_flat" value="0">
                            <input type="checkbox" name="block_h1_flat" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('block_h1_flat', ($cfg['block_h1_flat'] ?? false) ? '1' : '') === '1' || (old('block_h1_flat') === null && ($cfg['block_h1_flat'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Запрет входа при H1 = flat</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Жёстко: при боковом H1 сделок нет (рекомендуется по статистике — flat давал ~48% WR).</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="bounce_require_m1_confirm" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('bounce_require_m1_confirm', ($cfg['bounce_require_m1_confirm'] ?? false) ? '1' : '') === '1' || (old('bounce_require_m1_confirm') === null && ($cfg['bounce_require_m1_confirm'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Отскок: M1 в сторону входа</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL требует бычью M1, PUT — медвежью. Снимите для OTC: достаточно rejection на M5, M1 может быть нейтральной.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="breakout_require_retest" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('breakout_require_retest', ($cfg['breakout_require_retest'] ?? false) ? '1' : '') === '1' || (old('breakout_require_retest') === null && ($cfg['breakout_require_retest'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Пробой: требовать ретест уровня</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">После пробоя цена должна вернуться касанием к уровню на M5 и снова уйти в сторону пробоя. Отсекает «голые» пробои без подтверждения.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="breakout_vol_above_sma" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('breakout_vol_above_sma', ($cfg['breakout_vol_above_sma'] ?? false) ? '1' : '') === '1' || (old('breakout_vol_above_sma') === null && ($cfg['breakout_vol_above_sma'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Пробой: объём M5 ≥ 90% от SMA(20)</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Свеча пробоя на M5 должна быть не слабее среднего объёма за 20 баров. Слабые пробои на низком объёме отфильтровываются.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Старшие таймфреймы (MTF)</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">История M5 для rejection/пробоя, ATR M5 (кластеризация) и режима входа. 120 баров ≈ 10 часов.</p>
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Swing high/low на M15 как дополнительные уровни в кластере. 80 баров ≈ 20 часов.</p>
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 48) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Swing H1 + определение H1 bias (up/down/flat по последним 12 свечам). 48 баров = 2 суток.</p>
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram</p>
                    <p class="text-[11px] text-zinc-500">Push при новом сигнале и paper-сделке (cron <code class="text-zinc-400">bots:scan</code>). Token и chat_id — как у других ботов, один получатель.</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_strong_level" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Без галочки сделки создаются в платформе, но сообщения в Telegram не уходят.</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="telegram_notify_on" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
                            @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
                            <select name="telegram_notify_on" id="telegram_notify_on" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новый сигнал + paper-сделка</option>
                                <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять (даже если Telegram включён)</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">По умолчанию — при каждой созданной сделке Strong Level. «Не уведомлять» — для отладки без спама.</p>
                            @error('telegram_notify_on')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_alert_format" class="block text-xs font-medium text-zinc-400">Формат сообщения</label>
                            @php $telegramAlertFormat = old('telegram_alert_format', $cfg['telegram_alert_format'] ?? 'brief'); @endphp
                            <select name="telegram_alert_format" id="telegram_alert_format" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="brief" @selected($telegramAlertFormat === 'brief')>Краткий</option>
                                <option value="detailed" @selected($telegramAlertFormat === 'detailed')>Развёрнутый</option>
                                <option value="card" @selected($telegramAlertFormat === 'card')>Как на карточке</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">Краткий: пара, уровень, режим, экспирация. Развёрнутый: + confidence, H1, источники, причины. «Как на карточке»: шаблонный абзац стратегии + статистика + тех. детали (как в модалке карточек).</p>
                            @error('telegram_alert_format')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_send_chart" class="block text-xs font-medium text-zinc-400">График в алерте</label>
                            @php
                                $telegramSendChart = old('telegram_send_chart');
                                if ($telegramSendChart === null) {
                                    $telegramSendChart = ($cfg['telegram_send_chart'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart" id="telegram_send_chart" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChart === '1')>Текст + график M1 (PNG)</option>
                                <option value="0" @selected((string) $telegramSendChart === '0')>Только текст (без картинки)</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">С графиком: свечи M1, линия уровня, зона касания и маркер входа. Без графика — одно текстовое сообщение (быстрее, меньше трафика).</p>
                            @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_send_chart_m5" class="block text-xs font-medium text-zinc-400">Доп. график M5</label>
                            @php
                                $telegramSendChartM5 = old('telegram_send_chart_m5');
                                if ($telegramSendChartM5 === null) {
                                    $telegramSendChartM5 = ($cfg['telegram_send_chart_m5'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart_m5" id="telegram_send_chart_m5" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChartM5 === '1')>Отправлять второй PNG M5</option>
                                <option value="0" @selected((string) $telegramSendChartM5 === '0')>Только M1 (без M5)</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">Работает только при включённом «График в алерте». Показывает старший контекст на M5 отдельным сообщением.</p>
                            @error('telegram_send_chart_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled" value="1"
                                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                                <span>
                                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                                    <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Inline-кнопка под алертом: callback в Strong Level → LLM-анализ Chart Analyst в этом же чате. Нужен poll (кнопка ниже).</span>
                                </span>
                            </label>
                            @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_analyst_bot_id" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                            @php $selectedAnalystId = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="">Авто (первый Chart Analyst)</option>
                                @foreach ($chartAnalystBots ?? [] as $analystBot)
                                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystId === (string) $analystBot->id)>
                                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_analyst_llm_question" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question" rows="2"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
                            <p class="text-[11px] text-zinc-500">Подставляется в RunTelegramAnalystAnalysisJob вместо шага «ваш вопрос».</p>
                            @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        {{-- Статус poll растягиваем на всю строку, чтобы он не смешивался с полями ниже в двухколоночной сетке. --}}
                        <div class="space-y-1 sm:col-span-2">
                            <p id="telegram-process-status-strong-level" class="text-xs text-zinc-500">Проверка poll…</p>
                        </div>
                        {{-- У каждого элемента в grid должен быть свой контейнер, иначе соседняя колонка с action-кнопками «съезжает». --}}
                        <div class="space-y-1">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token_strong_level',
                                'hint' => 'Токен от @BotFather (напр. @strong_level_bot). Пустое поле при сохранении — оставить текущий.',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id_strong_level" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id_strong_level"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
                            <p class="text-[11px] text-zinc-500">Один chat_id — личный чат, группа или канал.</p>
                            @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn-strong-level"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id
                            </button>
                            <button type="button" id="telegram-test-btn-strong-level"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-strong-level"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status-strong-level" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Напишите боту
                        <a href="https://t.me/strong_level_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@strong_level_bot</a>
                        → /start, сохраните token, затем «Получить chat_id».
                    </p>
                </div>

                <div class="rounded border border-amber-900/40 bg-amber-950/20 px-4 py-3 text-[11px] text-amber-100/80">
                    <p class="font-medium text-amber-200/90">Почему сигналов мало?</p>
                    <p class="mt-1">Раньше формула силы не давала 68 при 2 источниках — это исправлено. Для частоты: min_level_strength 55–58, min_sources 1, touch 0.55+, bounce wick 0.25–0.28, снять H1/M1/ретест/объём.</p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn-strong-level');
                const testBtn = document.getElementById('telegram-test-btn-strong-level');
                const restartBtn = document.getElementById('telegram-restart-btn-strong-level');
                const statusEl = document.getElementById('telegram-action-status-strong-level');
                const processStatusEl = document.getElementById('telegram-process-status-strong-level');
                const chatInput = document.getElementById('telegram_chat_id_strong_level');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
                const testUrl = @json(route('bots.telegram.test', $bot));
                const slStatusUrl = @json(route('bots.telegram.strong-level-status', $bot));
                const slRestartUrl = @json(route('bots.telegram.strong-level-restart', $bot));

                function setStatus(text, isError) {
                    if (!statusEl) return;
                    statusEl.textContent = text || '';
                    statusEl.className = 'text-xs ' + (isError ? 'text-rose-400' : 'text-emerald-300');
                }

                function renderProcessStatus(data) {
                    if (!processStatusEl || !data) return;
                    const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
                    const queue = data.queue_running ? 'queue ✓' : 'queue ✗';
                    const color = data.poll_running && data.queue_running ? 'text-emerald-300' : 'text-amber-300';
                    processStatusEl.textContent = 'Процессы: ' + poll + ', ' + queue;
                    processStatusEl.className = 'text-xs ' + color;
                }

                async function refreshProcessStatus() {
                    if (!processStatusEl) return;
                    try {
                        const res = await fetch(slStatusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (res.ok && data.ok) renderProcessStatus(data);
                        else processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', false);
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(slRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', false);
                            renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Останавливаем poll и ждём /start (до 15 сек)…', false);
                        discoverBtn.disabled = true;
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — нажмите кнопку и сразу /start @strong_level_bot', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + '). Сохраните настройки.', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            discoverBtn.disabled = false;
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки, если меняли token/chat_id)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @elseif ($bot->type === 'strong_trend_telegram')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Strong Trend Telegram</strong> — детектор импульса на M1:
                        <strong>N закрытых свечей подряд</strong> в одну сторону → push-алерт в Telegram.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Режим исполнения задаётся в настройке ниже: <code class="text-zinc-400">Только алерты</code>
                        или <code class="text-zinc-400">Сделки + алерты</code>.
                        CALL = серия бычьих свечей (тренд вверх), PUT = медвежья серия.
                    </p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Детектор серии</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="execution_mode" class="block text-xs font-medium text-zinc-400">Режим исполнения</label>
                        @php $trendExecutionMode = old('execution_mode', $cfg['execution_mode'] ?? (($cfg['notify_only'] ?? true) ? 'alert_only' : 'signal_trade')); @endphp
                        <select name="execution_mode" id="execution_mode" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="alert_only" @selected($trendExecutionMode === 'alert_only')>Только алерты (без сделок)</option>
                            <option value="signal_trade" @selected($trendExecutionMode === 'signal_trade')>Сделки + алерты (как strong_level)</option>
                        </select>
                        <p class="text-[11px] text-zinc-500">В режиме сделок бот создаёт paper-сделки и запускает mirror по обычному пайплайну движка.</p>
                        @error('execution_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 30) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Сколько M1 загружать из БД. Для streak_len=6 достаточно 30–60. Больше — запас для диагностики, на логику не влияет.</p>
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="streak_len" class="block text-xs font-medium text-zinc-400">Свечей подряд (streak_len)</label>
                        <input type="number" name="streak_len" id="streak_len" min="2" max="30" required
                            value="{{ old('streak_len', $cfg['streak_len'] ?? 6) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Длина серии для алерта. 4–5 — чаще сигналы, больше шума. 6–8 — реже, но «чище» импульс. Минимум 2.</p>
                        @error('streak_len')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Используется только в режиме «Сделки + алерты». Для M1 обычно 60 секунд.</p>
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.55) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Confidence = 60% «качество тел свечей» + 40% payout. Базовый минимум стратегии 0.55. Ниже порога — алерт не уходит.</p>
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Пары с payout на PO ниже порога не сканируются. 0 = все активные с живым ingest.</p>
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Если последняя M1 не обновлялась дольше N секунд — пара пропускается (нет живого потока котировок / ingest отстаёт).</p>
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="dedupe_bucket_sec" class="block text-xs font-medium text-zinc-400">Dedupe (сек)</label>
                        <input type="number" name="dedupe_bucket_sec" id="dedupe_bucket_sec" min="60" max="3600" required
                            value="{{ old('dedupe_bucket_sec', $cfg['dedupe_bucket_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Не чаще одного алерта на пару + направление (CALL/PUT) в этом окне. 300 с = не спамить при продолжающемся тренде.</p>
                        @error('dedupe_bucket_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="candle_strictness" class="block text-xs font-medium text-zinc-400">Строгость свечей</label>
                        @php $strictness = old('candle_strictness', $cfg['candle_strictness'] ?? 'strict'); @endphp
                        <select name="candle_strictness" id="candle_strictness" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="strict" @selected($strictness === 'strict')>Строго (close &gt; open / close &lt; open)</option>
                            <option value="with_doji" @selected($strictness === 'with_doji')>С доджи (close ≥ open / close ≤ open)</option>
                        </select>
                        <p class="text-[11px] text-zinc-500"><strong>Строго</strong> — доджи (close=open) рвут серию. <strong>С доджи</strong> — плоские свечи допускаются внутри импульса, алертов больше.</p>
                        @error('candle_strictness')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела в диапазоне (0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Средняя |close−open| / (high−low) по серии. 0 = выкл. Рекомендуется 0.55 — отсекает доджи и «пилу».</p>
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Макс. доля тела (0 = выкл.)</label>
                        <input type="number" name="max_body_to_range_ratio" id="max_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('max_body_to_range_ratio', $cfg['max_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Верхняя граница средней доли тела. 0.70 отсекает «ракеты» без отката (late entry). 0 = выкл.</p>
                        @error('max_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Откат к EMA/HMA (не входить на голой N-й свече)</p>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <input type="hidden" name="require_pullback_to_ma" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="require_pullback_to_ma" id="require_pullback_to_ma" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('require_pullback_to_ma', $cfg['require_pullback_to_ma'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Требовать откат к MA перед входом</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                                Мягкий фильтр late-entry: в серии N свечей цена должна коснуться зоны EMA/HMA
                                <em>или</em> не улететь дальше «макс. удаления × ATR» от MA. Без галочки — вход сразу на N-й свече.
                            </span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="pullback_bars" class="block text-xs font-medium text-zinc-400">Баров на откат (legacy)</label>
                            <input type="number" name="pullback_bars" id="pullback_bars" min="1" max="5" required
                                value="{{ old('pullback_bars', $cfg['pullback_bars'] ?? 2) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">Сохраняется в конфиге; в soft-режиме касание ищется по всей серии streak_len.</p>
                            @error('pullback_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_ma_type" class="block text-xs font-medium text-zinc-400">Тип MA</label>
                            @php $pullbackMaType = old('pullback_ma_type', $cfg['pullback_ma_type'] ?? 'ema'); @endphp
                            <select name="pullback_ma_type" id="pullback_ma_type" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="ema" @selected($pullbackMaType === 'ema')>EMA</option>
                                <option value="hma" @selected($pullbackMaType === 'hma')>HMA (Hull)</option>
                            </select>
                            @error('pullback_ma_type')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_ma_length" class="block text-xs font-medium text-zinc-400">Длина MA</label>
                            <input type="number" name="pullback_ma_length" id="pullback_ma_length" min="2" max="100" required
                                value="{{ old('pullback_ma_length', $cfg['pullback_ma_length'] ?? 9) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">EMA/HMA(9) на M1 — быстрый откат. 21 — глубже.</p>
                            @error('pullback_ma_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_touch_atr_mult" class="block text-xs font-medium text-zinc-400">Зона касания MA (× ATR)</label>
                            <input type="number" name="pullback_touch_atr_mult" id="pullback_touch_atr_mult" min="0.05" max="2" step="0.01" required
                                value="{{ old('pullback_touch_atr_mult', $cfg['pullback_touch_atr_mult'] ?? 0.35) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">Насколько близко low/high к MA считается касанием. 0.5–0.7 — мягче.</p>
                            @error('pullback_touch_atr_mult')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_max_extension_atr" class="block text-xs font-medium text-zinc-400">Макс. удаление от MA (× ATR)</label>
                            <input type="number" name="pullback_max_extension_atr" id="pullback_max_extension_atr" min="0.5" max="5" step="0.1" required
                                value="{{ old('pullback_max_extension_atr', $cfg['pullback_max_extension_atr'] ?? 1.8) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <p class="text-[11px] text-zinc-500">Если в серии не было касания MA — вход всё равно разрешён, пока цена не дальше этого порога от MA. 2.0–2.5 — мягче.</p>
                            @error('pullback_max_extension_atr')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram</p>
                    <p class="text-[11px] text-zinc-500">Алерты отправляются при каждом срабатывании scan (cron <code class="text-zinc-400">bots:scan</code>). Сохраните token и chat_id, затем «Отправить тест».</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? true))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Без галочки сигналы пишутся в БД, но сообщения в Telegram не уходят.</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_trend" value="1"
                                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                                <span>
                                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                                    <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Inline-кнопка под алертом: callback в @trend1123_bot → LLM-анализ Chart Analyst в этом же чате. Нужен poll (кнопка ниже).</span>
                                </span>
                            </label>
                            @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_analyst_bot_id_trend" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                            @php $selectedAnalystIdTrend = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_trend"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="">Авто (первый Chart Analyst)</option>
                                @foreach ($chartAnalystBots ?? [] as $analystBot)
                                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystIdTrend === (string) $analystBot->id)>
                                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_analyst_llm_question_trend" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_trend" rows="2"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
                            <p class="text-[11px] text-zinc-500">Подставляется в RunTelegramAnalystAnalysisJob вместо шага «ваш вопрос».</p>
                            @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <p id="telegram-process-status-trend" class="text-xs text-zinc-500">Проверка poll…</p>
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_send_chart_trend" class="block text-xs font-medium text-zinc-400">Миниатюра M1 в алерте</label>
                            @php
                                $telegramSendChartTrend = old('telegram_send_chart');
                                if ($telegramSendChartTrend === null) {
                                    $telegramSendChartTrend = ($cfg['telegram_send_chart'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart" id="telegram_send_chart_trend" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChartTrend === '1')>Текст + миниатюра M1 (PNG)</option>
                                <option value="0" @selected((string) $telegramSendChartTrend === '0')>Только текст (без графика)</option>
                            </select>
                            <p class="text-[11px] text-zinc-500">PNG 640×360: последние свечи M1, зона серии, маркер последнего close. Отправляется отдельным сообщением перед текстом алерта.</p>
                            @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token',
                                'placeholder' => '8814450509:AAF...',
                                'hint' => 'Токен от @BotFather для бота-нотификатора. Пустое поле при сохранении — оставить текущий.',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
                            <p class="text-[11px] text-zinc-500">ID чата/канала куда слать алерты. Обязателен для доставки. Кнопка ниже подставит ID после /start боту.</p>
                            @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id из Telegram
                            </button>
                            <button type="button" id="telegram-test-btn"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-trend"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Если «Получить chat_id» возвращает пустой список — откройте
                        <a href="https://t.me/trend1123_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@trend1123_bot</a>,
                        нажмите Start, затем повторите.
                    </p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn');
                const testBtn = document.getElementById('telegram-test-btn');
                const restartBtn = document.getElementById('telegram-restart-btn-trend');
                const statusEl = document.getElementById('telegram-action-status');
                const processStatusEl = document.getElementById('telegram-process-status-trend');
                const chatInput = document.getElementById('telegram_chat_id');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
                const testUrl = @json(route('bots.telegram.test', $bot));
                const pollStatusUrl = @json(route('bots.telegram.strong-level-status', $bot));
                const pollRestartUrl = @json(route('bots.telegram.strong-level-restart', $bot));

                function setStatus(text, isError) {
                    if (!statusEl) return;
                    statusEl.textContent = text || '';
                    statusEl.className = 'text-xs ' + (isError ? 'text-rose-400' : 'text-emerald-300');
                }

                function renderProcessStatus(data) {
                    if (!processStatusEl || !data) return;
                    const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
                    const queue = data.queue_running ? 'queue ✓' : 'queue ✗';
                    const color = data.poll_running && data.queue_running ? 'text-emerald-300' : 'text-amber-300';
                    processStatusEl.textContent = 'Процессы: ' + poll + ', ' + queue;
                    processStatusEl.className = 'text-xs ' + color;
                }

                async function refreshProcessStatus() {
                    if (!processStatusEl) return;
                    try {
                        const res = await fetch(pollStatusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (res.ok && data.ok) renderProcessStatus(data);
                        else processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', false);
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(pollRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', false);
                            renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Запрос getUpdates…', false);
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — напишите боту /start', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + ')', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки, если меняли token/chat_id)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @else
            <div class="space-y-2 border-t border-zinc-800 pt-4">
                <label for="config_json" class="block text-sm font-medium text-zinc-300">Конфигурация (JSON)</label>
                <textarea name="config_json" id="config_json" rows="14" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('config_json', json_encode($configForJsonField, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') }}</textarea>
                <p class="text-xs text-zinc-500">Ключ <code class="text-zinc-400">price_action_dgt</code> задан выше отдельным полем и в этот JSON не входит.</p>
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
