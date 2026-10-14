{{-- Engulfing Liquidity (#45): 3 шага + live-инфра как Impulse LVL (#47) + фильтры со стримов. --}}
@php
    use App\Support\BotInstrumentMarketScope;
    use App\Support\EngulfingLiquidityConfig;
    use App\Support\SettingsFieldHints;
    $defs = EngulfingLiquidityConfig::defaultConfig();
    $scanIntervalSec = (int) old('scan_interval_sec', $cfg['scan_interval_sec'] ?? $defs['scan_interval_sec']);
    $scanWorkers = (int) old('scan_workers', $cfg['scan_workers'] ?? $defs['scan_workers']);
    $engTf = (int) old('timeframe', $cfg['timeframe'] ?? $defs['timeframe']);
    $engMarketScope = BotInstrumentMarketScope::normalize(
        old('market_scope', $cfg['market_scope'] ?? $defs['market_scope'] ?? BotInstrumentMarketScope::ALL)
    );
    $hint = static fn (string $field): string => SettingsFieldHints::bot($field, EngulfingLiquidityConfig::BOT_TYPE);
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4" id="engulfing-liquidity-settings">
    <div class="space-y-3 rounded-lg border border-zinc-700/80 bg-zinc-900/40 p-4 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-200">Поглощение · сценарий D</strong> — engulf → реакция → контр-поглощение.
            Вход на close шага 3. Со стримов 28–29.09: уровень FH/FL, окно реакции, skip «уход» и пробой+закреп.
        </p>
        <p class="text-[11px] text-zinc-500">
            Оверлей ENGULF 1/2/3 —
            <a href="{{ route('bots.indicators.engulfing-liquidity.edit', $bot) }}" class="text-blue-400 hover:underline">Engulfing Liquidity</a>.
            Live daemon — как у бота 47 (<code class="text-zinc-400">bots:impulse-live</code>).
        </p>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Скан (инфра как #47)</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="eng_scan_interval_sec" class="block text-xs font-medium text-zinc-400">Интервал скана (сек)</label>
            <select name="scan_interval_sec" id="eng_scan_interval_sec" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (EngulfingLiquidityConfig::ALLOWED_SCAN_INTERVAL_SEC as $interval)
                    <option value="{{ $interval }}" @selected($scanIntervalSec === $interval)>{{ $interval }} с</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('scan_interval_sec')])
        </div>
        <div class="space-y-1">
            <label for="eng_scan_workers" class="block text-xs font-medium text-zinc-400">Воркеры пар</label>
            <select name="scan_workers" id="eng_scan_workers" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (EngulfingLiquidityConfig::ALLOWED_SCAN_WORKERS as $workers)
                    <option value="{{ $workers }}" @selected($scanWorkers === $workers)>{{ $workers }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2 flex items-start gap-2 rounded border border-zinc-800 bg-zinc-950/40 px-3 py-2">
            <input type="hidden" name="continuous_scan" value="0">
            <input type="checkbox" name="continuous_scan" id="eng_continuous_scan" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500/40"
                @checked((bool) old('continuous_scan', $cfg['continuous_scan'] ?? $defs['continuous_scan'] ?? true))>
            <label for="eng_continuous_scan" class="text-xs leading-snug text-zinc-300">
                <span class="font-medium text-zinc-200">Live daemon</span> —
                poll всех пар каждые N сек + фоновые воркеры. Выкл. = batch <code class="text-zinc-500">bots:scan</code>.
            </label>
        </div>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Общие параметры</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
            <select name="timeframe" id="timeframe" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="{{ \App\Services\CandleService::TF_S15 }}" @selected($engTf === \App\Services\CandleService::TF_S15)>S15 — OTC</option>
                <option value="1" @selected($engTf === 1)>M1</option>
                <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($engTf === \App\Services\CandleService::TF_S30)>S30</option>
                <option value="{{ \App\Services\CandleService::TF_S5 }}" @selected($engTf === \App\Services\CandleService::TF_S5)>S5</option>
            </select>
        </div>
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
            <input type="number" name="count" id="count" min="30" max="5000" required
                value="{{ old('count', $cfg['count'] ?? $defs['count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
            <input type="number" name="expiry_sec" id="expiry_sec" min="15" max="900" required
                value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? $defs['expiry_sec']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
            <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                value="{{ old('min_payout', $cfg['min_payout'] ?? $defs['min_payout']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="market_scope" class="block text-xs font-medium text-zinc-400">Рынок пар</label>
            <select name="market_scope" id="market_scope" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (BotInstrumentMarketScope::labels() as $scopeValue => $scopeLabel)
                    <option value="{{ $scopeValue }}" @selected($engMarketScope === $scopeValue)>{{ $scopeLabel }}</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('market_scope')])
        </div>
        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? $defs['stale_after_sec']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
    </div>

    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Типы поглощения (шаг 1)</p>
        <input type="hidden" name="eng_allow_classic" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_allow_classic" value="1" @checked(old('eng_allow_classic', $cfg['eng_allow_classic'] ?? true))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span><span class="text-sm font-medium text-zinc-200">Классическое engulf</span><span class="mt-0.5 block text-[11px] text-zinc-500">Противоположный цвет.</span></span>
        </label>
        <input type="hidden" name="eng_allow_same_color" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_allow_same_color" value="1" @checked(old('eng_allow_same_color', $cfg['eng_allow_same_color'] ?? true))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span><span class="text-sm font-medium text-zinc-200">Same-color engulf</span><span class="mt-0.5 block text-[11px] text-zinc-500">Тот же цвет + снятие high/low соседа.</span></span>
        </label>
    </div>

    <div class="space-y-3 rounded border border-emerald-900/40 bg-emerald-950/20 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-500/80">Со стримов · уровень и фильтры</p>
        <input type="hidden" name="eng_require_level" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_require_level" value="1"
                @checked(old('eng_require_level', $cfg['eng_require_level'] ?? $defs['eng_require_level']))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span><span class="text-sm font-medium text-zinc-200">Требовать уровень FH/FL</span><span class="mt-0.5 block text-[11px] text-zinc-500">Шаг 1 у фрактала (как на стриме «у уровня»).</span></span>
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="eng_level_lookback" class="block text-xs font-medium text-zinc-400">Lookback фракталов</label>
                <input type="number" name="eng_level_lookback" id="eng_level_lookback" min="10" max="200" required
                    value="{{ old('eng_level_lookback', $cfg['eng_level_lookback'] ?? $defs['eng_level_lookback']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="eng_level_touch_pct" class="block text-xs font-medium text-zinc-400">Касание уровня (%)</label>
                <input type="number" name="eng_level_touch_pct" id="eng_level_touch_pct" min="0.01" max="2" step="0.01" required
                    value="{{ old('eng_level_touch_pct', $cfg['eng_level_touch_pct'] ?? $defs['eng_level_touch_pct']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="eng_reaction_max_bars" class="block text-xs font-medium text-zinc-400">Окно реакции (баров)</label>
                <input type="number" name="eng_reaction_max_bars" id="eng_reaction_max_bars" min="1" max="8" required
                    value="{{ old('eng_reaction_max_bars', $cfg['eng_reaction_max_bars'] ?? $defs['eng_reaction_max_bars']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="eng_breakout_hold_closes" class="block text-xs font-medium text-zinc-400">Closes для закрепа E</label>
                <input type="number" name="eng_breakout_hold_closes" id="eng_breakout_hold_closes" min="1" max="4" required
                    value="{{ old('eng_breakout_hold_closes', $cfg['eng_breakout_hold_closes'] ?? $defs['eng_breakout_hold_closes']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        </div>
        <input type="hidden" name="eng_skip_if_left" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_skip_if_left" value="1"
                @checked(old('eng_skip_if_left', $cfg['eng_skip_if_left'] ?? $defs['eng_skip_if_left']))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span><span class="text-sm font-medium text-zinc-200">Skip «уход»</span><span class="mt-0.5 block text-[11px] text-zinc-500">2+ свечи в сторону шага 1 без реакции — не входим (Ihj 08:50).</span></span>
        </label>
        <input type="hidden" name="eng_skip_if_breakout_hold" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_skip_if_breakout_hold" value="1"
                @checked(old('eng_skip_if_breakout_hold', $cfg['eng_skip_if_breakout_hold'] ?? $defs['eng_skip_if_breakout_hold']))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span><span class="text-sm font-medium text-zinc-200">Skip пробой+закреп (E)</span><span class="mt-0.5 block text-[11px] text-zinc-500">Close закрепился за уровнем → не D (Ihj 36:31 / Vun 15:36).</span></span>
        </label>
    </div>

    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">OTC-гэпы и сессия</p>
        <input type="hidden" name="eng_gap_filter_enabled" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_gap_filter_enabled" value="1" @checked(old('eng_gap_filter_enabled', $cfg['eng_gap_filter_enabled'] ?? false))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span><span class="text-sm font-medium text-zinc-200">Пропускать пару с частыми гэпами</span></span>
        </label>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-1">
                <label for="eng_gap_lookback" class="block text-xs font-medium text-zinc-400">Окно баров</label>
                <input type="number" name="eng_gap_lookback" id="eng_gap_lookback" min="5" max="500" required
                    value="{{ old('eng_gap_lookback', $cfg['eng_gap_lookback'] ?? 40) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="eng_gap_max_ratio" class="block text-xs font-medium text-zinc-400">Макс. доля гэпов</label>
                <input type="number" name="eng_gap_max_ratio" id="eng_gap_max_ratio" min="0.05" max="1" step="0.01" required
                    value="{{ old('eng_gap_max_ratio', $cfg['eng_gap_max_ratio'] ?? 0.35) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="eng_gap_size_mult" class="block text-xs font-medium text-zinc-400">Порог гэпа × range</label>
                <input type="number" name="eng_gap_size_mult" id="eng_gap_size_mult" min="0.1" max="10" step="0.1" required
                    value="{{ old('eng_gap_size_mult', $cfg['eng_gap_size_mult'] ?? 0.5) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        </div>
        <input type="hidden" name="eng_skip_martingale_if_session_green" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="eng_skip_martingale_if_session_green" value="1"
                @checked(old('eng_skip_martingale_if_session_green', $cfg['eng_skip_martingale_if_session_green'] ?? false))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span><span class="text-sm font-medium text-zinc-200">Не мартингейл, если сессия в плюсе</span></span>
        </label>
    </div>

    @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
</div>
