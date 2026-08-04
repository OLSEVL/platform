{{-- Wave B CALL/PUT: импульсные паттерны (vola-adaptive / impulse). --}}
@php
    use App\Support\OsEngineWaveBBotConfig;
    use App\Support\SettingsFieldHints;
    $meta = OsEngineWaveBBotConfig::meta($bot->type);
    $defs = OsEngineWaveBBotConfig::defaultConfig($bot->type);
    $countMin = in_array($bot->type, ['vol_adaptive_candles', 'three_soldier_vol_adaptive'], true) ? 200 : 40;
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4">
    <div class="space-y-2 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-300">{{ $meta['title'] }}</strong> — {{ $meta['blurb'] }}
        </p>
        <p class="text-[11px] text-zinc-500">
            Сделка живёт ровно expiry_sec секунд (без стопа/трейлинга). ТФ — M1.
            Для адаптации по волатильности нужно много истории: примерно days × 1440 минутных свечей.
        </p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
            <input type="number" name="count" id="count" min="{{ $countMin }}" max="5000" required
                value="{{ old('count', $cfg['count'] ?? $defs['count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => SettingsFieldHints::bot('count')])
            @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
            <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => SettingsFieldHints::bot('expiry_sec')])
            @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>

        @if ($bot->type === 'vol_adaptive_candles')
            <div class="space-y-1">
                <label for="height_signal_candle" class="block text-xs font-medium text-zinc-400">Height signal candle % (запасной порог)</label>
                <input type="number" name="height_signal_candle" id="height_signal_candle" min="0" max="20" step="0.001" required
                    value="{{ old('height_signal_candle', $cfg['height_signal_candle'] ?? $defs['height_signal_candle']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('height_signal_candle')])
            </div>
            <div class="space-y-1">
                <label for="days_volatility_adaptive" class="block text-xs font-medium text-zinc-400">Days volatility adaptive</label>
                <input type="number" name="days_volatility_adaptive" id="days_volatility_adaptive" min="0" max="30" required
                    value="{{ old('days_volatility_adaptive', $cfg['days_volatility_adaptive'] ?? $defs['days_volatility_adaptive']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('days_volatility_adaptive')])
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label for="height_signal_candle_vola_percent" class="block text-xs font-medium text-zinc-400">Height signal candle volatility percent</label>
                <input type="number" name="height_signal_candle_vola_percent" id="height_signal_candle_vola_percent" min="0" max="100" step="0.1" required
                    value="{{ old('height_signal_candle_vola_percent', $cfg['height_signal_candle_vola_percent'] ?? $defs['height_signal_candle_vola_percent']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('height_signal_candle_vola_percent')])
            </div>
        @elseif ($bot->type === 'three_soldier_vol_adaptive')
            <div class="space-y-1">
                <label for="height_soldiers" class="block text-xs font-medium text-zinc-400">Height soldiers % (запасной)</label>
                <input type="number" name="height_soldiers" id="height_soldiers" min="0" max="20" step="0.001" required
                    value="{{ old('height_soldiers', $cfg['height_soldiers'] ?? $defs['height_soldiers']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('height_soldiers')])
            </div>
            <div class="space-y-1">
                <label for="min_height_one_soldier" class="block text-xs font-medium text-zinc-400">Min height one soldier % (запасной)</label>
                <input type="number" name="min_height_one_soldier" id="min_height_one_soldier" min="0" max="20" step="0.001" required
                    value="{{ old('min_height_one_soldier', $cfg['min_height_one_soldier'] ?? $defs['min_height_one_soldier']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('min_height_one_soldier')])
            </div>
            <div class="space-y-1">
                <label for="days_volatility_adaptive" class="block text-xs font-medium text-zinc-400">Days volatility adaptive</label>
                <input type="number" name="days_volatility_adaptive" id="days_volatility_adaptive" min="0" max="30" required
                    value="{{ old('days_volatility_adaptive', $cfg['days_volatility_adaptive'] ?? $defs['days_volatility_adaptive']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('days_volatility_adaptive')])
            </div>
            <div class="space-y-1">
                <label for="height_soldiers_vola_percent" class="block text-xs font-medium text-zinc-400">Height soldiers vola %</label>
                <input type="number" name="height_soldiers_vola_percent" id="height_soldiers_vola_percent" min="0" max="100" step="0.1" required
                    value="{{ old('height_soldiers_vola_percent', $cfg['height_soldiers_vola_percent'] ?? $defs['height_soldiers_vola_percent']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('height_soldiers_vola_percent')])
            </div>
            <div class="space-y-1 sm:col-span-2">
                <label for="min_height_one_soldier_vola_percent" class="block text-xs font-medium text-zinc-400">Min one soldier vola %</label>
                <input type="number" name="min_height_one_soldier_vola_percent" id="min_height_one_soldier_vola_percent" min="0" max="100" step="0.1" required
                    value="{{ old('min_height_one_soldier_vola_percent', $cfg['min_height_one_soldier_vola_percent'] ?? $defs['min_height_one_soldier_vola_percent']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('min_height_one_soldier_vola_percent')])
            </div>
        @elseif ($bot->type === 'custom_candles_impulse')
            <div class="space-y-1">
                <label for="candles_count_to_entry" class="block text-xs font-medium text-zinc-400">Candles count to entry</label>
                <input type="number" name="candles_count_to_entry" id="candles_count_to_entry" min="1" max="20" required
                    value="{{ old('candles_count_to_entry', $cfg['candles_count_to_entry'] ?? $defs['candles_count_to_entry']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('candles_count_to_entry')])
            </div>
            <div class="space-y-1">
                <label for="seconds_time_on_candles" class="block text-xs font-medium text-zinc-400">Seconds time on candles</label>
                <input type="number" name="seconds_time_on_candles" id="seconds_time_on_candles" min="30" max="3600" required
                    value="{{ old('seconds_time_on_candles', $cfg['seconds_time_on_candles'] ?? $defs['seconds_time_on_candles']) }}"
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                @include('partials.field-hint', ['text' => SettingsFieldHints::bot('seconds_time_on_candles')])
            </div>
        @endif

        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.56) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => SettingsFieldHints::bot('min_confidence', $bot->type)])
        </div>
        <div class="space-y-1">
            <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
            <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => SettingsFieldHints::bot('min_payout')])
        </div>
        <div class="space-y-1">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @include('partials.field-hint', ['text' => SettingsFieldHints::bot('stale_after_sec')])
        </div>
    </div>
    @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
</div>
