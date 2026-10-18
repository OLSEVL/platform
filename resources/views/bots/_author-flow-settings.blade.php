{{-- Author Flow: оркестратор D/E/sweep/raid_pb; бот #45 не меняется. --}}
@php
    use App\Support\AuthorFlowConfig;
    use App\Support\BotInstrumentMarketScope;
    use App\Support\SettingsFieldHints;
    $defs = AuthorFlowConfig::defaultConfig();
    $scanIntervalSec = (int) old('scan_interval_sec', $cfg['scan_interval_sec'] ?? $defs['scan_interval_sec']);
    $scanWorkers = (int) old('scan_workers', $cfg['scan_workers'] ?? $defs['scan_workers']);
    $afTf = (int) old('timeframe', $cfg['timeframe'] ?? $defs['timeframe']);
    $afMarketScope = BotInstrumentMarketScope::normalize(
        old('market_scope', $cfg['market_scope'] ?? $defs['market_scope'] ?? BotInstrumentMarketScope::OTC)
    );
    $hint = static fn (string $field): string => SettingsFieldHints::bot($field, AuthorFlowConfig::BOT_TYPE);
    $flag = static fn (string $key) => (bool) old($key, $cfg[$key] ?? $defs[$key] ?? false);
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4" id="author-flow-settings">
    <div class="space-y-3 rounded-lg border border-zinc-700/80 bg-zinc-900/40 p-4 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-200">Author Flow</strong> — один сигнал на бар: приоритет E → D → raid_pb → sweep.
            Детекторы #45 и SRL вызываются как библиотеки; их боты не затрагиваются.
        </p>
        <p class="text-[11px] text-zinc-500">
            Спека: <code class="text-zinc-400">docs_need_release/video_analysis/AUTHOR_FLOW_DESIGN.md</code>.
            Live — <code class="text-zinc-400">bots:impulse-live</code>.
        </p>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Скан (инфра как #47)</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="af_scan_interval_sec" class="block text-xs font-medium text-zinc-400">Интервал скана (сек)</label>
            <select name="scan_interval_sec" id="af_scan_interval_sec" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (AuthorFlowConfig::ALLOWED_SCAN_INTERVAL_SEC as $interval)
                    <option value="{{ $interval }}" @selected($scanIntervalSec === $interval)>{{ $interval }} с</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('scan_interval_sec')])
        </div>
        <div class="space-y-1">
            <label for="af_scan_workers" class="block text-xs font-medium text-zinc-400">Воркеры пар</label>
            <select name="scan_workers" id="af_scan_workers" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (AuthorFlowConfig::ALLOWED_SCAN_WORKERS as $workers)
                    <option value="{{ $workers }}" @selected($scanWorkers === $workers)>{{ $workers }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2 flex items-start gap-2 rounded border border-zinc-800 bg-zinc-950/40 px-3 py-2">
            <input type="hidden" name="continuous_scan" value="0">
            <input type="checkbox" name="continuous_scan" id="af_continuous_scan" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500/40"
                @checked($flag('continuous_scan'))>
            <label for="af_continuous_scan" class="text-xs leading-snug text-zinc-300">
                <span class="font-medium text-zinc-200">Live daemon</span> —
                poll всех пар каждые N сек + фоновые воркеры.
            </label>
        </div>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Общие параметры</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
            <select name="timeframe" id="timeframe" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="{{ \App\Services\CandleService::TF_S15 }}" @selected($afTf === \App\Services\CandleService::TF_S15)>S15 — OTC</option>
                <option value="1" @selected($afTf === 1)>M1</option>
                <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($afTf === \App\Services\CandleService::TF_S30)>S30</option>
                <option value="{{ \App\Services\CandleService::TF_S5 }}" @selected($afTf === \App\Services\CandleService::TF_S5)>S5</option>
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
                    <option value="{{ $scopeValue }}" @selected($afMarketScope === $scopeValue)>{{ $scopeLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? $defs['min_confidence']) }}"
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
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Сценарии</p>
        @foreach ([
            'af_enable_breakout_e' => 'E · пробой + закреп',
            'af_enable_engulf_d' => 'D · 3-шаговое поглощение',
            'af_enable_raid_pb' => 'Raid → первая откатная',
            'af_enable_sweep' => 'Sweep fade',
            'af_enable_skop_score' => 'Skop boost score (не entry)',
        ] as $key => $label)
            <input type="hidden" name="{{ $key }}" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="{{ $key }}" value="1" @checked($flag($key))
                    class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                <span class="text-sm font-medium text-zinc-200">{{ $label }}</span>
            </label>
        @endforeach
    </div>

    <div class="space-y-3 rounded border border-emerald-900/40 bg-emerald-950/20 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-500/80">Контекст</p>
        <input type="hidden" name="af_require_structure" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_require_structure" value="1" @checked($flag('af_require_structure'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span class="text-sm font-medium text-zinc-200">Требовать alignment со структурой</span>
        </label>
        <input type="hidden" name="af_allow_range_entries" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_allow_range_entries" value="1" @checked($flag('af_allow_range_entries'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span class="text-sm font-medium text-zinc-200">Разрешить входы в range</span>
        </label>
        <input type="hidden" name="af_require_open_liquidity" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_require_open_liquidity" value="1" @checked($flag('af_require_open_liquidity'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500">
            <span class="text-sm font-medium text-zinc-200">Требовать открытую ликвидность</span>
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="af_structure_pivot" class="block text-xs font-medium text-zinc-400">Pivot структуры</label>
                <input type="number" name="af_structure_pivot" id="af_structure_pivot" min="1" max="10" required
                    value="{{ old('af_structure_pivot', $cfg['af_structure_pivot'] ?? $defs['af_structure_pivot']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_liquidity_lookback" class="block text-xs font-medium text-zinc-400">Lookback ликвидности</label>
                <input type="number" name="af_liquidity_lookback" id="af_liquidity_lookback" min="10" max="200" required
                    value="{{ old('af_liquidity_lookback', $cfg['af_liquidity_lookback'] ?? $defs['af_liquidity_lookback']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        </div>
    </div>

    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Параметры D (прокси EngulfingDetector)</p>
        <input type="hidden" name="af_eng_allow_classic" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_eng_allow_classic" value="1" @checked($flag('af_eng_allow_classic'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Классическое engulf</span>
        </label>
        <input type="hidden" name="af_eng_allow_same_color" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_eng_allow_same_color" value="1" @checked($flag('af_eng_allow_same_color'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Same-color engulf</span>
        </label>
        <input type="hidden" name="af_eng_require_level" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_eng_require_level" value="1" @checked($flag('af_eng_require_level'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Требовать уровень FH/FL</span>
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="af_eng_level_lookback" class="block text-xs font-medium text-zinc-400">Lookback фракталов D</label>
                <input type="number" name="af_eng_level_lookback" id="af_eng_level_lookback" min="10" max="200" required
                    value="{{ old('af_eng_level_lookback', $cfg['af_eng_level_lookback'] ?? $defs['af_eng_level_lookback']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_eng_level_touch_pct" class="block text-xs font-medium text-zinc-400">Касание уровня (%)</label>
                <input type="number" name="af_eng_level_touch_pct" id="af_eng_level_touch_pct" min="0.01" max="2" step="0.01" required
                    value="{{ old('af_eng_level_touch_pct', $cfg['af_eng_level_touch_pct'] ?? $defs['af_eng_level_touch_pct']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_eng_reaction_max_bars" class="block text-xs font-medium text-zinc-400">Окно реакции</label>
                <input type="number" name="af_eng_reaction_max_bars" id="af_eng_reaction_max_bars" min="1" max="8" required
                    value="{{ old('af_eng_reaction_max_bars', $cfg['af_eng_reaction_max_bars'] ?? $defs['af_eng_reaction_max_bars']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_breakout_hold_closes" class="block text-xs font-medium text-zinc-400">Closes закрепа E</label>
                <input type="number" name="af_breakout_hold_closes" id="af_breakout_hold_closes" min="1" max="4" required
                    value="{{ old('af_breakout_hold_closes', $cfg['af_breakout_hold_closes'] ?? $defs['af_breakout_hold_closes']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_srl_pivot_length" class="block text-xs font-medium text-zinc-400">Pivot SRL / E</label>
                <input type="number" name="af_srl_pivot_length" id="af_srl_pivot_length" min="1" max="10" required
                    value="{{ old('af_srl_pivot_length', $cfg['af_srl_pivot_length'] ?? $defs['af_srl_pivot_length']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        </div>
        <input type="hidden" name="af_eng_skip_if_left" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_eng_skip_if_left" value="1" @checked($flag('af_eng_skip_if_left'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Skip «уход» (D)</span>
        </label>
        <input type="hidden" name="af_eng_skip_if_breakout_hold" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_eng_skip_if_breakout_hold" value="1" @checked($flag('af_eng_skip_if_breakout_hold'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Skip D при закрепе E (детектор)</span>
        </label>
    </div>

    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">OTC-гэпы</p>
        <input type="hidden" name="af_gap_filter_enabled" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="af_gap_filter_enabled" value="1" @checked($flag('af_gap_filter_enabled'))
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
            <span class="text-sm font-medium text-zinc-200">Пропускать пару с частыми гэпами</span>
        </label>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-1">
                <label for="af_gap_lookback" class="block text-xs font-medium text-zinc-400">Окно баров</label>
                <input type="number" name="af_gap_lookback" id="af_gap_lookback" min="5" max="500" required
                    value="{{ old('af_gap_lookback', $cfg['af_gap_lookback'] ?? $defs['af_gap_lookback']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_gap_max_ratio" class="block text-xs font-medium text-zinc-400">Макс. доля гэпов</label>
                <input type="number" name="af_gap_max_ratio" id="af_gap_max_ratio" min="0.05" max="1" step="0.01" required
                    value="{{ old('af_gap_max_ratio', $cfg['af_gap_max_ratio'] ?? $defs['af_gap_max_ratio']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="af_gap_size_mult" class="block text-xs font-medium text-zinc-400">Порог гэпа × range</label>
                <input type="number" name="af_gap_size_mult" id="af_gap_size_mult" min="0.1" max="10" step="0.1" required
                    value="{{ old('af_gap_size_mult', $cfg['af_gap_size_mult'] ?? $defs['af_gap_size_mult']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        </div>
    </div>

    @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
</div>
