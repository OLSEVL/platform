<div class="space-y-5 border-t border-zinc-800 pt-4">
    <div class="space-y-2 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-300">Candle Reversal + Confirm</strong> — отбитие от <strong>сильного уровня</strong>
            (конфлюенс DGT + SMC + swing) с настраиваемым подтверждением
            <strong>M5 rejection</strong> и <strong>M1 разворотных свечей</strong> (1–3).
        </p>
        <p class="text-[11px] text-zinc-500">
            Источники уровней: Price Action DGT (S/R, POC/VA), SMC Flow (rolling 4H/1D, reversal S/R, 3BR-зоны), swing H1/M15.
            Параметры индикаторов — на отдельных страницах:
            <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}" class="text-blue-400 hover:underline">Price Action DGT</a>,
            <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-blue-400 hover:underline">SMC Flow</a>.
        </p>
        <p class="text-[11px] text-zinc-500">
            Цепочка: сбор уровней → касание ценой → (опц.) M5 rejection → (опц.) N M1 в сторону отскока → paper-сделка на 3–5 мин.
            Пробои не торгуются — только отскок.
        </p>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные и скан</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 (count)</label>
            <input type="number" name="count" id="count" min="200" max="5000" required
                value="{{ old('count', $cfg['count'] ?? 500) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Сколько M1 загружать на пару. 400–500 — баланс точности уровней и скорости scan.</p>
            @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="min_closed_bars" class="block text-xs font-medium text-zinc-400">Мин. закрытых баров</label>
            <input type="number" name="min_closed_bars" id="min_closed_bars" min="80" max="2000" required
                value="{{ old('min_closed_bars', $cfg['min_closed_bars'] ?? 200) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Запас для rolling-уровней (4H/1D) и volume profile. Не ниже 150–200.</p>
            @error('min_closed_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
            <input type="number" name="expiry_sec" id="expiry_sec" min="120" max="600" required
                value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 300) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Длительность опциона и окно dedupe. 180 = 3 мин, 300 = 5 мин. Вход = момент scan.</p>
            @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
            <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                value="{{ old('min_payout', $cfg['min_payout'] ?? 82) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Пары с payout ниже порога пропускаются. 0 = все активные.</p>
            @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Пропуск «застывших» пар без свежего ingest.</p>
            @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
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
            <p class="text-[11px] text-zinc-500">58 — сбалансировано. 52–55 — чаще. 68+ — редко, нужен сильный конфлюенс.</p>
            @error('min_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="max_level_strength" class="block text-xs font-medium text-zinc-400">Макс. сила уровня (0 = выкл.)</label>
            <input type="number" name="max_level_strength" id="max_level_strength" min="0" max="100" step="1" required
                value="{{ old('max_level_strength', $cfg['max_level_strength'] ?? 0) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Потолок силы. Рекомендуется 90 — уровни 90+ статистически хуже. 0 = без потолка.</p>
            @error('max_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="min_sources" class="block text-xs font-medium text-zinc-400">Мин. источников конфлюенса</label>
            <input type="number" name="min_sources" id="min_sources" min="1" max="6" required
                value="{{ old('min_sources', $cfg['min_sources'] ?? 1) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">1 — один сильный источник. 2 — классический конфлюенс (реже).</p>
            @error('min_sources')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="cluster_atr_mult_m5" class="block text-xs font-medium text-zinc-400">Кластер уровней (× ATR M5)</label>
            <input type="number" name="cluster_atr_mult_m5" id="cluster_atr_mult_m5" min="0.05" max="1" step="0.01" required
                value="{{ old('cluster_atr_mult_m5', $cfg['cluster_atr_mult_m5'] ?? 0.28) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Радиус склейки близких цен в один кластер.</p>
            @error('cluster_atr_mult_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="touch_atr_mult_m1" class="block text-xs font-medium text-zinc-400">Зона касания (× ATR M1)</label>
            <input type="number" name="touch_atr_mult_m1" id="touch_atr_mult_m1" min="0.05" max="1.5" step="0.01" required
                value="{{ old('touch_atr_mult_m1', $cfg['touch_atr_mult_m1'] ?? 0.55) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Насколько близко цена к уровню. Главный рычаг частоты сигналов.</p>
            @error('touch_atr_mult_m1')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="h1_flat_strength_extra" class="block text-xs font-medium text-zinc-400">H1 флэт: доп. сила уровня</label>
            <input type="number" name="h1_flat_strength_extra" id="h1_flat_strength_extra" min="0" max="40" step="1" required
                value="{{ old('h1_flat_strength_extra', $cfg['h1_flat_strength_extra'] ?? 4) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Когда H1 во флэте и включён H1-фильтр (без полного запрета flat) — к min_level_strength добавляется этот запас.</p>
            @error('h1_flat_strength_extra')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Подтверждение M5 (rejection)</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="bounce_min_wick_ratio_m5" class="block text-xs font-medium text-zinc-400">M5: мин. тень rejection (0–1)</label>
            <input type="number" name="bounce_min_wick_ratio_m5" id="bounce_min_wick_ratio_m5" min="0.15" max="0.8" step="0.01" required
                value="{{ old('bounce_min_wick_ratio_m5', $cfg['bounce_min_wick_ratio_m5'] ?? 0.28) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">CALL — нижняя тень у support, PUT — верхняя у resistance. Работает при включённом M5 rejection.</p>
            @error('bounce_min_wick_ratio_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
    </div>
    {{-- Нужен хотя бы один из M5/M1; иначе анализатор всегда возвращает null. --}}
    <p class="text-[11px] text-amber-400/90">Важно: нельзя выключать и M5 rejection, и M1 разворот одновременно — бот перестанет давать сигналы.</p>
    @error('m1_reversal_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <input type="hidden" name="m5_rejection_enabled" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="m5_rejection_enabled" id="m5_rejection_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('m5_rejection_enabled', $cfg['m5_rejection_enabled'] ?? true))>
            <span>
                <span class="text-sm font-medium text-zinc-200">Требовать M5 rejection</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Последняя M5 — разворотная свеча с тенью у уровня. Снимите для раннего входа только по M1 (больше сигналов, выше риск).</span>
            </span>
        </label>
        <input type="hidden" name="m5_rejection_require_direction" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="m5_rejection_require_direction" id="m5_rejection_require_direction" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('m5_rejection_require_direction', $cfg['m5_rejection_require_direction'] ?? true))>
            <span>
                <span class="text-sm font-medium text-zinc-200">M5: направление тела в сторону отскока</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL — бычья M5, PUT — медвежья. Снимите — достаточно длинной тени без требования к телу.</span>
            </span>
        </label>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Подтверждение M1 (разворот)</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="m1_reversal_confirm_bars" class="block text-xs font-medium text-zinc-400">M1: свечей подтверждения</label>
            <input type="number" name="m1_reversal_confirm_bars" id="m1_reversal_confirm_bars" min="1" max="3" required
                value="{{ old('m1_reversal_confirm_bars', $cfg['m1_reversal_confirm_bars'] ?? 1) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Сколько последних закрытых M1 должны идти в сторону отскока. 1 — ранний вход, 2–3 — увереннее.</p>
            @error('m1_reversal_confirm_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="m1_reversal_min_wick_ratio" class="block text-xs font-medium text-zinc-400">M1: мин. тень (0–1)</label>
            <input type="number" name="m1_reversal_min_wick_ratio" id="m1_reversal_min_wick_ratio" min="0.10" max="0.70" step="0.01" required
                value="{{ old('m1_reversal_min_wick_ratio', $cfg['m1_reversal_min_wick_ratio'] ?? 0.22) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Минимальная тень в сторону сделки на проверяемых M1 (см. scope).</p>
            @error('m1_reversal_min_wick_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="m1_reversal_min_body_ratio" class="block text-xs font-medium text-zinc-400">M1: мин. тело (0–1)</label>
            <input type="number" name="m1_reversal_min_body_ratio" id="m1_reversal_min_body_ratio" min="0.05" max="0.80" step="0.01" required
                value="{{ old('m1_reversal_min_body_ratio', $cfg['m1_reversal_min_body_ratio'] ?? 0.25) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Фильтр доджи: каждая из N M1 должна иметь заметное тело.</p>
            @error('m1_reversal_min_body_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="m1_reversal_wick_scope" class="block text-xs font-medium text-zinc-400">M1: где проверять тени</label>
            @php $m1WickScope = old('m1_reversal_wick_scope', $cfg['m1_reversal_wick_scope'] ?? 'last'); @endphp
            <select name="m1_reversal_wick_scope" id="m1_reversal_wick_scope" required
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                <option value="last" @selected($m1WickScope === 'last')>Только последняя M1 (рекомендуется)</option>
                <option value="all" @selected($m1WickScope === 'all')>Все N свечей (строже)</option>
            </select>
            <p class="text-[11px] text-zinc-500"><strong>last</strong> — тени у последней из N M1. <strong>all</strong> — у каждой свечи блока.</p>
            @error('m1_reversal_wick_scope')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
    </div>
    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <input type="hidden" name="m1_reversal_enabled" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="m1_reversal_enabled" id="m1_reversal_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('m1_reversal_enabled', $cfg['m1_reversal_enabled'] ?? true))>
            <span>
                <span class="text-sm font-medium text-zinc-200">Требовать M1 подтверждение разворота</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">N последних M1 в сторону отскока. Минимум один блок (M5 или M1) должен быть включён.</span>
            </span>
        </label>
        <input type="hidden" name="m1_reversal_require_wick_dominance" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="m1_reversal_require_wick_dominance" id="m1_reversal_require_wick_dominance" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('m1_reversal_require_wick_dominance', $cfg['m1_reversal_require_wick_dominance'] ?? true))>
            <span>
                <span class="text-sm font-medium text-zinc-200">M1: доминирование «своей» тени</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Тень в сторону сделки длиннее противоположной (rejection).</span>
            </span>
        </label>
        <input type="hidden" name="require_h1_alignment" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="require_h1_alignment" id="require_h1_alignment_crc" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('require_h1_alignment', $cfg['require_h1_alignment'] ?? false))>
            <span>
                <span class="text-sm font-medium text-zinc-200">H1 bias не против входа</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL запрещён при H1 down, PUT — при H1 up. По умолчанию выкл.</span>
            </span>
        </label>
        <input type="hidden" name="block_h1_flat" value="0">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="block_h1_flat" id="block_h1_flat_crc" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked((bool) old('block_h1_flat', $cfg['block_h1_flat'] ?? false))>
            <span>
                <span class="text-sm font-medium text-zinc-200">Запрет входа при H1 = flat</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">При боковом H1 сделок нет. Жёстче, чем «доп. сила во флэте».</span>
            </span>
        </label>
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Старшие таймфреймы (MTF)</p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
            <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                value="{{ old('m5_take', $cfg['m5_take'] ?? 120) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">M5 rejection, ATR M5, режим отскок/пробой.</p>
            @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
            <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                value="{{ old('m15_take', $cfg['m15_take'] ?? 80) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Swing high/low M15 как доп. уровни.</p>
            @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
            <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                value="{{ old('h1_take', $cfg['h1_take'] ?? 48) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            <p class="text-[11px] text-zinc-500">Swing H1 + H1 bias.</p>
            @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
        <p class="text-sm font-medium text-zinc-300">Telegram</p>
        <p class="text-[11px] text-zinc-500">Формат как у Strong Level: текст + графики M5/M1, уровень, LLM-кнопка.</p>
        <label class="flex items-start gap-2">
            <input type="checkbox" name="telegram_enabled" id="telegram_enabled_crc" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
            <span>
                <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
            </span>
        </label>
        @include('bots._telegram-notify-result-field', [
            'cfg' => $cfg,
            'telegramNotifyResultId' => 'telegram_notify_result_crc',
        ])
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="telegram_notify_on_crc" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
                @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
                <select name="telegram_notify_on" id="telegram_notify_on_crc" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новый сигнал + paper-сделка</option>
                    <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять</option>
                </select>
                @error('telegram_notify_on')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="telegram_alert_format_crc" class="block text-xs font-medium text-zinc-400">Формат сообщения</label>
                @php $telegramAlertFormat = old('telegram_alert_format', $cfg['telegram_alert_format'] ?? 'brief'); @endphp
                <select name="telegram_alert_format" id="telegram_alert_format_crc" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="brief" @selected($telegramAlertFormat === 'brief')>Краткий</option>
                    <option value="detailed" @selected($telegramAlertFormat === 'detailed')>Развёрнутый</option>
                    <option value="card" @selected($telegramAlertFormat === 'card')>Как на карточке</option>
                </select>
                @error('telegram_alert_format')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="telegram_send_chart_crc" class="block text-xs font-medium text-zinc-400">График M1</label>
                @php $telegramSendChart = old('telegram_send_chart', ($cfg['telegram_send_chart'] ?? true) ? '1' : '0'); @endphp
                <select name="telegram_send_chart" id="telegram_send_chart_crc" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="1" @selected((string) $telegramSendChart === '1')>Текст + график M1</option>
                    <option value="0" @selected((string) $telegramSendChart === '0')>Только текст</option>
                </select>
                @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1">
                <label for="telegram_send_chart_m5_crc" class="block text-xs font-medium text-zinc-400">График M5</label>
                @php $telegramSendChartM5 = old('telegram_send_chart_m5', ($cfg['telegram_send_chart_m5'] ?? true) ? '1' : '0'); @endphp
                <select name="telegram_send_chart_m5" id="telegram_send_chart_m5_crc" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="1" @selected((string) $telegramSendChartM5 === '1')>Отправлять PNG M5</option>
                    <option value="0" @selected((string) $telegramSendChartM5 === '0')>Только M1</option>
                </select>
                @error('telegram_send_chart_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_crc" value="1"
                        class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                        @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                </label>
            </div>
            <div class="space-y-1">
                <label for="telegram_analyst_bot_id_crc" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                @php $selectedAnalystId = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_crc"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="">Авто (первый Chart Analyst)</option>
                    @foreach ($chartAnalystBots ?? [] as $analystBot)
                        <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystId === (string) $analystBot->id)>
                            #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label for="telegram_analyst_llm_question_crc" class="block text-xs font-medium text-zinc-400">Вопрос к LLM</label>
                <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_crc" rows="2"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>
            </div>
            <div class="space-y-1 sm:col-span-2">
                <p id="telegram-process-status-crc" class="text-xs text-zinc-500">Проверка poll…</p>
            </div>
            <div class="space-y-1">
                @include('bots._telegram-bot-token-field', [
                    'inputId' => 'telegram_bot_token_crc',
                    'hint' => 'Токен от @BotFather (@candle_reversal_confirm_bot). Пустое поле — оставить текущий.',
                ])
            </div>
            <div class="space-y-1">
                <label for="telegram_chat_id_crc" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                <input type="text" name="telegram_chat_id" id="telegram_chat_id_crc"
                    value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                    placeholder="123456789"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">
            </div>
                        @include('bots._telegram-auto-subscribe-field', [
                'cfg' => $cfg,
                'telegramAutoSubscribeId' => 'telegram_auto_subscribe_crc',
            ])
            <div class="space-y-1 flex flex-col justify-end gap-2">
                <button type="button" id="telegram-discover-btn-crc"
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">Получить chat_id</button>
                <button type="button" id="telegram-test-btn-crc"
                    class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">Отправить тест</button>
                <button type="button" id="telegram-restart-btn-crc"
                    class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">Перезапустить poll</button>
            </div>
        </div>
        <p id="telegram-action-status-crc" class="text-xs text-zinc-500"></p>
        <p class="text-[11px] text-zinc-500">
            Напишите боту
            <a href="https://t.me/candle_reversal_confirm_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@candle_reversal_confirm_bot</a>
            → /start.
        </p>
    </div>

    <div class="rounded border border-amber-900/40 bg-amber-950/20 px-4 py-3 text-[11px] text-amber-100/80">
        <p class="font-medium text-amber-200/90">Пресеты</p>
        <p class="mt-1"><strong>Агрессивный:</strong> M5 выкл, M1=1, touch 0.55+. <strong>Сбалансированный:</strong> M5+M1=1–2, wick 0.25. <strong>Консервативный:</strong> M5 wick 0.35, M1=2–3, wick_scope=all.</p>
    </div>
</div>
<script>
(function () {
    const discoverBtn = document.getElementById('telegram-discover-btn-crc');
    const testBtn = document.getElementById('telegram-test-btn-crc');
    const restartBtn = document.getElementById('telegram-restart-btn-crc');
    const statusEl = document.getElementById('telegram-action-status-crc');
    const processStatusEl = document.getElementById('telegram-process-status-crc');
    const chatInput = document.getElementById('telegram_chat_id_crc');
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
        } catch (e) { /* ignore */ }
    }
    refreshProcessStatus();

    if (restartBtn) {
        restartBtn.addEventListener('click', async function () {
            setStatus('Перезапуск…', false);
            restartBtn.disabled = true;
            try {
                const res = await fetch(slRestartUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                setStatus(data.ok ? (data.message || 'OK') : (data.error || 'Ошибка'), !data.ok);
                if (data.status) renderProcessStatus(data.status);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                restartBtn.disabled = false;
            }
        });
    }

    if (discoverBtn) {
        discoverBtn.addEventListener('click', async function () {
            setStatus('Ждём /start…', false);
            discoverBtn.disabled = true;
            try {
                const res = await fetch(discoverUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    setStatus(data.error || data.hint || 'Ошибка', true);
                    return;
                }
                const chats = data.chats || [];
                if (chats.length === 0) {
                    setStatus('Чатов не найдено — /start @candle_reversal_confirm_bot', true);
                    return;
                }
                if (chatInput && chats[0].id) chatInput.value = chats[0].id;
                setStatus('Найден chat_id: ' + chats[0].id, false);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            } finally {
                discoverBtn.disabled = false;
            }
        });
    }

    if (testBtn) {
        testBtn.addEventListener('click', async function () {
            setStatus('Отправка…', false);
            try {
                const res = await fetch(testUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await res.json();
                setStatus(data.ok ? 'Тест отправлен' : (data.error || 'Ошибка'), !data.ok);
            } catch (e) {
                setStatus('Сеть: ' + e.message, true);
            }
        });
    }
})();
</script>
