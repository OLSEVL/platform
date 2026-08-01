{{-- Wave A OsEngine→CALL/PUT: общие runtime + стратегия-специфичные поля. --}}
@php
    use App\Support\OsEngineWaveABotConfig;
    $meta = OsEngineWaveABotConfig::meta($bot->type);
    $defs = OsEngineWaveABotConfig::defaultConfig($bot->type);
@endphp
<div class="space-y-5 border-t border-zinc-800 pt-4">
    <div class="space-y-2 text-sm text-zinc-400">
        <p>
            <strong class="text-zinc-300">{{ $meta['title'] }}</strong> — {{ $meta['blurb'] }}
        </p>
        <p class="text-[11px] text-zinc-500">
            Выход OsEngine (trail/stop) не переносится — фиксируем expiry_sec. ТФ M1.
        </p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
            <input type="number" name="count" id="count" min="{{ $bot->type === 'pivot_points' ? 200 : 40 }}" max="5000" required
                value="{{ old('count', $cfg['count'] ?? $defs['count']) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>
        <div class="space-y-1">
            <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
            <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
        </div>

        @if ($bot->type === 'envelop_trend')
            <div class="space-y-1">
                <label for="length" class="block text-xs font-medium text-zinc-400">Length</label>
                <input type="number" name="length" id="length" min="2" max="500" required value="{{ old('length', $cfg['length'] ?? $defs['length']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="deviation" class="block text-xs font-medium text-zinc-400">Deviation (%)</label>
                <input type="number" name="deviation" id="deviation" min="0.01" max="20" step="0.01" required value="{{ old('deviation', $cfg['deviation'] ?? $defs['deviation']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        @elseif ($bot->type === 'price_channel_trade')
            <div class="space-y-1">
                <label for="length_up" class="block text-xs font-medium text-zinc-400">Length Up</label>
                <input type="number" name="length_up" id="length_up" min="2" max="500" required value="{{ old('length_up', $cfg['length_up'] ?? $defs['length_up']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="length_down" class="block text-xs font-medium text-zinc-400">Length Down</label>
                <input type="number" name="length_down" id="length_down" min="2" max="500" required value="{{ old('length_down', $cfg['length_down'] ?? $defs['length_down']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        @elseif ($bot->type === 'parabolic_sar_trade')
            <div class="space-y-1">
                <label for="step" class="block text-xs font-medium text-zinc-400">AF Step</label>
                <input type="number" name="step" id="step" min="0.001" max="1" step="0.001" required value="{{ old('step', $cfg['step'] ?? $defs['step']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="max_step" class="block text-xs font-medium text-zinc-400">Max AF</label>
                <input type="number" name="max_step" id="max_step" min="0.001" max="1" step="0.001" required value="{{ old('max_step', $cfg['max_step'] ?? $defs['max_step']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <label class="flex items-center gap-2 sm:col-span-2">
                <input type="checkbox" name="require_cross" value="1" class="rounded border-zinc-600" @checked(old('require_cross', ($cfg['require_cross'] ?? true) ? '1' : '') === '1' || (old('require_cross') === null && ($cfg['require_cross'] ?? true)))>
                <span class="text-sm text-zinc-300">Только кросс SAR (рекомендуется)</span>
            </label>
        @elseif ($bot->type === 'break_linreg_channel')
            <div class="space-y-1">
                <label for="period" class="block text-xs font-medium text-zinc-400">LR Period</label>
                <input type="number" name="period" id="period" min="5" max="500" required value="{{ old('period', $cfg['period'] ?? $defs['period']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="up_deviation" class="block text-xs font-medium text-zinc-400">Up deviation</label>
                <input type="number" name="up_deviation" id="up_deviation" min="0.1" max="10" step="0.1" required value="{{ old('up_deviation', $cfg['up_deviation'] ?? $defs['up_deviation']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="down_deviation" class="block text-xs font-medium text-zinc-400">Down deviation</label>
                <input type="number" name="down_deviation" id="down_deviation" min="0.1" max="10" step="0.1" required value="{{ old('down_deviation', $cfg['down_deviation'] ?? $defs['down_deviation']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="sma_length" class="block text-xs font-medium text-zinc-400">SMA length (фильтр)</label>
                <input type="number" name="sma_length" id="sma_length" min="2" max="500" required value="{{ old('sma_length', $cfg['sma_length'] ?? $defs['sma_length']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="sma_filter_position" value="1" class="rounded border-zinc-600" @checked(old('sma_filter_position', ($cfg['sma_filter_position'] ?? false) ? '1' : '') === '1')>
                <span class="text-sm text-zinc-300">SMA position filter</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="sma_filter_slope" value="1" class="rounded border-zinc-600" @checked(old('sma_filter_slope', ($cfg['sma_filter_slope'] ?? false) ? '1' : '') === '1')>
                <span class="text-sm text-zinc-300">SMA slope filter</span>
            </label>
        @elseif ($bot->type === 'pivot_points')
            <div class="space-y-1">
                <label for="pivot_period" class="block text-xs font-medium text-zinc-400">Pivot period</label>
                @php $pp = old('pivot_period', $cfg['pivot_period'] ?? 'Daily'); @endphp
                <select name="pivot_period" id="pivot_period" required class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    <option value="Daily" @selected($pp === 'Daily')>Daily</option>
                    <option value="Weekly" @selected($pp === 'Weekly')>Weekly</option>
                </select>
            </div>
        @elseif ($bot->type === 'sma_stochastic')
            <div class="space-y-1">
                <label for="sma_length" class="block text-xs font-medium text-zinc-400">SMA length</label>
                <input type="number" name="sma_length" id="sma_length" min="2" max="500" required value="{{ old('sma_length', $cfg['sma_length'] ?? $defs['sma_length']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="step_pct" class="block text-xs font-medium text-zinc-400">Step (% цены)</label>
                <input type="number" name="step_pct" id="step_pct" min="0" max="0.05" step="0.0001" required value="{{ old('step_pct', $cfg['step_pct'] ?? $defs['step_pct']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="stoch_period1" class="block text-xs font-medium text-zinc-400">Stoch period1</label>
                <input type="number" name="stoch_period1" id="stoch_period1" min="1" max="200" required value="{{ old('stoch_period1', $cfg['stoch_period1'] ?? $defs['stoch_period1']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="up_line" class="block text-xs font-medium text-zinc-400">Up line</label>
                <input type="number" name="up_line" id="up_line" min="50" max="100" step="1" required value="{{ old('up_line', $cfg['up_line'] ?? $defs['up_line']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="down_line" class="block text-xs font-medium text-zinc-400">Down line</label>
                <input type="number" name="down_line" id="down_line" min="0" max="50" step="1" required value="{{ old('down_line', $cfg['down_line'] ?? $defs['down_line']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <input type="hidden" name="stoch_period2" value="{{ old('stoch_period2', $cfg['stoch_period2'] ?? $defs['stoch_period2']) }}">
            <input type="hidden" name="stoch_period3" value="{{ old('stoch_period3', $cfg['stoch_period3'] ?? $defs['stoch_period3']) }}">
        @elseif ($bot->type === 'momentum_macd')
            <div class="space-y-1">
                <label for="momentum_length" class="block text-xs font-medium text-zinc-400">Momentum length</label>
                <input type="number" name="momentum_length" id="momentum_length" min="2" max="200" required value="{{ old('momentum_length', $cfg['momentum_length'] ?? $defs['momentum_length']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="macd_fast" class="block text-xs font-medium text-zinc-400">MACD fast</label>
                <input type="number" name="macd_fast" id="macd_fast" min="2" max="200" required value="{{ old('macd_fast', $cfg['macd_fast'] ?? $defs['macd_fast']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="macd_slow" class="block text-xs font-medium text-zinc-400">MACD slow</label>
                <input type="number" name="macd_slow" id="macd_slow" min="3" max="300" required value="{{ old('macd_slow', $cfg['macd_slow'] ?? $defs['macd_slow']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="macd_signal" class="block text-xs font-medium text-zinc-400">MACD signal</label>
                <input type="number" name="macd_signal" id="macd_signal" min="2" max="100" required value="{{ old('macd_signal', $cfg['macd_signal'] ?? $defs['macd_signal']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
        @elseif ($bot->type === 'parabolic_bollinger')
            <div class="space-y-1">
                <label for="length" class="block text-xs font-medium text-zinc-400">BB length</label>
                <input type="number" name="length" id="length" min="5" max="500" required value="{{ old('length', $cfg['length'] ?? $defs['length']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="deviation" class="block text-xs font-medium text-zinc-400">Deviation</label>
                <input type="number" name="deviation" id="deviation" min="0.1" max="10" step="0.1" required value="{{ old('deviation', $cfg['deviation'] ?? $defs['deviation']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="averaging_period" class="block text-xs font-medium text-zinc-400">Averaging period</label>
                <input type="number" name="averaging_period" id="averaging_period" min="2" max="200" required value="{{ old('averaging_period', $cfg['averaging_period'] ?? $defs['averaging_period']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="volatility_multi" class="block text-xs font-medium text-zinc-400">Volatility multi</label>
                <input type="number" name="volatility_multi" id="volatility_multi" min="0.01" max="5" step="0.01" required value="{{ old('volatility_multi', $cfg['volatility_multi'] ?? $defs['volatility_multi']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <input type="hidden" name="volatility_period" value="{{ old('volatility_period', $cfg['volatility_period'] ?? 'Day') }}">
        @elseif ($bot->type === 'parabolic_price_channel')
            <div class="space-y-1">
                <label for="length_up" class="block text-xs font-medium text-zinc-400">Length Up</label>
                <input type="number" name="length_up" id="length_up" min="5" max="500" required value="{{ old('length_up', $cfg['length_up'] ?? $defs['length_up']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="length_down" class="block text-xs font-medium text-zinc-400">Length Down</label>
                <input type="number" name="length_down" id="length_down" min="5" max="500" required value="{{ old('length_down', $cfg['length_down'] ?? $defs['length_down']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="averaging_period" class="block text-xs font-medium text-zinc-400">Averaging period</label>
                <input type="number" name="averaging_period" id="averaging_period" min="2" max="200" required value="{{ old('averaging_period', $cfg['averaging_period'] ?? $defs['averaging_period']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <div class="space-y-1">
                <label for="volatility_multi" class="block text-xs font-medium text-zinc-400">Volatility multi</label>
                <input type="number" name="volatility_multi" id="volatility_multi" min="0.01" max="5" step="0.01" required value="{{ old('volatility_multi', $cfg['volatility_multi'] ?? $defs['volatility_multi']) }}" class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
            </div>
            <input type="hidden" name="volatility_period" value="{{ old('volatility_period', $cfg['volatility_period'] ?? 'Day') }}">
        @endif

        <div class="space-y-1">
            <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0.56) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
            <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
        <div class="space-y-1">
            <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
            <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
        </div>
    </div>
    @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
</div>
