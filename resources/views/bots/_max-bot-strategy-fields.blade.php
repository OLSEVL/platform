{{--
    Поля одной стратегии MaxBot.
    @var int $index
    @var array<string, mixed> $s
--}}
@php
    use App\Support\SettingsFieldHints;
    $hint = static fn (string $field): string => SettingsFieldHints::bot($field, \App\Support\MaxBotConfig::BOT_TYPE);
@endphp
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Название (ID)</label>
        <input type="text" name="strategies[{{ $index }}][id]" maxlength="32" required
            value="{{ old('strategies.'.$index.'.id', $s['id'] ?? ('rule_'.($index + 1))) }}"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
        @include('partials.field-hint', ['text' => $hint('max_bot_strategy_id')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Длина серии (streak_length)</label>
        <input type="number" name="strategies[{{ $index }}][streak_length]" min="1" max="20" required
            value="{{ old('strategies.'.$index.'.streak_length', $s['streak_length'] ?? 1) }}"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
        @include('partials.field-hint', ['text' => $hint('max_bot_streak_length')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Режим серии (streak_mode)</label>
        @php $streakMode = old('strategies.'.$index.'.streak_mode', $s['streak_mode'] ?? 'at_least'); @endphp
        <select name="strategies[{{ $index }}][streak_mode]" required
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            <option value="exact" @selected($streakMode === 'exact')>exact — ровно N</option>
            <option value="at_least" @selected($streakMode === 'at_least')>at_least — N и более</option>
        </select>
        @include('partials.field-hint', ['text' => $hint('max_bot_streak_mode')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Свечей для измерения (measured_candles)</label>
        <input type="number" name="strategies[{{ $index }}][measured_candles]" min="1" max="20" required
            value="{{ old('strategies.'.$index.'.measured_candles', $s['measured_candles'] ?? 1) }}"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
        @include('partials.field-hint', ['text' => $hint('max_bot_measured_candles')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Окно истории (lookback_bars)</label>
        <input type="number" name="strategies[{{ $index }}][lookback_bars]" min="2" max="500" required
            value="{{ old('strategies.'.$index.'.lookback_bars', $s['lookback_bars'] ?? 100) }}"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
        @include('partials.field-hint', ['text' => $hint('max_bot_lookback_bars')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Порог ratio (threshold_pct, %)</label>
        <input type="number" name="strategies[{{ $index }}][threshold_pct]" min="1" max="500" step="0.1" required
            value="{{ old('strategies.'.$index.'.threshold_pct', $s['threshold_pct'] ?? 110) }}"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
        @include('partials.field-hint', ['text' => $hint('max_bot_threshold_pct')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Цвет серии (color)</label>
        @php $color = old('strategies.'.$index.'.color', $s['color'] ?? ''); @endphp
        <select name="strategies[{{ $index }}][color]"
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            <option value="" @selected($color === '' || $color === 'any')>любой</option>
            <option value="green" @selected($color === 'green')>green — только бычьи</option>
            <option value="red" @selected($color === 'red')>red — только медвежьи</option>
        </select>
        @include('partials.field-hint', ['text' => $hint('max_bot_color')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Момент сигнала (signal_timing)</label>
        @php $timing = old('strategies.'.$index.'.signal_timing', $s['signal_timing'] ?? 'live'); @endphp
        <select name="strategies[{{ $index }}][signal_timing]" required
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            <option value="live" @selected($timing === 'live')>live — на текущей свече</option>
            <option value="closed" @selected($timing === 'closed')>closed — после закрытия</option>
        </select>
        @include('partials.field-hint', ['text' => $hint('max_bot_signal_timing')])
    </div>
    <div class="space-y-1">
        <label class="block text-xs font-medium text-zinc-400">Направление сделки (direction)</label>
        @php $direction = old('strategies.'.$index.'.direction', $s['direction'] ?? 'trend'); @endphp
        <select name="strategies[{{ $index }}][direction]" required
            class="w-full rounded border border-zinc-700 bg-zinc-950 px-2 py-1.5 text-sm text-zinc-100">
            <option value="trend" @selected($direction === 'trend')>trend — по цвету импульса</option>
            <option value="counter" @selected($direction === 'counter')>counter — против импульса</option>
        </select>
        @include('partials.field-hint', ['text' => $hint('max_bot_direction')])
    </div>
</div>
