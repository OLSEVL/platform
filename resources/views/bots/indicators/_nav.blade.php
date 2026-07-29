{{-- Общая навигация для страниц индикаторов графика бота. --}}
<div class="flex flex-wrap items-center gap-2 text-sm">
    <a href="{{ route('bots.show', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">График</a>
    <a href="{{ route('bots.settings', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Настройки бота</a>
    <a href="{{ route('bots.indicators.order-blocks.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ордерблоки</a>
    <a href="{{ route('bots.indicators.fvg.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Имбалансы</a>
    <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Price Action (DGT)</a>
    <a href="{{ route('bots.indicators.liquidity-swings.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Liquidity Swings</a>
    <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMC Flow</a>
    <a href="{{ route('bots.indicators.bollinger.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Bollinger</a>
    <a href="{{ route('bots.indicators.rsi.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">RSI</a>
    <a href="{{ route('bots.indicators.sma.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMA</a>
    <a href="{{ route('bots.indicators.ema.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">EMA</a>
    <a href="{{ route('bots.indicators.atr.edit', $bot) }}"
        class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">ATR</a>
</div>
