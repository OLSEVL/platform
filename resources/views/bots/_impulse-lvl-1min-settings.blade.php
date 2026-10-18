{{-- Impulse LVL 1min / S15: профили trim-mean + MAX + LVL. --}}
@php
    use App\Support\BotInstrumentMarketScope;
    use App\Support\ImpulseLvl1minConfig;
    use App\Support\SettingsFieldHints;
    $impulseBotType = (string) ($bot->type ?? ImpulseLvl1minConfig::BOT_TYPE);
    $isImpulseS15 = ImpulseLvl1minConfig::isS15($impulseBotType);
    $defs = ImpulseLvl1minConfig::defaultConfig($impulseBotType);
    $profiles = old('profiles', $cfg['profiles'] ?? $defs['profiles']);
    if (! is_array($profiles) || $profiles === []) {
        $profiles = $defs['profiles'];
    }
    $scanIntervalSec = (int) old('scan_interval_sec', $cfg['scan_interval_sec'] ?? $defs['scan_interval_sec']);
    $scanWorkers = (int) old('scan_workers', $cfg['scan_workers'] ?? $defs['scan_workers']);
    $impulseMarketScope = BotInstrumentMarketScope::normalize(
        old('market_scope', $cfg['market_scope'] ?? $defs['market_scope'] ?? BotInstrumentMarketScope::ALL)
    );
    $hint = static fn (string $field): string => SettingsFieldHints::bot($field, $impulseBotType);
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4" id="impulse-lvl-1min-settings">
    <div class="space-y-3 rounded-lg border border-zinc-700/80 bg-zinc-900/40 p-4 text-sm text-zinc-400">
        @if ($isImpulseS15)
            <p>
                <strong class="text-zinc-200">Impulse LVL S15</strong> — тот же алгоритм, что Impulse LVL 1min, на закрытых свечах S15 (15 с, dashboard 4015).
                Бот 47 на M1 не меняется.
            </p>
            <ol class="list-decimal space-y-1.5 pl-5 text-[12px] leading-relaxed text-zinc-400">
                <li>Среднее тело = trim из последних 100 <em>закрытых</em> S15 (формирующийся бар в среднее не входит).</li>
                <li>MAX и профили — как у 1min. Paper — одна сделка. Экспирация по умолчанию 15 с.</li>
                <li>LVL: S15 тело↔фитиль; M1/M5/M15 из БД (wick_tip на старших). Redis S5 live-скан складывает в текущий бакет S15 (open первого S5 + фитиль).</li>
                <li>Сторона входа — продолжение или отбой, как у 1min. Live-фитиль — галочка ниже; после сохранения перезапустите live.</li>
            </ol>
        @else
        <p>
            <strong class="text-zinc-200">Impulse LVL 1min</strong> — порт <code class="text-zinc-300">docs_need_release/1min</code>:
            импульс к trimmed mean или MAX-ratio, плюс касание swing-зоны LVL.
        </p>
        <ol class="list-decimal space-y-1.5 pl-5 text-[12px] leading-relaxed text-zinc-400">
            <li>Среднее тело = trim 10+10 из последних 100 <em>закрытых</em> M1 (live-бар в среднее не входит).</li>
            <li>MAX и каждый профиль на одном баре — отдельные алерты (как 1min). Paper — одна сделка, без пачки.</li>
            <li>MAX: самая длинная одноцветная серия vs исторический максимум (1→110%, 2→130%, иначе 120%).</li>
            <li>LVL: high/low импульса в зоне свинга. M1 тело↔фитиль; M5/M15/M30 — wick_tip. Live: текущий M5/M15/M30 собирается из M1 (как 1min). Старший ТФ побеждает дубли.</li>
            <li>Сторона входа по умолчанию — продолжение импульса (зелёный → CALL, красный → PUT). В поле ниже можно включить отбой. Доджи рвут серию. После закрытия M1 ещё раз гоняем её как prev. Открытая paper не режет алерты.</li>
        </ol>
        @endif
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Общие параметры</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="impulse_lvl_scan_interval_sec" class="block text-xs font-medium text-zinc-400">Интервал скана (сек)</label>
            <select name="scan_interval_sec" id="impulse_lvl_scan_interval_sec" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (ImpulseLvl1minConfig::ALLOWED_SCAN_INTERVAL_SEC as $interval)
                    <option value="{{ $interval }}" @selected($scanIntervalSec === $interval)>{{ $interval }} с</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('scan_interval_sec')])
        </div>
        <div class="space-y-1">
            <label for="impulse_lvl_scan_workers" class="block text-xs font-medium text-zinc-400">Воркеры пар (как SSID в 1min)</label>
            <select name="scan_workers" id="impulse_lvl_scan_workers" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (ImpulseLvl1minConfig::ALLOWED_SCAN_WORKERS as $workers)
                    <option value="{{ $workers }}" @selected($scanWorkers === $workers)>{{ $workers }}{{ $workers === 1 ? ' — по очереди' : ($workers === 6 ? ' — как 1min' : '') }}</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('scan_workers')])
        </div>
        <div class="sm:col-span-2 flex items-start gap-2 rounded border border-zinc-800 bg-zinc-950/40 px-3 py-2">
            <input type="hidden" name="continuous_scan" value="0">
            <input type="checkbox" name="continuous_scan" id="impulse_lvl_continuous_scan" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-emerald-500 focus:ring-emerald-500/40"
                @checked((bool) old('continuous_scan', $cfg['continuous_scan'] ?? $defs['continuous_scan'] ?? true))>
            <label for="impulse_lvl_continuous_scan" class="text-xs leading-snug text-zinc-300">
                <span class="font-medium text-zinc-200">Live daemon (updateBotM1)</span> —
                poll всех пар каждые N сек + фоновые воркеры. Выкл. = batch <code class="text-zinc-500">bots:scan</code>.
            </label>
        </div>
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Глубина истории {{ $isImpulseS15 ? 'S15' : 'M1' }} (count)</label>
            <input type="number" name="count" id="count" min="110" max="5000" required
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
        <div class="space-y-1">
            <label for="impulse_lvl_market_scope" class="block text-xs font-medium text-zinc-400">Рынок пар</label>
            <select name="market_scope" id="impulse_lvl_market_scope" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @foreach (BotInstrumentMarketScope::labels() as $scopeValue => $scopeLabel)
                    <option value="{{ $scopeValue }}" @selected($impulseMarketScope === $scopeValue)>{{ $scopeLabel }}</option>
                @endforeach
            </select>
            @include('partials.field-hint', ['text' => $hint('market_scope')])
        </div>
        <div class="space-y-1">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть live-свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? $defs['stale_after_sec']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('stale_after_sec')])
        </div>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="keep_stale_forming_m1" value="0">
            <input type="checkbox" name="keep_stale_forming_m1" id="keep_stale_forming_m1" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('keep_stale_forming_m1', $cfg['keep_stale_forming_m1'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Держать формирующуюся {{ $isImpulseS15 ? 'S15' : 'M1' }}</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('keep_stale_forming_m1') }}</span>
            </span>
        </label>
        <div class="space-y-1">
            <label for="trim_low_count" class="block text-xs font-medium text-zinc-400">Trim: отрезать тонких</label>
            <input type="number" name="trim_low_count" id="trim_low_count" min="0" max="90" required
                value="{{ old('trim_low_count', $cfg['trim_low_count'] ?? $defs['trim_low_count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('trim_low_count')])
        </div>
        <div class="space-y-1">
            <label for="trim_high_count" class="block text-xs font-medium text-zinc-400">Trim: отрезать толстых</label>
            <input type="number" name="trim_high_count" id="trim_high_count" min="0" max="90" required
                value="{{ old('trim_high_count', $cfg['trim_high_count'] ?? $defs['trim_high_count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('trim_high_count')])
        </div>
        <div class="space-y-1">
            <label for="occupancy_scope" class="block text-xs font-medium text-zinc-400">Занятость paper</label>
            <select name="occupancy_scope" id="occupancy_scope" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @php $occScope = old('occupancy_scope', $cfg['occupancy_scope'] ?? $defs['occupancy_scope'] ?? 'instrument'); @endphp
                <option value="instrument" @selected($occScope === 'instrument')>Только эта пара</option>
                <option value="bot" @selected($occScope === 'bot')>Любая пара этого бота</option>
            </select>
            @include('partials.field-hint', ['text' => $hint('occupancy_scope')])
        </div>
        <div class="space-y-1">
            <label for="m5_take" class="block text-xs font-medium text-zinc-400">Баров M5 для LVL</label>
            <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                value="{{ old('m5_take', $cfg['m5_take'] ?? $defs['m5_take']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('m5_take')])
        </div>
        <div class="space-y-1">
            <label for="m15_take" class="block text-xs font-medium text-zinc-400">Баров M15 для LVL</label>
            <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                value="{{ old('m15_take', $cfg['m15_take'] ?? $defs['m15_take']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('m15_take')])
        </div>
        @if ($isImpulseS15)
        <div class="space-y-1">
            <label for="m1_take" class="block text-xs font-medium text-zinc-400">Баров M1 для LVL</label>
            <input type="number" name="m1_take" id="m1_take" min="40" max="400" required
                value="{{ old('m1_take', $cfg['m1_take'] ?? $defs['m1_take'] ?? 120) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('m1_take')])
        </div>
        @else
        <div class="space-y-1">
            <label for="m30_take" class="block text-xs font-medium text-zinc-400">Баров M30 для LVL</label>
            <input type="number" name="m30_take" id="m30_take" min="10" max="200" required
                value="{{ old('m30_take', $cfg['m30_take'] ?? $defs['m30_take']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => $hint('m30_take')])
        </div>
        @endif
    </div>

    <div class="flex flex-wrap gap-6">
        <div class="w-full space-y-1 sm:w-auto sm:min-w-[18rem]">
            <label for="impulse_lvl_entry_side_mode" class="block text-xs font-medium text-zinc-400">Сторона входа</label>
            @php $entrySideMode = old('entry_side_mode', $cfg['entry_side_mode'] ?? $defs['entry_side_mode'] ?? ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION); @endphp
            <select name="entry_side_mode" id="impulse_lvl_entry_side_mode" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="continuation" @selected($entrySideMode === ImpulseLvl1minConfig::ENTRY_SIDE_CONTINUATION)>Продолжение импульса</option>
                <option value="bounce" @selected($entrySideMode === ImpulseLvl1minConfig::ENTRY_SIDE_BOUNCE)>Отбой от LVL (против импульса)</option>
            </select>
            @include('partials.field-hint', ['text' => $hint('entry_side_mode')])
        </div>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="lvl_filter" value="0">
            <input type="checkbox" name="lvl_filter" id="lvl_filter" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('lvl_filter', $cfg['lvl_filter'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Фильтр LVL</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('lvl_filter') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="max_enabled" value="0">
            <input type="checkbox" name="max_enabled" id="max_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('max_enabled', $cfg['max_enabled'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Блок MAX</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('max_enabled') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="signal_timing_closed" value="0">
            <input type="checkbox" name="signal_timing_closed" id="signal_timing_closed" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('signal_timing_closed', ($cfg['signal_timing'] ?? 'live') === 'closed'))>
            <span>
                <span class="font-medium text-zinc-200">Только закрытая {{ $isImpulseS15 ? 'S15' : 'M1' }}</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">По умолчанию live — текущая свеча. Галочка = closed, как бэктест.</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="max_only_longest" value="0">
            <input type="checkbox" name="max_only_longest" id="max_only_longest" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('max_only_longest', $cfg['max_only_longest'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">MAX только самая длинная</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('max_only_longest') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="eval_prev_candle" value="0">
            <input type="checkbox" name="eval_prev_candle" id="eval_prev_candle" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('eval_prev_candle', $cfg['eval_prev_candle'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Повтор prev</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('eval_prev_candle') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="overlay_forming_htf" value="0">
            <input type="checkbox" name="overlay_forming_htf" id="overlay_forming_htf" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('overlay_forming_htf', $cfg['overlay_forming_htf'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Живой HTF из {{ $isImpulseS15 ? 'S15' : 'M1' }}</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('overlay_forming_htf') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="live_candle_wick_signals" value="0">
            <input type="checkbox" name="live_candle_wick_signals" id="live_candle_wick_signals" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('live_candle_wick_signals', $cfg['live_candle_wick_signals'] ?? false))>
            <span>
                <span class="font-medium text-zinc-200">Фитиль живой {{ $isImpulseS15 ? 'S15' : 'M1' }}</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('live_candle_wick_signals') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="send_all_bar_alerts" value="0">
            <input type="checkbox" name="send_all_bar_alerts" id="send_all_bar_alerts" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('send_all_bar_alerts', $cfg['send_all_bar_alerts'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Все профили+MAX в алертах</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('send_all_bar_alerts') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="occupancy_blocks_paper" value="0">
            <input type="checkbox" name="occupancy_blocks_paper" id="occupancy_blocks_paper" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('occupancy_blocks_paper', $cfg['occupancy_blocks_paper'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Ждать закрытия paper</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('occupancy_blocks_paper') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="occupancy_blocks_alerts" value="0">
            <input type="checkbox" name="occupancy_blocks_alerts" id="occupancy_blocks_alerts" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('occupancy_blocks_alerts', $cfg['occupancy_blocks_alerts'] ?? false))>
            <span>
                <span class="font-medium text-zinc-200">Занятость режет алерты</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('occupancy_blocks_alerts') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2 text-sm text-zinc-300">
            <input type="hidden" name="paper_prefer_live" value="0">
            <input type="checkbox" name="paper_prefer_live" id="paper_prefer_live" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('paper_prefer_live', $cfg['paper_prefer_live'] ?? true))>
            <span>
                <span class="font-medium text-zinc-200">Paper с live, не с prev</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">{{ $hint('paper_prefer_live') }}</span>
            </span>
        </label>
    </div>

    <div class="space-y-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Профили</p>
        @include('partials.field-hint', ['text' => $hint('impulse_profile_enabled')])
        <div class="space-y-3">
            @foreach ($profiles as $index => $profile)
                @php
                    $p = is_array($profile) ? $profile : [];
                    $enabled = (bool) old('profiles.'.$index.'.enabled', $p['enabled'] ?? true);
                @endphp
                <div class="rounded-lg border border-zinc-700 bg-zinc-900/50 p-3">
                    <input type="hidden" name="profiles[{{ $index }}][id]" value="{{ $p['id'] ?? 'profile_'.$index }}">
                    <input type="hidden" name="profiles[{{ $index }}][multipliers_json]" value='@json($p["candle_multipliers"] ?? new stdClass)'>
                    <div class="mb-3 flex flex-wrap items-center gap-3 border-b border-zinc-800 pb-3">
                        <label class="flex items-center gap-2 text-sm text-zinc-300">
                            <input type="hidden" name="profiles[{{ $index }}][enabled]" value="0">
                            <input type="checkbox" name="profiles[{{ $index }}][enabled]" value="1"
                                class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked($enabled)>
                            <span class="text-base leading-none">{{ $p['emoji'] ?? '' }}</span>
                            {{ $p['label'] ?? ($p['id'] ?? 'профиль') }}
                            <span class="text-xs text-zinc-500">({{ $p['id'] ?? '' }})</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-400">
                            <input type="hidden" name="profiles[{{ $index }}][filter_enabled]" value="0">
                            <input type="checkbox" name="profiles[{{ $index }}][filter_enabled]" value="1"
                                class="rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('profiles.'.$index.'.filter_enabled', $p['filter_enabled'] ?? true))>
                            LVL
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-zinc-400">Кружок</label>
                            <input type="text" name="profiles[{{ $index }}][emoji]" maxlength="8"
                                value="{{ old('profiles.'.$index.'.emoji', $p['emoji'] ?? '') }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-zinc-400">Подпись</label>
                            <input type="text" name="profiles[{{ $index }}][label]" maxlength="32"
                                value="{{ old('profiles.'.$index.'.label', $p['label'] ?? '') }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-zinc-400">Свечей (N)</label>
                            <input type="number" name="profiles[{{ $index }}][candle_count]" min="1" max="8" required
                                value="{{ old('profiles.'.$index.'.candle_count', $p['candle_count'] ?? 1) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-zinc-400">Множитель к mean</label>
                            <input type="number" name="profiles[{{ $index }}][multiplier]" min="0.5" max="20" step="0.01" required
                                value="{{ old('profiles.'.$index.'.multiplier', $p['multiplier'] ?? 3) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
                            @include('partials.field-hint', ['text' => $hint('impulse_profile_multiplier')])
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @error('profiles')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
    </div>

    @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
</div>
