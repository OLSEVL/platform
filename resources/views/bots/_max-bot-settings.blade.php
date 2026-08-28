{{-- MaxBot: ratio тел к историческому максимуму + список стратегий. --}}
@php
    use App\Support\MaxBotConfig;
    use App\Support\SettingsFieldHints;
    $defs = MaxBotConfig::defaultConfig();
    $strategies = old('strategies', $cfg['strategies'] ?? $defs['strategies']);
    if (! is_array($strategies) || $strategies === []) {
        $strategies = $defs['strategies'];
    }
    $timeframeSec = (int) old('timeframe_sec', $cfg['timeframe_sec'] ?? $defs['timeframe_sec']);
    $scanIntervalSec = (int) old('scan_interval_sec', $cfg['scan_interval_sec'] ?? $defs['scan_interval_sec']);
    $hint = static fn (string $field): string => SettingsFieldHints::bot($field, MaxBotConfig::BOT_TYPE);
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4" id="max-bot-settings">
    <div class="space-y-3 rounded-lg border border-zinc-700/80 bg-zinc-900/40 p-4 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-200">MaxBot</strong> ищет <em>аномально крупное тело</em> относительно недавней истории.
            Алгоритм для каждого правила:
        </p>
        <ol class="list-decimal space-y-1.5 pl-5 text-[12px] leading-relaxed text-zinc-400">
            <li>В хвосте графика должна быть серия из <strong class="text-zinc-300">N</strong> свечей одного цвета (зелёных или красных).</li>
            <li>Суммируются тела последних <strong class="text-zinc-300">measured_candles</strong> свечей этой серии.</li>
            <li>В окне <strong class="text-zinc-300">lookback_bars</strong> <em>до</em> этого хвоста ищется максимальная сумма тел такого же одноцветного блока той же длины.</li>
            <li>Считается <strong class="text-zinc-300">ratio_pct</strong> = (сумма тел ÷ эталонный максимум) × 100. Если ratio ≥ <strong class="text-zinc-300">threshold_pct</strong> — сигнал.</li>
            <li>Стратегии проверяются <strong class="text-zinc-300">сверху вниз</strong>; первая сработавшая побеждает.</li>
        </ol>
        <p class="text-[11px] text-zinc-500">
            Пример: порог 120%, measured=2 — две последние зелёные свечи вместе на 20% «толще» любых двух зелёных подряд за последние 100 баров.
            Дефолты: scan 5 с, ТФ 60 с, live, пресеты 1:110% … 4:120%.
        </p>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Общие параметры</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="timeframe_sec" class="block text-xs font-medium text-zinc-400">Таймфрейм свечей (сек)</label>
            <select name="timeframe_sec" id="timeframe_sec" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (MaxBotConfig::ALLOWED_TIMEFRAME_SEC as $tf)
                    <option value="{{ $tf }}" @selected($timeframeSec === $tf)>{{ $tf }} с</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('timeframe_sec')])
        </div>
        <div class="space-y-1">
            <label for="max_bot_scan_interval_sec" class="block text-xs font-medium text-zinc-400">Интервал скана (сек)</label>
            <select name="scan_interval_sec" id="max_bot_scan_interval_sec" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (MaxBotConfig::ALLOWED_SCAN_INTERVAL_SEC as $interval)
                    <option value="{{ $interval }}" @selected($scanIntervalSec === $interval)>{{ $interval }} с</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('scan_interval_sec')])
        </div>
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Глубина истории (count)</label>
            <input type="number" name="count" id="count" min="40" max="5000" required
                value="{{ old('count', $cfg['count'] ?? $defs['count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('count')])
        </div>
        <div class="space-y-1">
            <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
            <input type="number" name="expiry_sec" id="expiry_sec" min="5" max="900" required
                value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? $defs['expiry_sec']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('expiry_sec')])
        </div>
        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('min_confidence')])
        </div>
        <div class="space-y-1">
            <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
            <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                value="{{ old('min_payout', $cfg['min_payout'] ?? $defs['min_payout']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('min_payout')])
        </div>
        <div class="space-y-1 sm:col-span-2">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть live-свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? $defs['stale_after_sec']) }}"
                class="w-full max-w-xs rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('stale_after_sec')])
        </div>
    </div>

    <div class="space-y-3">
        <div class="space-y-1">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Стратегии</p>
                <button type="button" id="max-bot-add-strategy"
                    class="rounded border border-zinc-600 px-3 py-1.5 text-xs text-zinc-200 hover:bg-zinc-800">
                    + Добавить стратегию
                </button>
            </div>
            @include('partials.field-hint', ['text' => $hint('max_bot_strategies_order')])
        </div>
        <div id="max-bot-strategies-list" class="space-y-3">
            @foreach ($strategies as $index => $strategy)
                @php
                    $s = is_array($strategy) ? $strategy : [];
                    $enabled = (bool) old('strategies.'.$index.'.enabled', $s['enabled'] ?? true);
                @endphp
                <div class="max-bot-strategy-row rounded-lg border border-zinc-700 bg-zinc-900/50 p-3" data-index="{{ $index }}">
                    <div class="mb-3 flex flex-wrap items-center gap-3 border-b border-zinc-800 pb-3">
                        <label class="flex items-center gap-2 text-sm text-zinc-300">
                            <input type="hidden" name="strategies[{{ $index }}][enabled]" value="0">
                            <input type="checkbox" name="strategies[{{ $index }}][enabled]" value="1"
                                class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked($enabled)>
                            Правило включено
                        </label>
                        <button type="button" class="max-bot-remove-strategy text-xs text-rose-400 hover:text-rose-300">Удалить</button>
                    </div>
                    @include('partials.field-hint', ['text' => $hint('max_bot_strategy_enabled')])
                    @include('bots._max-bot-strategy-fields', ['index' => $index, 's' => $s])
                </div>
            @endforeach
        </div>
        @error('strategies')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
    </div>

    @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
