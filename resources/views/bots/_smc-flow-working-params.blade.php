{{-- Снимок реальных рычагов SMC Flow: бот + индикатор + статистика skip за сутки. --}}
@php
    /** @var array{bot:array<string,mixed>,indicator:array<string,mixed>,stats:array<string,mixed>} $smcFlowWorking */
    $smcBot = $smcFlowWorking['bot'];
    $smcInd = $smcFlowWorking['indicator'];
    $smcStats = $smcFlowWorking['stats'];
    $skipLabels = [
        'strategy_no_signal' => 'нет совпадения UT+3BR+OB на закрытой M1',
        'payout_below_min' => 'payout ниже порога',
        'not_enough_bars' => 'мало закрытых свечей',
        'stale_series' => 'котировки протухли',
        'dedupe' => 'повтор в окне экспирации',
        'open_trade_exists' => 'по паре уже есть OPEN сделка',
        'confidence_below_threshold' => 'confidence ниже порога',
    ];
@endphp
<div class="space-y-3 rounded border border-amber-900/50 bg-amber-950/20 p-4">
    <p class="text-sm font-medium text-amber-100">Почему сделок мало</p>
    <p class="text-[12px] leading-relaxed text-zinc-300">
        Вход — <strong class="text-zinc-100">логическое AND трёх фильтров на последней закрытой M1</strong>.
        Не «рядом с сигналом», а строго в одну минуту:
    </p>
    <ol class="list-decimal space-y-1 pl-5 text-[12px] text-zinc-300">
        <li><strong class="text-zinc-100">UT Bot</strong> — свежий Buy/Sell именно на этом баре (кросс ATR-stop). На следующем баре без нового кросса входа нет.</li>
        <li><strong class="text-zinc-100">3BR</strong> — confirm (?) или pattern (^/¡) той же стороны не старше {{ (int) $smcBot['confirm_max_bars'] }} баров.</li>
        <li><strong class="text-zinc-100">LuxAlgo OB</strong> — {{ $smcBot['require_ob'] ? 'вкл.: цена должна быть в зоне той же стороны за последние '.(int) $smcBot['ob_touch_max_bars'].' бара' : 'выкл.: зона не требуется' }}.</li>
    </ol>
    <p class="text-[11px] text-zinc-500">
        За 24ч: сигналов {{ (int) $smcStats['signals_24h'] }}, сделок {{ (int) $smcStats['trades_24h'] }},
        пропусков скана {{ (int) $smcStats['skips_24h'] }}.
        @if (($smcStats['skip_reasons']['strategy_no_signal'] ?? 0) > 0)
            Почти все пропуски — <span class="font-mono text-zinc-400">strategy_no_signal</span>
            ({{ (int) $smcStats['skip_reasons']['strategy_no_signal'] }}): на закрытой свече не сошлись все три фильтра.
        @endif
    </p>
    @if ($smcStats['skip_reasons'] !== [])
        <ul class="text-[11px] text-zinc-500">
            @foreach ($smcStats['skip_reasons'] as $reason => $cnt)
                <li><span class="font-mono text-zinc-400">{{ $reason }}</span> — {{ $skipLabels[$reason] ?? $reason }}: {{ $cnt }}</li>
            @endforeach
        </ul>
    @endif
    <p class="text-[11px] text-zinc-500">
        Чтобы сигналов стало больше: снять «Требовать LuxAlgo Order Block», расширить окно 3BR,
        понизить min_payout (сейчас {{ rtrim(rtrim(number_format((float) $smcBot['min_payout'], 1, '.', ''), '0'), '.') }}%),
        уменьшить UT Key на странице индикатора (сейчас {{ rtrim(rtrim(number_format((float) $smcInd['ut_key_value'], 2, '.', ''), '0'), '.') }} — чем больше, тем реже кросс).
    </p>
</div>

<div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-medium text-zinc-200">Рабочие параметры входа</p>
        <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-[11px] text-blue-400 hover:underline">Изменить UT / 3BR / OB индикатора</a>
    </div>
    <p class="text-[11px] text-zinc-500">
        Это не дефолты «на бумаге», а значения, с которыми сейчас идёт scan: config бота + merged-настройки индикатора SMC Flow.
    </p>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Этот бот</p>
            <dl class="space-y-0.5 text-[12px] text-zinc-300">
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">AND: UT свежий бар</dt><dd class="font-mono">да</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">AND: 3BR окно</dt><dd class="font-mono">{{ (int) $smcBot['confirm_max_bars'] }} бар</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">3BR pattern (^/¡)</dt><dd class="font-mono">{{ $smcBot['allow_pattern'] ? 'да' : 'нет' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">AND: требовать OB</dt><dd class="font-mono">{{ $smcBot['require_ob'] ? 'да' : 'нет' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">OB касание</dt><dd class="font-mono">{{ (int) $smcBot['ob_touch_max_bars'] }} бар</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">min payout</dt><dd class="font-mono">{{ rtrim(rtrim(number_format((float) $smcBot['min_payout'], 1, '.', ''), '0'), '.') }}%</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">min confidence</dt><dd class="font-mono">{{ rtrim(rtrim(number_format((float) $smcBot['min_confidence'], 2, '.', ''), '0'), '.') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">expiry / dedupe</dt><dd class="font-mono">{{ (int) $smcBot['expiry_sec'] }} с</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">скан</dt><dd class="font-mono">{{ (int) $smcBot['scan_interval_sec'] }} с</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">свечей M1 / мин. закрытых</dt><dd class="font-mono">{{ (int) $smcBot['count'] }} / {{ (int) $smcBot['min_closed_bars'] }}</dd></div>
            </dl>
        </div>
        <div>
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Индикатор SMC Flow</p>
            <dl class="space-y-0.5 text-[12px] text-zinc-300">
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">UT Key</dt><dd class="font-mono">{{ rtrim(rtrim(number_format((float) $smcInd['ut_key_value'], 2, '.', ''), '0'), '.') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">UT ATR period</dt><dd class="font-mono">{{ (int) $smcInd['ut_atr_period'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">3BR type</dt><dd class="font-mono">{{ $smcInd['three_br_type'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">3BR trend</dt><dd class="font-mono">{{ $smcInd['three_br_trend_type'] }} / {{ $smcInd['three_br_trend_filter'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">OB length</dt><dd class="font-mono">{{ (int) $smcInd['lux_ob_length'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">OB зон bull/bear</dt><dd class="font-mono">{{ (int) $smcInd['lux_ob_bull_count'] }} / {{ (int) $smcInd['lux_ob_bear_count'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">OB mitigation</dt><dd class="font-mono">{{ $smcInd['lux_ob_mitigation'] }}</dd></div>
            </dl>
        </div>
    </div>
</div>