</div>

<template id="max-bot-strategy-template">
    <div class="max-bot-strategy-row rounded-lg border border-zinc-700 bg-zinc-900/50 p-3" data-index="__INDEX__">
        <div class="mb-3 flex flex-wrap items-center gap-3 border-b border-zinc-800 pb-3">
            <label class="flex items-center gap-2 text-sm text-zinc-300">
                <input type="hidden" name="strategies[__INDEX__][enabled]" value="0">
                <input type="checkbox" name="strategies[__INDEX__][enabled]" value="1" checked
                    class="rounded border-zinc-600 bg-zinc-800 text-blue-500">
                Правило включено
            </label>
            <button type="button" class="max-bot-remove-strategy text-xs text-rose-400 hover:text-rose-300">Удалить</button>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Название (ID)</label>
                <input type="text" name="strategies[__INDEX__][id]" maxlength="32" required value="new_rule"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Длина серии (streak_length)</label>
                <input type="number" name="strategies[__INDEX__][streak_length]" min="1" max="20" required value="1"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Режим серии (streak_mode)</label>
                <select name="strategies[__INDEX__][streak_mode]" required
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                    <option value="exact">exact — ровно N</option>
                    <option value="at_least" selected>at_least — N и более</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Свечей для измерения (measured_candles)</label>
                <input type="number" name="strategies[__INDEX__][measured_candles]" min="1" max="20" required value="1"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Окно истории (lookback_bars)</label>
                <input type="number" name="strategies[__INDEX__][lookback_bars]" min="2" max="500" required value="100"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Порог ratio (threshold_pct, %)</label>
                <input type="number" name="strategies[__INDEX__][threshold_pct]" min="1" max="500" step="0.1" required value="110"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Цвет серии (color)</label>
                <select name="strategies[__INDEX__][color]"
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                    <option value="" selected>любой</option>
                    <option value="green">green — только бычьи</option>
                    <option value="red">red — только медвежьи</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Момент сигнала (signal_timing)</label>
                <select name="strategies[__INDEX__][signal_timing]" required
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                    <option value="live" selected>live — на текущей свече</option>
                    <option value="closed">closed — после закрытия</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="block text-xs font-medium text-zinc-400">Направление сделки (direction)</label>
                <select name="strategies[__INDEX__][direction]" required
                    class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                    <option value="trend" selected>trend — по цвету импульса</option>
                    <option value="counter">counter — против импульса</option>
                </select>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    const list = document.getElementById('max-bot-strategies-list');
    const tpl = document.getElementById('max-bot-strategy-template');
    const addBtn = document.getElementById('max-bot-add-strategy');
    if (!list || !tpl || !addBtn) return;

    function nextIndex() {
        const rows = list.querySelectorAll('.max-bot-strategy-row');
        let max = -1;
        rows.forEach((row) => {
            const idx = parseInt(row.getAttribute('data-index') || '0', 10);
            if (idx > max) max = idx;
        });
        return max + 1;
    }

    function bindRemove(row) {
        const btn = row.querySelector('.max-bot-remove-strategy');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (list.querySelectorAll('.max-bot-strategy-row').length <= 1) {
                alert('Нужна хотя бы одна стратегия.');
                return;
            }
            row.remove();
        });
    }

    list.querySelectorAll('.max-bot-strategy-row').forEach(bindRemove);

    addBtn.addEventListener('click', () => {
        const index = nextIndex();
        const html = tpl.innerHTML.replaceAll('__INDEX__', String(index));
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const row = wrap.firstElementChild;
        if (!row) return;
        row.setAttribute('data-index', String(index));
        list.appendChild(row);
        bindRemove(row);
    });
})();
</script>
