@extends('layouts.app')
@section('title', $bot->name.' — настройки')
@section('content')
@php
    $cfg = is_array($bot->config) ? $bot->config : [];
    // price_action_dgt задаётся на отдельной странице индикатора (indicator_settings), в общий JSON не включаем.
    $configForJsonField = $cfg;
    unset($configForJsonField['price_action_dgt']);
    $openrouterModels = isset($openrouterModels) && is_array($openrouterModels) ? $openrouterModels : [];
    $selectedOpenRouterModel = old('model', $cfg['model'] ?? 'openai/gpt-5.2');
    // Отдельно выносим дефолтный prompt в переменную, чтобы не ломать Blade
    // сложной строкой с JSON-скобками внутри inline-выражения.
    $openrouterDefaultPrompt = \App\Support\OpenRouterLlmConfig::defaultTradingSystemPrompt();
    $cfgForPrompt = $cfg;
    $cfgForPrompt['_bot_type'] = $bot->type;
    $openrouterAnalystDefaultPrompt = \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptForConfig($cfgForPrompt);
    $openrouterAnalystShortDefaultPrompt = \App\Support\OpenRouterChartLevelsParser::defaultAnalystSystemPromptShortForConfig($cfgForPrompt);
    $analystPromptMode = old('analyst_prompt_mode', $cfg['analyst_prompt_mode'] ?? \App\Support\OpenRouterLlmConfig::ANALYST_PROMPT_MODE_FULL);
    $isChartAnalystBot = \App\Support\ChartAnalystBotTypes::isAnalyst($bot->type);
    $isSrlAnalystBot = \App\Support\ChartAnalystBotTypes::isSrl($bot->type);
    $isEngulfAnalystBot = \App\Support\ChartAnalystBotTypes::isEngulf($bot->type);
    $isImpulseAnalystBot = \App\Support\ChartAnalystBotTypes::isImpulse($bot->type);
    $analystSmcFlowEnabled = $isChartAnalystBot && ! $isSrlAnalystBot && ! $isEngulfAnalystBot && ! $isImpulseAnalystBot
        && \App\Support\OpenRouterLlmConfig::analystSmcFlowEnabled($cfg);
    $analystSrlFlowEnabled = $isSrlAnalystBot;
    $analystEngulfFlowEnabled = $isEngulfAnalystBot;
@endphp
<div class="mx-auto w-full max-w-2xl space-y-6">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-100">Настройки бота</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ $bot->name }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ route('bots.show', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">График</a>
            <a href="{{ route('bots.indicators.order-blocks.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ордерблоки</a>
            <a href="{{ route('bots.indicators.fvg.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Имбалансы</a>
            <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Price Action</a>
            <a href="{{ route('bots.indicators.liquidity-swings.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Liquidity Swings</a>
            <a href="{{ route('bots.indicators.sweep-raid-liquidity.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Sweep / Raid Liquidity</a>
            <a href="{{ route('bots.indicators.engulfing-liquidity.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Engulfing Liquidity</a>
            <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMC Flow</a>
            <a href="{{ route('bots.indicators.strat-assistant.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Strat Assistant</a>
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
            <a href="{{ route('bots.indicators.macd.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">MACD</a>
            <a href="{{ route('bots.indicators.stochastic.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Stochastic</a>
            <a href="{{ route('bots.indicators.cci.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">CCI</a>
            <a href="{{ route('bots.indicators.vwma.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">VWMA</a>
            <a href="{{ route('bots.indicators.keltner.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Keltner</a>
            <a href="{{ route('bots.indicators.envelops.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Envelops</a>
            <a href="{{ route('bots.indicators.parabolic-sar.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Parabolic SAR</a>
            <a href="{{ route('bots.indicators.supertrend.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SuperTrend</a>
            <a href="{{ route('bots.indicators.adx.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">ADX</a>
            <a href="{{ route('bots.indicators.williams.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Williams %R</a>
            <a href="{{ route('bots.indicators.ao.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">AO</a>
            <a href="{{ route('bots.indicators.alligator.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Alligator</a>
            <a href="{{ route('bots.indicators.ichimoku.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ichimoku</a>
            <a href="{{ route('bots.indicators.price-channel.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Price Channel</a>
            <a href="{{ route('bots.indicators.nrtr.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">NRTR</a>
            <a href="{{ route('bots.indicators.hma.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">HMA</a>
            <a href="{{ route('bots.indicators.aroon.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Aroon</a>
            <a href="{{ route('bots.indicators.momentum.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Momentum</a>
            <a href="{{ route('bots.indicators.roc.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">ROC</a>
            <a href="{{ route('bots.indicators.osma.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">OsMa</a>
            <a href="{{ route('bots.indicators.trix.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Trix</a>
            <a href="{{ route('bots.indicators.cmo.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">CMO</a>
            <a href="{{ route('bots.indicators.demarker.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">DeMarker</a>
            <a href="{{ route('bots.indicators.rvi.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">RVI</a>
            <a href="{{ route('bots.indicators.ultimate.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ultimate Oscillator</a>
            <a href="{{ route('bots.indicators.smi.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMI</a>
            <a href="{{ route('bots.indicators.stddev.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">StdDev</a>
            <a href="{{ route('bots.indicators.sma-channel.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">SMA Channel</a>
            <a href="{{ route('bots.indicators.ivashov.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Ivashov Range</a>
            <a href="{{ route('bots.indicators.linreg.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Linear Regression</a>
            <a href="{{ route('bots.indicators.linreg-channel.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">LR Channel</a>
            <a href="{{ route('bots.indicators.kalman.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Kalman</a>
            <a href="{{ route('bots.indicators.fractal.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Fractal</a>
            <a href="{{ route('bots.indicators.donchian.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Donchian</a>
            <a href="{{ route('bots.indicators.stoch-rsi.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Stoch RSI</a>
            <a href="{{ route('bots.indicators.ac.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">AC</a>
            <a href="{{ route('bots.indicators.bulls-power.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Bulls Power</a>
            <a href="{{ route('bots.indicators.bears-power.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Bears Power</a>
            <a href="{{ route('bots.indicators.dpo.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">DPO</a>
            <a href="{{ route('bots.indicators.qstick.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">QStick</a>
            <a href="{{ route('bots.indicators.vh-filter.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">VHFilter</a>
            <a href="{{ route('bots.indicators.efficiency-ratio.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Efficiency Ratio</a>
            <a href="{{ route('bots.indicators.ravi.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">RAVI</a>
            <a href="{{ route('bots.indicators.cog.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">COG</a>
            <a href="{{ route('bots.indicators.pivot-floor.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Pivot Floor</a>
            <a href="{{ route('bots.indicators.mass-index.edit', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Mass Index</a>
            <a href="{{ route('bots.cards', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Карточки</a>
            <a href="{{ route('bots.activity', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Сигналы и сделки</a>
            <a href="{{ route('bots.logs', $bot) }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">Логи и метрики</a>
            <a href="{{ route('bots.index') }}"
                class="inline-flex items-center rounded border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-200 shadow-sm transition-colors hover:bg-zinc-700 hover:border-zinc-500 hover:text-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500/50">К списку</a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-800/60 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="{{ route('bots.settings.update', $bot) }}" class="space-y-6 rounded border border-zinc-800 bg-zinc-950/40 p-6">
        @csrf
        @method('PUT')

        <div class="space-y-2">
            <label for="name" class="block text-sm font-medium text-zinc-300">Имя бота</label>
            <input type="text" name="name" id="name" value="{{ old('name', $bot->name) }}" required maxlength="255"
                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
            @error('name')
                <p class="text-sm text-rose-400">{{ $message }}</p>
            @enderror
        </div>

        @unless($isChartAnalystBot)
        <div class="flex items-start gap-2">
            <input type="checkbox" name="is_active" id="is_active" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('is_active', $bot->is_active))>
            <label for="is_active" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Бот активен</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Участвует в плановом <code class="text-zinc-400">bots:scan</code>. Выключенный бот не анализирует пары и не создаёт сигналы/сделки.</span>
            </label>
        </div>

        {{-- Per-bot зеркало: профиль «Включить зеркалирование» — общий гейт; этот чекбокс — по боту.
             По умолчанию выкл. (нет ключа в config = false). --}}
        <div class="flex items-start gap-2">
            <input type="hidden" name="mirror_trades_enabled" value="0">
            <input type="checkbox" name="mirror_trades_enabled" id="mirror_trades_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('mirror_trades_enabled', $cfg['mirror_trades_enabled'] ?? false))>
            <label for="mirror_trades_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Включить зеркалирование на PO</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                    Отправлять paper-сделки этого бота на Pocket Option (SSID и сумма — в Профиль → Торговля).
                    Нужен также общий переключатель зеркала в профиле. По умолчанию выключено.
                </span>
            </label>
        </div>

        @if (! \App\Support\MaxBotConfig::is($bot->type) && ! \App\Support\ImpulseLvl1minConfig::is($bot->type))
            @include('bots._scan-interval-settings', ['cfg' => $cfg])
        @endif
        @else
        <p class="text-sm text-zinc-500">
            Плановый <code class="text-zinc-400">bots:scan</code> для Chart Analyst не запускается.
            Статус <strong class="text-zinc-300">active</strong> включается автоматически при галочке «Гибридный режим» ниже (для списка ботов и аналитики).
        </p>
        @endunless

        <div class="flex items-start gap-2">
            {{-- Эта настройка управляет UX графика:
                 когда включена, на странице графика работает tradingview-подобный кроссхейр
                 (вертикальная/горизонтальная линии + подписи времени и цены под курсором).
                 По умолчанию флаг включен для новых/старых ботов, если ключ еще не задан в config. --}}
            <input type="checkbox" name="tv_crosshair_enabled" id="tv_crosshair_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('tv_crosshair_enabled', $cfg['tv_crosshair_enabled'] ?? true))>
            <label for="tv_crosshair_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Кроссхейр как в TradingView</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">На странице графика бота: вертикальная/горизонтальная линии под курсором и подписи цены/времени. На торговую логику не влияет.</span>
            </label>
        </div>

        @unless($isChartAnalystBot)
        <div class="flex items-start gap-2">
            {{-- Персональный флаг логирования для этого конкретного бота:
                 когда включен — движок пишет детальные bot_analysis_runs (status/skip_reason/decision/latency),
                 когда выключен — проходы не попадают в таблицу bot_analysis_runs.
                 Дефолт = true для обратной совместимости со старыми конфигами, где ключа ещё нет. --}}
            <input type="checkbox" name="analysis_runs_logging_enabled" id="analysis_runs_logging_enabled" value="1"
                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                @checked(old('analysis_runs_logging_enabled', $cfg['analysis_runs_logging_enabled'] ?? true))>
            <label for="analysis_runs_logging_enabled" class="text-sm text-zinc-300">
                <span class="font-medium text-zinc-200">Диагностические логи scan</span>
                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Пишет в <code class="text-zinc-400">bot_analysis_runs</code> причину пропуска по каждой паре (not_enough_bars, strategy_no_signal, confidence_below_threshold, dedupe…). Смотреть на странице «Логи и метрики». Увеличивает нагрузку на БД.</span>
            </label>
        </div>
        @else
        <p class="text-sm text-zinc-500">История запросов сохраняется в <code class="text-zinc-400">bot_llm_logs</code> (страница «Логи и метрики»).</p>
        @endunless

        <div class="space-y-1">
            <span class="block text-sm font-medium text-zinc-300">Тип стратегии</span>
            <p class="text-sm text-zinc-500 font-mono">{{ $bot->type }}</p>
        </div>

        @if ($bot->type === 'three_candles_in_row')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Three Candles in Row</strong> — контртрендовая логика: три закрытые M1 подряд в одну сторону → сделка
                        <strong>в противоположную</strong> (ожидание отката/истощения импульса).
                    </p>
                    <p class="text-[11px] text-zinc-500">Цепочка: 3 бычьих M1 → PUT; 3 медвежьих → CALL. Вход на последней закрытой свече серии, экспирация — по полю ниже.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела в диапазоне (0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01"
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_body_to_range_ratio')])
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="trade_hour_utc_from" class="block text-xs font-medium text-zinc-400">Час UTC с (пусто = 24/7)</label>
                        <input type="number" name="trade_hour_utc_from" id="trade_hour_utc_from" min="0" max="23" step="1"
                            value="{{ old('trade_hour_utc_from', $cfg['trade_hour_utc_from'] ?? '') }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('trade_hour_utc_from')])
                        @error('trade_hour_utc_from')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="trade_hour_utc_to" class="block text-xs font-medium text-zinc-400">Час UTC по (включительно)</label>
                        <input type="number" name="trade_hour_utc_to" id="trade_hour_utc_to" min="0" max="23" step="1"
                            value="{{ old('trade_hour_utc_to', $cfg['trade_hour_utc_to'] ?? '') }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('trade_hour_utc_to')])
                        @error('trade_hour_utc_to')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="blocked_instruments_text" class="block text-xs font-medium text-zinc-400">Blacklist инструментов</label>
                        <textarea name="blocked_instruments_text" id="blocked_instruments_text" rows="3"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('blocked_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['blocked_instruments'] ?? null)) }}</textarea>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('blocked_instruments_text')])
                        @error('blocked_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

            </div>
        @elseif ($bot->type === 'rsi_contrtrend')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">RSI Contrtrend</strong> — стратегия:
                        покупка перепроданности RSI <strong>только в аптренде</strong> (цена выше SMA),
                        продажа перекупленности <strong>только в даунтренде</strong> (цена ниже SMA).
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        CALL: Close &gt; SMA и RSI &lt; Down. PUT: Close &lt; SMA и RSI &gt; Up.
                        ТФ сигнала M1, экспирация по умолчанию 180 с (3 мин). Выход — фиксированная экспирация (expiry).
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="60" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        {{-- M1 OTC: короткая SMA — иначе при RSI-экстремуме цена уже по другую сторону SMA50. --}}
                        <label for="sma_length" class="block text-xs font-medium text-zinc-400">SMA length</label>
                        <input type="number" name="sma_length" id="sma_length" min="2" max="500" required
                            value="{{ old('sma_length', $cfg['sma_length'] ?? 10) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('sma_length')])
                        @error('sma_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_length" class="block text-xs font-medium text-zinc-400">RSI length</label>
                        <input type="number" name="rsi_length" id="rsi_length" min="2" max="500" required
                            value="{{ old('rsi_length', $cfg['rsi_length'] ?? 14) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_length')])
                        @error('rsi_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_up" class="block text-xs font-medium text-zinc-400">RSI Up (перекупленность)</label>
                        <input type="number" name="rsi_up" id="rsi_up" min="50" max="95" step="0.1" required
                            value="{{ old('rsi_up', $cfg['rsi_up'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_up')])
                        @error('rsi_up')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_down" class="block text-xs font-medium text-zinc-400">RSI Down (перепроданность)</label>
                        <input type="number" name="rsi_down" id="rsi_down" min="5" max="50" step="0.1" required
                            value="{{ old('rsi_down', $cfg['rsi_down'] ?? 40) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_down')])
                        @error('rsi_down')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                @include('bots._rsi-contrtrend-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'bollinger_contrtrend')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Bollinger Contrtrend</strong> — стратегия mean-reversion по полосам Боллинджера:
                        покупка при закрытии <strong>ниже нижней полосы</strong>, продажа при закрытии
                        <strong>выше верхней</strong>.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        CALL: Close &lt; BB Down. PUT: Close &gt; BB Up.
                        ТФ M1, экспирация по умолчанию 180 с. Выход — фиксированная экспирация (expiry).
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="bb_length" class="block text-xs font-medium text-zinc-400">BB length</label>
                        <input type="number" name="bb_length" id="bb_length" min="2" max="500" required
                            value="{{ old('bb_length', $cfg['bb_length'] ?? 12) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('bb_length')])
                        @error('bb_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="bb_deviation" class="block text-xs font-medium text-zinc-400">BB deviation (σ)</label>
                        <input type="number" name="bb_deviation" id="bb_deviation" min="0.1" max="10" step="0.1" required
                            value="{{ old('bb_deviation', $cfg['bb_deviation'] ?? 2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('bb_deviation')])
                        @error('bb_deviation')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                @include('bots._bollinger-contrtrend-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'pinbar_trade')
            {{-- PinBar: тело в верхней/нижней трети HL + SMA по тренду. --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">PinBar Trade</strong> — стратегия пинбара:
                        бычий пин (O/C в верхней 1/3 HL, close &gt; SMA) → CALL;
                        медвежий (O/C в нижней 1/3, close &lt; SMA) → PUT.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="sma_length" class="block text-xs font-medium text-zinc-400">SMA length</label>
                        <input type="number" name="sma_length" id="sma_length" min="2" max="500" required
                            value="{{ old('sma_length', $cfg['sma_length'] ?? 50) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('sma_length')])
                        @error('sma_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'williams_range_trade')
            {{-- Williams %R: oversold/overbought. --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Williams Range Trade</strong> — стратегия Williams %R:
                        вход в зону (кросс) WR &lt; wr_down → CALL, WR &gt; wr_up → PUT. Без кросса — шум на удержании экстремума.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="wr_length" class="block text-xs font-medium text-zinc-400">WR length</label>
                        <input type="number" name="wr_length" id="wr_length" min="2" max="500" required
                            value="{{ old('wr_length', $cfg['wr_length'] ?? 21) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('wr_length')])
                        @error('wr_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="wr_up" class="block text-xs font-medium text-zinc-400">WR up (перекупленность)</label>
                        <input type="number" name="wr_up" id="wr_up" min="-100" max="0" step="0.1" required
                            value="{{ old('wr_up', $cfg['wr_up'] ?? -10) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('wr_up')])
                        @error('wr_up')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="wr_down" class="block text-xs font-medium text-zinc-400">WR down (перепроданность)</label>
                        <input type="number" name="wr_down" id="wr_down" min="-100" max="0" step="0.1" required
                            value="{{ old('wr_down', $cfg['wr_down'] ?? -90) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('wr_down')])
                        @error('wr_down')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 flex items-end">
                        <label class="flex items-start gap-2 pb-2">
                            <input type="checkbox" name="require_cross" id="require_cross_wr" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('require_cross', $cfg['require_cross'] ?? true))>
                            <span class="text-sm text-zinc-300">Только кросс в зону <span class="text-zinc-500">(анти-шум)</span></span>
                        </label>
                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('require_cross')])
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'three_soldier')
            {{-- Three Soldiers / Three Crows. --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Three Soldier</strong> — стратегия:
                        три бычьих свечи → CALL, три медвежьих → PUT (фильтр по высоте паттерна %).
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="height_soldiers" class="block text-xs font-medium text-zinc-400">Высота паттерна (%)</label>
                        <input type="number" name="height_soldiers" id="height_soldiers" min="0" max="10" step="0.01" required
                            value="{{ old('height_soldiers', $cfg['height_soldiers'] ?? 0.08) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('height_soldiers')])
                        @error('height_soldiers')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_height_one_soldier" class="block text-xs font-medium text-zinc-400">Мин. высота одной свечи (%)</label>
                        <input type="number" name="min_height_one_soldier" id="min_height_one_soldier" min="0" max="10" step="0.01" required
                            value="{{ old('min_height_one_soldier', $cfg['min_height_one_soldier'] ?? 0.02) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_height_one_soldier')])
                        @error('min_height_one_soldier')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'cci_trade')
            {{-- CCI mean-reversion. --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">CCI Trade</strong> — стратегия CCI:
                        CCI ≤ cci_down → CALL, CCI ≥ cci_up → PUT.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="cci_length" class="block text-xs font-medium text-zinc-400">CCI length</label>
                        <input type="number" name="cci_length" id="cci_length" min="2" max="500" required
                            value="{{ old('cci_length', $cfg['cci_length'] ?? 20) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('cci_length')])
                        @error('cci_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="cci_up" class="block text-xs font-medium text-zinc-400">CCI up</label>
                        <input type="number" name="cci_up" id="cci_up" step="1" required
                            value="{{ old('cci_up', $cfg['cci_up'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('cci_up')])
                        @error('cci_up')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="cci_down" class="block text-xs font-medium text-zinc-400">CCI down</label>
                        <input type="number" name="cci_down" id="cci_down" step="1" required
                            value="{{ old('cci_down', $cfg['cci_down'] ?? -150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('cci_down')])
                        @error('cci_down')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'rsi_trade')
            {{-- RSI Trade: кросс из зоны (без SMA Contrtrend). --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">RSI Trade</strong> — стратегия RSI:
                        кросс вверх из oversold → CALL, кросс вниз из overbought → PUT.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_length" class="block text-xs font-medium text-zinc-400">RSI length</label>
                        <input type="number" name="rsi_length" id="rsi_length" min="2" max="500" required
                            value="{{ old('rsi_length', $cfg['rsi_length'] ?? 14) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_length')])
                        @error('rsi_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_up" class="block text-xs font-medium text-zinc-400">RSI up</label>
                        <input type="number" name="rsi_up" id="rsi_up" min="0" max="100" step="0.1" required
                            value="{{ old('rsi_up', $cfg['rsi_up'] ?? 65) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_up')])
                        @error('rsi_up')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rsi_down" class="block text-xs font-medium text-zinc-400">RSI down</label>
                        <input type="number" name="rsi_down" id="rsi_down" min="0" max="100" step="0.1" required
                            value="{{ old('rsi_down', $cfg['rsi_down'] ?? 35) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rsi_down')])
                        @error('rsi_down')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'rvi_trade')
            {{-- RVI: кросс линии / signal. --}}
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">RVI Trade</strong> — стратегия RVI:
                        только бар кросса RVI через signal (signal достаточно далеко от 0). Без кросса — тысячи сделок на удержании состояния.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="40" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 150) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="rvi_length" class="block text-xs font-medium text-zinc-400">RVI length</label>
                        <input type="number" name="rvi_length" id="rvi_length" min="2" max="500" required
                            value="{{ old('rvi_length', $cfg['rvi_length'] ?? 14) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('rvi_length')])
                        @error('rvi_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_signal_abs" class="block text-xs font-medium text-zinc-400">Мин. |signal|</label>
                        <input type="number" name="min_signal_abs" id="min_signal_abs" min="0" max="5" step="0.01" required
                            value="{{ old('min_signal_abs', $cfg['min_signal_abs'] ?? 0.20) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_signal_abs')])
                        @error('min_signal_abs')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 flex items-end">
                        <label class="flex items-start gap-2 pb-2">
                            <input type="checkbox" name="require_cross" id="require_cross_rvi" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('require_cross', $cfg['require_cross'] ?? true))>
                            <span class="text-sm text-zinc-300">Только кросс RVI/signal <span class="text-zinc-500">(анти-шум)</span></span>
                        </label>
                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('require_cross')])
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 75) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._os-engine-binary-telegram-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
            </div>
        @elseif ($bot->type === 'candle_reversal_confirm')
            @include('bots._candle-reversal-level-settings')
        @elseif ($bot->type === 'sweep_raid_liquidity')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Свип / рейд ликвидности</strong> — бот ищет подтверждённый фрактальный
                        максимум или минимум, снятие ликвидности и вход на закрытой свече выбранного ТФ.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Для одинаковой визуализации и исполнения используйте слой
                        <a href="{{ route('bots.indicators.sweep-raid-liquidity.edit', $bot) }}" class="text-blue-400 hover:underline">Sweep / Raid Liquidity</a>.
                        Автор на OTC: <strong class="text-zinc-400">S15 + expiry 15с</strong>; legacy — M1 + 60с.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        {{-- ТФ серии для стратегии: S15 совпадает со стримами DarkSamurai на OTC. --}}
                        <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
                        @php $srlTf = (int) old('timeframe', $cfg['timeframe'] ?? 1); @endphp
                        <select name="timeframe" id="timeframe" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="{{ \App\Services\CandleService::TF_S15 }}" @selected($srlTf === \App\Services\CandleService::TF_S15)>S15 — как у автора (OTC)</option>
                            <option value="1" @selected($srlTf === 1)>M1 — классика</option>
                            <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($srlTf === \App\Services\CandleService::TF_S30)>S30</option>
                            <option value="{{ \App\Services\CandleService::TF_S5 }}" @selected($srlTf === \App\Services\CandleService::TF_S5)>S5</option>
                        </select>
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('timeframe', $bot->type)])
                        @error('timeframe')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="30" max="5000" required value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        {{-- min=15: BinaryExpiry / OTC S15; старый floor 60 ломал WR на стримах. --}}
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="15" max="900" required value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec', $bot->type)])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="srl_pivot_length" class="block text-xs font-medium text-zinc-400">Фрактал: баров слева/справа</label>
                        <input type="number" name="srl_pivot_length" id="srl_pivot_length" min="1" max="100" required value="{{ old('srl_pivot_length', $cfg['srl_pivot_length'] ?? 2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Меньше значение — больше уровней и сигналов; 2 — стандартный фрактал.</p>
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                </div>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Пути входа</p>
                    <input type="hidden" name="srl_enter_sweep" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_enter_sweep" value="1" @checked(old('srl_enter_sweep', $cfg['srl_enter_sweep'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Вход на свипе</span><span class="mt-0.5 block text-[11px] text-zinc-500">Зелёный свип хая → PUT; красный свип лоу → CALL (fade).</span></span>
                    </label>
                    <input type="hidden" name="srl_enter_pullback" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_enter_pullback" value="1" @checked(old('srl_enter_pullback', $cfg['srl_enter_pullback'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Вход на первой откатной</span><span class="mt-0.5 block text-[11px] text-zinc-500">После свипа или рейда: сверху первая зелёная → PUT; снизу первая красная → CALL.</span></span>
                    </label>
                    <input type="hidden" name="srl_pullback_sweeps_only" value="0">
                    <label class="flex items-start gap-2 pl-6">
                        <input type="checkbox" name="srl_pullback_sweeps_only" value="1" @checked(old('srl_pullback_sweeps_only', $cfg['srl_pullback_sweeps_only'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Откатная только после свипа</span><span class="mt-0.5 block text-[11px] text-zinc-500">Не входить по PB после рейда — меньше шума (author-like / replay K83).</span></span>
                    </label>
                    <input type="hidden" name="srl_enter_breakout" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_enter_breakout" value="1" @checked(old('srl_enter_breakout', $cfg['srl_enter_breakout'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Пробой + закрепление (сценарий E)</span><span class="mt-0.5 block text-[11px] text-zinc-500">Close(s) за FH/FL без возврата → продолжение: выше FH → CALL, ниже FL → PUT. Не путать с рейдом (рейд = вернулись).</span></span>
                    </label>
                    <input type="hidden" name="srl_exclusive_fade_breakout" value="0">
                    <label class="flex items-start gap-2 pl-6">
                        <input type="checkbox" name="srl_exclusive_fade_breakout" value="1" @checked(old('srl_exclusive_fade_breakout', $cfg['srl_exclusive_fade_breakout'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Exclusive fade ↔ breakout</span><span class="mt-0.5 block text-[11px] text-zinc-500">На одном баре при конфликте берём пробой, не fade/PB (как dual-mode автора).</span></span>
                    </label>
                    <div class="space-y-1 pl-6">
                        <label for="srl_breakout_closes" class="block text-xs font-medium text-zinc-400">Закрытий за уровнем для пробоя</label>
                        <input type="number" name="srl_breakout_closes" id="srl_breakout_closes" min="1" max="5" required
                            value="{{ old('srl_breakout_closes', $cfg['srl_breakout_closes'] ?? 2) }}"
                            class="w-full max-w-[8rem] rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">1 — агрессивно; 2 — как «закрепились» у автора на стриме.</p>
                        @error('srl_breakout_closes')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    @error('srl_enter_sweep')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">OTC-гэпы и сессия</p>
                    <input type="hidden" name="srl_gap_filter_enabled" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_gap_filter_enabled" value="1" @checked(old('srl_gap_filter_enabled', $cfg['srl_gap_filter_enabled'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Пропускать «грязную» пару с частыми гэпами</span><span class="mt-0.5 block text-[11px] text-zinc-500">Если доля баров с большим close→open разрывом выше порога — сигналов нет (стрим kAyG3wJDPBU).</span></span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <label for="srl_gap_lookback" class="block text-xs font-medium text-zinc-400">Окно баров</label>
                            <input type="number" name="srl_gap_lookback" id="srl_gap_lookback" min="5" max="500" required
                                value="{{ old('srl_gap_lookback', $cfg['srl_gap_lookback'] ?? 40) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        </div>
                        <div class="space-y-1">
                            <label for="srl_gap_max_ratio" class="block text-xs font-medium text-zinc-400">Макс. доля гэпов</label>
                            <input type="number" name="srl_gap_max_ratio" id="srl_gap_max_ratio" min="0.05" max="1" step="0.01" required
                                value="{{ old('srl_gap_max_ratio', $cfg['srl_gap_max_ratio'] ?? 0.35) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        </div>
                        <div class="space-y-1">
                            <label for="srl_gap_size_mult" class="block text-xs font-medium text-zinc-400">Порог гэпа × range</label>
                            <input type="number" name="srl_gap_size_mult" id="srl_gap_size_mult" min="0.1" max="10" step="0.1" required
                                value="{{ old('srl_gap_size_mult', $cfg['srl_gap_size_mult'] ?? 0.5) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        </div>
                    </div>
                    <input type="hidden" name="srl_skip_martingale_if_session_green" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_skip_martingale_if_session_green" value="1" @checked(old('srl_skip_martingale_if_session_green', $cfg['srl_skip_martingale_if_session_green'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Не поднимать мартингейл, если день уже в плюсе</span><span class="mt-0.5 block text-[11px] text-zinc-500">После LOSS ступень не растёт, пока paper-PnL бота за сегодня &gt; 0 (до этой сделки).</span></span>
                    </label>
                </div>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">DeMarker (фильтр)</p>
                    <input type="hidden" name="srl_demarker_enabled" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="srl_demarker_enabled" value="1" @checked(old('srl_demarker_enabled', $cfg['srl_demarker_enabled'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Фильтровать по DeMarker</span>
                            <span class="mt-0.5 block text-[11px] text-zinc-500">Сделка только если на баре входа осциллятор пересёк зону: CALL — выход вверх из нижней; PUT — выход вниз из верхней.</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <label for="srl_demarker_period" class="block text-xs font-medium text-zinc-400">Период</label>
                            <input type="number" name="srl_demarker_period" id="srl_demarker_period" min="1" max="500" required
                                value="{{ old('srl_demarker_period', $cfg['srl_demarker_period'] ?? 6) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('srl_demarker_period')])
                            @error('srl_demarker_period')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="srl_demarker_oversold" class="block text-xs font-medium text-zinc-400">Нижняя зона (%)</label>
                            <input type="number" name="srl_demarker_oversold" id="srl_demarker_oversold" min="0" max="49.99" step="0.01" required
                                value="{{ old('srl_demarker_oversold', $cfg['srl_demarker_oversold'] ?? 10) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('srl_demarker_oversold')])
                            @error('srl_demarker_oversold')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="srl_demarker_overbought" class="block text-xs font-medium text-zinc-400">Верхняя зона (%)</label>
                            <input type="number" name="srl_demarker_overbought" id="srl_demarker_overbought" min="50.01" max="100" step="0.01" required
                                value="{{ old('srl_demarker_overbought', $cfg['srl_demarker_overbought'] ?? 90) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('srl_demarker_overbought')])
                            @error('srl_demarker_overbought')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <p class="text-[11px] text-zinc-500">При включении DeMarker отображается на веб-графике и в PNG Telegram-алерта.</p>
                </div>
                @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
            </div>
        @elseif ($bot->type === 'engulfing_liquidity')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Поглощение (3 шага)</strong> — rule-based бот по стратегии автора:
                        engulf → реакция → контр-pogloщение. Вход на close 3-й свечи, не на первом engulf.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Оверлей ENGULF 1/2/3 —
                        <a href="{{ route('bots.indicators.engulfing-liquidity.edit', $bot) }}" class="text-blue-400 hover:underline">Engulfing Liquidity</a>.
                        Экспирация по умолчанию — 60 секунд; для OTC S15 допустимо 15с.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
                        @php $engTf = (int) old('timeframe', $cfg['timeframe'] ?? 1); @endphp
                        <select name="timeframe" id="timeframe" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="{{ \App\Services\CandleService::TF_S15 }}" @selected($engTf === \App\Services\CandleService::TF_S15)>S15 — OTC</option>
                            <option value="1" @selected($engTf === 1)>M1</option>
                            <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($engTf === \App\Services\CandleService::TF_S30)>S30</option>
                            <option value="{{ \App\Services\CandleService::TF_S5 }}" @selected($engTf === \App\Services\CandleService::TF_S5)>S5</option>
                        </select>
                        @error('timeframe')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей (count)</label>
                        <input type="number" name="count" id="count" min="30" max="5000" required value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="15" max="900" required value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                    </div>
                </div>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Типы поглощения (шаг 1)</p>
                    <input type="hidden" name="eng_allow_classic" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_allow_classic" value="1" @checked(old('eng_allow_classic', $cfg['eng_allow_classic'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Классическое engulf</span><span class="mt-0.5 block text-[11px] text-zinc-500">Противоположный цвет: зелёная съела красную (CALL) или красная зелёную (PUT).</span></span>
                    </label>
                    <input type="hidden" name="eng_allow_same_color" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_allow_same_color" value="1" @checked(old('eng_allow_same_color', $cfg['eng_allow_same_color'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Same-color engulf</span><span class="mt-0.5 block text-[11px] text-zinc-500">Тот же цвет + снятие high/low соседа (как на стриме AED/CNY).</span></span>
                    </label>
                </div>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">OTC-гэпы и сессия</p>
                    <input type="hidden" name="eng_gap_filter_enabled" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_gap_filter_enabled" value="1" @checked(old('eng_gap_filter_enabled', $cfg['eng_gap_filter_enabled'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Пропускать пару с частыми гэпами</span><span class="mt-0.5 block text-[11px] text-zinc-500">Тот же фильтр, что у Sweep/Raid: dirty OTC → нет сигналов.</span></span>
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
                        <input type="checkbox" name="eng_skip_martingale_if_session_green" value="1" @checked(old('eng_skip_martingale_if_session_green', $cfg['eng_skip_martingale_if_session_green'] ?? false))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span><span class="text-sm font-medium text-zinc-200">Не поднимать мартингейл, если день уже в плюсе</span><span class="mt-0.5 block text-[11px] text-zinc-500">После LOSS ступень не растёт при зелёном paper-дне бота.</span></span>
                    </label>
                </div>
                @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
            </div>
        @elseif ($bot->type === 'smc_flow')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">SMC Flow</strong> — вход при совпадении трёх фильтров на последней закрытой M1:
                        <strong>UT Bot</strong> (свежий Buy/Sell) + <strong>3BR confirm</strong> в окне баров + <strong>LuxAlgo OB</strong> (цена в зоне).
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Параметры индикатора (UT, 3BR, OB, FVG…) —
                        <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-blue-400 hover:underline">настройки SMC Flow</a>.
                        Экспирация по умолчанию 3 мин.
                    </p>
                </div>

                @if (! empty($smcFlowWorking))
                    @include('bots._smc-flow-working-params', ['bot' => $bot, 'smcFlowWorking' => $smcFlowWorking])
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 (count)</label>
                        <input type="number" name="count" id="count" min="80" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="120" max="600" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="smc_three_br_confirm_max_bars" class="block text-xs font-medium text-zinc-400">3BR: окно подтверждения (баров)</label>
                        <input type="number" name="smc_three_br_confirm_max_bars" id="smc_three_br_confirm_max_bars" min="1" max="120" required
                            value="{{ old('smc_three_br_confirm_max_bars', $cfg['smc_three_br_confirm_max_bars'] ?? 30) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('smc_three_br_confirm_max_bars')])
                        @error('smc_three_br_confirm_max_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="smc_ob_touch_max_bars" class="block text-xs font-medium text-zinc-400">OB: касание зоны (баров назад)</label>
                        <input type="number" name="smc_ob_touch_max_bars" id="smc_ob_touch_max_bars" min="0" max="12"
                            value="{{ old('smc_ob_touch_max_bars', $cfg['smc_ob_touch_max_bars'] ?? 3) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('smc_ob_touch_max_bars')])
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Фильтры SMC Flow</p>
                        <input type="hidden" name="smc_three_br_allow_pattern" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="smc_three_br_allow_pattern" id="smc_three_br_allow_pattern" value="1"
                            @checked(old('smc_three_br_allow_pattern', $cfg['smc_three_br_allow_pattern'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span>
                            <span class="text-sm font-medium text-zinc-200">3BR: учитывать pattern (^/¡)</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Если выкл. — только confirm (?). Pattern даёт больше сигналов, но слабее подтверждение.</span>
                        </span>
                        </label>
                        <input type="hidden" name="smc_require_ob" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="smc_require_ob" id="smc_require_ob" value="1"
                            @checked(old('smc_require_ob', $cfg['smc_require_ob'] ?? true))
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-900 text-blue-500">
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Требовать LuxAlgo Order Block</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Цена в активной OB-зоне нужной стороны. Выкл. — вход по UT + 3BR без зоны накопления.</span>
                        </span>
                        </label>
                </div>

                {{-- Telegram: алерты paper-сделки, PNG с UT/3BR/OB, poll /start и LLM-кнопка Chart Analyst. --}}
                @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
            </div>
        @elseif (\App\Support\OsEngineWaveABotConfig::isWaveA($bot->type))
            @include('bots._wave-a-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
        @elseif (\App\Support\MaxBotConfig::is($bot->type))
            @include('bots._max-bot-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
        @elseif (\App\Support\ImpulseLvl1minConfig::is($bot->type))
            @include('bots._impulse-lvl-1min-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
        @elseif (\App\Support\OsEngineWaveBBotConfig::isWaveB($bot->type))
            @include('bots._wave-b-settings', ['bot' => $bot, 'cfg' => $cfg, 'chartAnalystBots' => $chartAnalystBots ?? []])
        @elseif ($bot->type === 'openrouter_llm')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">OpenRouter LLM</strong> — торговые решения принимает нейросеть по JSON-контракту.
                        В запрос уходят свечи M1 + опционально M5/M15/H1; алгоритмы старых ботов не используются.
                    </p>
                    <p class="text-[11px] text-zinc-500">Нужен <code class="text-zinc-400">OPENROUTER_API_KEY</code> в .env. Логи запросов — «Логи и метрики» → bot_llm_logs.</p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные для модели</p>
                <p class="text-[11px] text-zinc-500 -mt-2">По каждому ТФ одно число = столько последних баров загружается из БД и передаётся в LLM.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1</label>
                        <input type="number" name="count" id="count" min="20" max="500" required
                            value="{{ old('count', $cfg['count'] ?? ($cfg['candles_take'] ?? 120)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="mtf_enabled" id="mtf_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                   @checked(old('mtf_enabled', $cfg['mtf_enabled'] ?? true))>
                            <span>
                                <span class="text-xs font-medium text-zinc-300">MTF-контекст (M5/M15/H1)</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Модель получает старшие ТФ для bias и структуры. Выкл. — только M1 (быстрее, но больше шума).</span>
                            </span>
                            </label>
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('mtf_enabled')])
                        @error('mtf_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 96) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m5_take')])
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 64) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m15_take')])
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 24) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('h1_take')])
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Scan и сделки</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="60" max="900" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
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
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 70) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Параметры OpenRouter</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1 sm:col-span-2">
                        <label for="model" class="block text-xs font-medium text-zinc-400">Модель (provider/model)</label>
                        @include('bots._openrouter-model-select', [
                            'openrouterModels' => $openrouterModels,
                            'selectedOpenRouterModel' => $selectedOpenRouterModel,
                        ])
                        <p class="text-[11px] text-zinc-500">Формат OpenRouter, напр. <code class="text-zinc-400">openai/gpt-5.2</code>. Vision не требуется — только JSON по свечам.</p>
                        @error('model')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="temperature" class="block text-xs font-medium text-zinc-400">Temperature (0–2)</label>
                        <input type="number" name="temperature" id="temperature" min="0" max="2" step="0.01" required
                            value="{{ old('temperature', $cfg['temperature'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('temperature')])
                        @error('temperature')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens" class="block text-xs font-medium text-zinc-400">Max tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" min="32" max="4096" required
                            value="{{ old('max_tokens', $cfg['max_tokens'] ?? 350) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('max_tokens')])
                        @error('max_tokens')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    @include('bots._openrouter-timeout-field', ['cfg' => $cfg, 'defaultTimeout' => 120])
                </div>

                <div class="space-y-1">
                    <label for="system_prompt" class="block text-xs font-medium text-zinc-400">System prompt</label>
                    <textarea name="system_prompt" id="system_prompt" rows="10" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt', $cfg['system_prompt'] ?? $openrouterDefaultPrompt) }}</textarea>

                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('system_prompt')])
                        @error('system_prompt')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
            </div>
        @elseif ($isChartAnalystBot)
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">{{ $isImpulseAnalystBot ? 'Chart Analyst Impulse LVL' : ($isEngulfAnalystBot ? 'Chart Analyst Engulfing' : ($isSrlAnalystBot ? 'Chart Analyst Sweep/Raid' : 'Chart Analyst')) }}</strong> —
                    @if($isImpulseAnalystBot)
                            ручной и Telegram-анализ: уровни Impulse LVL (бот 47) на графике и в промпте, LLM сам даёт CALL/PUT/NONE. Экспирация ориентир 60 с.
                    @elseif($isEngulfAnalystBot)
                            ручной и Telegram-анализ: детектор находит ENGULF 1/2/3, LLM только подтверждает или отклоняет. Экспирация ориентир 60 с.
                    @elseif($isSrlAnalystBot)
                            ручной и Telegram-анализ: детектор находит SWEEP/RAID/PB, LLM только подтверждает или отклоняет. Экспирация ориентир 60 с.
                    @elseif($analystSmcFlowEnabled)
                            ручной и Telegram-анализ с снимком SMC Flow в LLM + блок <code class="text-zinc-400">chart_levels</code> для графика.
                    @else
                            ручной анализ по кнопке «Сделать анализ» на графике (классический MTF без SMC в промпте).
                    @endif
                        Paper-сделки и плановый scan <strong>отключены</strong>.
                </p>
                    <p class="text-[11px] text-zinc-500">Ответ — текст на русском + уровни для оверлея. История в <code class="text-zinc-400">bot_llm_logs</code>.</p>
                </div>

                @if ($isSrlAnalystBot)
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Sweep / Raid — кандидаты для LLM</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="srl_pivot_length" class="block text-xs font-medium text-zinc-400">Pivot length (фрактал)</label>
                        <input type="number" name="srl_pivot_length" id="srl_pivot_length" min="1" max="100" required
                            value="{{ old('srl_pivot_length', $cfg['srl_pivot_length'] ?? 2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Тот же параметр, что у rule-based бота и индикатора SRL.</p>
                        @error('srl_pivot_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="srl_enter_sweep" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('srl_enter_sweep', ($cfg['srl_enter_sweep'] ?? true) ? '1' : '') === '1' || (old('srl_enter_sweep') === null && ($cfg['srl_enter_sweep'] ?? true)))>
                            <span class="text-xs text-zinc-300">Кандидаты на свипе (вход сразу после валидного SWEEP)</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="srl_enter_pullback" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('srl_enter_pullback', ($cfg['srl_enter_pullback'] ?? true) ? '1' : '') === '1' || (old('srl_enter_pullback') === null && ($cfg['srl_enter_pullback'] ?? true)))>
                            <span class="text-xs text-zinc-300">Кандидаты на первой откатной после SWEEP/RAID</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="srl_enter_breakout" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('srl_enter_breakout', ($cfg['srl_enter_breakout'] ?? false) ? '1' : '') === '1' || (old('srl_enter_breakout') === null && ($cfg['srl_enter_breakout'] ?? false)))>
                            <span class="text-xs text-zinc-300">Кандидаты на пробое + закреплении (сценарий E, продолжение)</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2 pt-1">
                            <div class="space-y-1">
                                <label for="srl_breakout_closes" class="block text-[11px] font-medium text-zinc-400">Закрытий за уровнем</label>
                                <input type="number" name="srl_breakout_closes" id="srl_breakout_closes" min="1" max="5"
                                    value="{{ old('srl_breakout_closes', $cfg['srl_breakout_closes'] ?? 2) }}"
                                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            </div>
                        </div>
                        <label class="flex items-start gap-2 pt-1">
                            <input type="checkbox" name="srl_gap_filter_enabled" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('srl_gap_filter_enabled', ($cfg['srl_gap_filter_enabled'] ?? false) ? '1' : '') === '1' || (old('srl_gap_filter_enabled') === null && ($cfg['srl_gap_filter_enabled'] ?? false)))>
                            <span class="text-xs text-zinc-300">Фильтр OTC-гэпов (не слать кандидатов на «грязной» паре)</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="space-y-1">
                                <label class="block text-[11px] text-zinc-400">Окно</label>
                                <input type="number" name="srl_gap_lookback" min="5" max="500" value="{{ old('srl_gap_lookback', $cfg['srl_gap_lookback'] ?? 40) }}"
                                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            </div>
                            <div class="space-y-1">
                                <label class="block text-[11px] text-zinc-400">Макс. доля</label>
                                <input type="number" name="srl_gap_max_ratio" min="0.05" max="1" step="0.01" value="{{ old('srl_gap_max_ratio', $cfg['srl_gap_max_ratio'] ?? 0.35) }}"
                                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            </div>
                            <div class="space-y-1">
                                <label class="block text-[11px] text-zinc-400">× range</label>
                                <input type="number" name="srl_gap_size_mult" min="0.1" max="10" step="0.1" value="{{ old('srl_gap_size_mult', $cfg['srl_gap_size_mult'] ?? 0.5) }}"
                                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            </div>
                        </div>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="srl_skip_martingale_if_session_green" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('srl_skip_martingale_if_session_green', ($cfg['srl_skip_martingale_if_session_green'] ?? false) ? '1' : '') === '1' || (old('srl_skip_martingale_if_session_green') === null && ($cfg['srl_skip_martingale_if_session_green'] ?? false)))>
                            <span class="text-xs text-zinc-300">Не поднимать мартингейл при зелёном дне бота</span>
                        </label>
                        @error('srl_enter_sweep')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @endif

                @if ($isEngulfAnalystBot)
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Engulfing — кандидаты для LLM</p>
                <div class="space-y-2 sm:col-span-2">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_allow_classic" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('eng_allow_classic', ($cfg['eng_allow_classic'] ?? true) ? '1' : '') === '1' || (old('eng_allow_classic') === null && ($cfg['eng_allow_classic'] ?? true)))>
                        <span class="text-xs text-zinc-300">Classic engulf — противоположный цвет (зелёная съела красную / наоборот)</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_allow_same_color" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('eng_allow_same_color', ($cfg['eng_allow_same_color'] ?? true) ? '1' : '') === '1' || (old('eng_allow_same_color') === null && ($cfg['eng_allow_same_color'] ?? true)))>
                        <span class="text-xs text-zinc-300">Same-color engulf — снятие high/low соседа тем же цветом</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_gap_filter_enabled" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('eng_gap_filter_enabled', ($cfg['eng_gap_filter_enabled'] ?? false) ? '1' : '') === '1' || (old('eng_gap_filter_enabled') === null && ($cfg['eng_gap_filter_enabled'] ?? false)))>
                        <span class="text-xs text-zinc-300">Фильтр OTC-гэпов</span>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <input type="number" name="eng_gap_lookback" min="5" max="500" value="{{ old('eng_gap_lookback', $cfg['eng_gap_lookback'] ?? 40) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100" placeholder="окно">
                        <input type="number" name="eng_gap_max_ratio" min="0.05" max="1" step="0.01" value="{{ old('eng_gap_max_ratio', $cfg['eng_gap_max_ratio'] ?? 0.35) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100" placeholder="доля">
                        <input type="number" name="eng_gap_size_mult" min="0.1" max="10" step="0.1" value="{{ old('eng_gap_size_mult', $cfg['eng_gap_size_mult'] ?? 0.5) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100" placeholder="×range">
                    </div>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eng_skip_martingale_if_session_green" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('eng_skip_martingale_if_session_green', ($cfg['eng_skip_martingale_if_session_green'] ?? false) ? '1' : '') === '1' || (old('eng_skip_martingale_if_session_green') === null && ($cfg['eng_skip_martingale_if_session_green'] ?? false)))>
                        <span class="text-xs text-zinc-300">Не поднимать мартингейл при зелёном дне бота</span>
                    </label>
                    @error('eng_allow_classic')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                @endif

                @if ($isImpulseAnalystBot)
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Impulse LVL — как у бота 47</p>
                <div class="space-y-2">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="overlay_forming_htf" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('overlay_forming_htf', ($cfg['overlay_forming_htf'] ?? true) ? '1' : '') === '1' || (old('overlay_forming_htf') === null && ($cfg['overlay_forming_htf'] ?? true)))>
                        <span class="text-xs text-zinc-300">Формирующиеся M5/M15/M30 из хвоста M1 (как 1min LEVEL_UPDATE_HIGHER_TF)</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="eval_prev_candle" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('eval_prev_candle', ($cfg['eval_prev_candle'] ?? true) ? '1' : '') === '1' || (old('eval_prev_candle') === null && ($cfg['eval_prev_candle'] ?? true)))>
                        <span class="text-xs text-zinc-300">Учитывать закрытую предыдущую M1 (prev) в live_setups</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="live_candle_wick_signals" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('live_candle_wick_signals', ($cfg['live_candle_wick_signals'] ?? true) ? '1' : '') === '1' || (old('live_candle_wick_signals') === null && ($cfg['live_candle_wick_signals'] ?? true)))>
                        <span class="text-xs text-zinc-300">Live-фитиль как updateBotM1 (в snapshot; LLM всё равно решает сам)</span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="lvl_filter" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('lvl_filter', ($cfg['lvl_filter'] ?? true) ? '1' : '') === '1' || (old('lvl_filter') === null && ($cfg['lvl_filter'] ?? true)))>
                        <span class="text-xs text-zinc-300">LVL обязателен для live_setups детектора (зоны на график всё равно считаются)</span>
                    </label>
                </div>
                @endif

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные для модели</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1</label>
                        <input type="number" name="count" id="count" min="1" max="500" required
                            value="{{ old('count', $cfg['count'] ?? ($cfg['candles_take'] ?? 120)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="s5_take" class="block text-xs font-medium text-zinc-400">Свечей S5</label>
                        <input type="number" name="s5_take" id="s5_take" min="1" max="1500" required
                            value="{{ old('s5_take', $cfg['s5_take'] ?? 240) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('s5_take')])
                        @error('s5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="mtf_enabled" id="mtf_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('mtf_enabled', $cfg['mtf_enabled'] ?? true))>
                            <span>
                                <span class="text-xs font-medium text-zinc-300">MTF M1/M5/M15/H1</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Старшие ТФ в запросе аналитика. Выкл. — только M1+S5 (быстрее, меньше контекста).</span>
                            </span>
                        </label>
                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('mtf_enabled')])
                    </div>
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="1" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 96) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m5_take')])
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="1" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 64) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m15_take')])
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="1" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 24) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('h1_take')])
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="model" class="block text-xs font-medium text-zinc-400">Модель OpenRouter</label>
                        @include('bots._openrouter-model-select', [
                            'openrouterModels' => $openrouterModels,
                            'selectedOpenRouterModel' => $selectedOpenRouterModel,
                        ])
                        <p class="text-[11px] text-zinc-500">Для длинного текста + chart_levels лучше модели с большим контекстом. Ответ не обязан быть строгим JSON (в отличие от trading LLM).</p>
                    </div>
                    <div class="space-y-1">
                        <label for="temperature" class="block text-xs font-medium text-zinc-400">Temperature</label>
                        <input type="number" name="temperature" id="temperature" min="0" max="2" step="0.01" required
                            value="{{ old('temperature', $cfg['temperature'] ?? 0.2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('temperature')])
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens" class="block text-xs font-medium text-zinc-400">Max tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" min="64" max="8192" required
                            value="{{ old('max_tokens', $cfg['max_tokens'] ?? 900) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('max_tokens')])
                    </div>
                    <div class="space-y-1">
                        <label for="max_tokens_short" class="block text-xs font-medium text-zinc-400">Max tokens (краткий режим)</label>
                        <input type="number" name="max_tokens_short" id="max_tokens_short" required
                            value="{{ old('max_tokens_short', $cfg['max_tokens_short'] ?? \App\Support\OpenRouterLlmConfig::ANALYST_SHORT_MAX_TOKENS_DEFAULT) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                    
                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('max_tokens_short')])
                    </div>
                    @include('bots._openrouter-timeout-field', ['cfg' => $cfg, 'defaultTimeout' => 180])
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 border-t border-zinc-800 pt-4">System prompt</p>
                <div class="space-y-1">
                    <label for="analyst_prompt_mode" class="block text-xs font-medium text-zinc-400">Режим ответа</label>
                    <select name="analyst_prompt_mode" id="analyst_prompt_mode" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                        <option value="full" @selected($analystPromptMode === 'full')>Полный — MTF-разбор + chart_levels</option>
                        <option value="short" @selected($analystPromptMode === 'short')>Краткий — CALL/PUT/NONE + вероятность успеха</option>
                    </select>

                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('analyst_prompt_mode')])
                        @error('analyst_prompt_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="system_prompt" class="block text-xs font-medium text-zinc-400">System prompt (полный)</label>
                    <textarea name="system_prompt" id="system_prompt" rows="12" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt', $cfg['system_prompt'] ?? $openrouterAnalystDefaultPrompt) }}</textarea>

                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('system_prompt')])
                        @error('system_prompt')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1">
                    <label for="system_prompt_short" class="block text-xs font-medium text-zinc-400">System prompt (краткий)</label>
                    <textarea name="system_prompt_short" id="system_prompt_short" rows="10" required
                        class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('system_prompt_short', $cfg['system_prompt_short'] ?? $openrouterAnalystShortDefaultPrompt) }}</textarea>

                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('system_prompt_short')])
                        @error('system_prompt_short')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 border-t border-zinc-800 pt-4">Telegram-меню пар</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout для меню (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="allowed_instruments_text" class="block text-xs font-medium text-zinc-400">Whitelist инструментов</label>
                        <textarea name="allowed_instruments_text" id="allowed_instruments_text" rows="3"
                            placeholder="EURUSD_otc&#10;GBPUSD_otc"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('allowed_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['allowed_instruments'] ?? null)) }}</textarea>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('allowed_instruments_text')])
                        @error('allowed_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <label for="blocked_instruments_text" class="block text-xs font-medium text-zinc-400">Blacklist инструментов</label>
                        <textarea name="blocked_instruments_text" id="blocked_instruments_text" rows="2"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100">{{ old('blocked_instruments_text', \App\Services\Telegram\TelegramAnalystInstrumentService::formatInstrumentListForInput($cfg['blocked_instruments'] ?? null)) }}</textarea>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('blocked_instruments_text')])
                        @error('blocked_instruments_text')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Гибрид (сигнал другого бота → этот Chart Analyst)</p>
                    <p class="text-[11px] text-zinc-500">
                        Когда выбранный бот даёт CALL/PUT, этот аналитик получает те же данные, что и обычный Chart Analyst (MTF/S5/SMC),
                        плюс полный decision source, и пишет результат в логи/Telegram.
                        Опционально — своя paper-сделка (и PO) на этом Chart Analyst, поверх сделки source.
                        Кнопка «Доп. анализ нейросетью» у source-ботов работает параллельно.
                        Под гибрид-алертом можно включить такую же кнопку для независимого повторного анализа.
                    </p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="hybrid_enabled" id="hybrid_enabled" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('hybrid_enabled', $cfg['hybrid_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Включить гибридный режим</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Нужны queue worker <code class="text-zinc-400">telegram-analyst</code> (при сохранении перезапускается сам). Бот станет <strong class="text-zinc-300">active</strong> в списке. Для Telegram-алертов — token и chat_id ниже.</span>
                        </span>
                    </label>
                    @error('hybrid_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    @php
                        $hybridSourceMode = old('hybrid_source_mode', $cfg['hybrid_source_mode'] ?? \App\Support\HybridAnalystConfig::SOURCE_MODE_FIXED);
                        $hybridSourceId = (int) old('hybrid_source_bot_id', $cfg['hybrid_source_bot_id'] ?? 0);
                        $hybridLeaderWindow = (int) old('hybrid_leader_window_sec', $cfg['hybrid_leader_window_sec'] ?? \App\Support\HybridAnalystConfig::LEADER_WINDOW_1H);
                        $hybridLeaderMinTrades = (int) old('hybrid_leader_min_trades', $cfg['hybrid_leader_min_trades'] ?? \App\Support\HybridAnalystConfig::LEADER_MIN_TRADES_DEFAULT);
                        $hybridLeaderId = (int) ($cfg['hybrid_leader_bot_id'] ?? 0);
                        // Длинный список ботов: нативный popup <select> обрезается из-за overflow-hidden на body.
                        $hybridSourceBotOptionCount = count($hybridSourceBots ?? []) + 1;
                        $hybridSourceSelectNeedsScroll = $hybridSourceBotOptionCount > 8;
                    @endphp
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            <label for="hybrid_source_mode" class="block text-xs font-medium text-zinc-400">Источник сигнала</label>
                            <select name="hybrid_source_mode" id="hybrid_source_mode" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="fixed" @selected($hybridSourceMode === 'fixed')>Фиксированный бот</option>
                                <option value="best_winrate" @selected($hybridSourceMode === 'best_winrate')>Лучший по paper winrate за период</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_source_mode')])
                        @error('hybrid_source_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2" id="hybrid-source-bot-wrap">
                            <label for="hybrid_source_bot_id" class="block text-xs font-medium text-zinc-400">Бот первичного анализа</label>
                            <select name="hybrid_source_bot_id" id="hybrid_source_bot_id"
                                @if ($hybridSourceSelectNeedsScroll) data-scrollable-select="1" data-scrollable-select-max="32" data-scrollable-select-max-height="48rem" @endif
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 disabled:opacity-50 disabled:cursor-not-allowed">
                                <option value="">— не выбран —</option>
                                @foreach ($hybridSourceBots ?? [] as $srcBot)
                                    <option value="{{ $srcBot->id }}" @selected($hybridSourceId === (int) $srcBot->id)>
                                        #{{ $srcBot->id }} {{ $srcBot->name }} ({{ $srcBot->type }}){{ $srcBot->is_active ? '' : ' · выкл' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-[11px] text-zinc-500" id="hybrid-source-bot-hint">Один конкретный бот. Сигналы других ботов игнорируются.</p>
                            @error('hybrid_source_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 hybrid-leader-only">
                            <label for="hybrid_leader_window_sec" class="block text-xs font-medium text-zinc-400">Окно рейтинга</label>
                            <select name="hybrid_leader_window_sec" id="hybrid_leader_window_sec" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1800" @selected($hybridLeaderWindow === 1800)>30 минут</option>
                                <option value="3600" @selected($hybridLeaderWindow === 3600)>1 час</option>
                                <option value="7200" @selected($hybridLeaderWindow === 7200)>2 часа</option>
                                <option value="10800" @selected($hybridLeaderWindow === 10800)>3 часа</option>
                                <option value="21600" @selected($hybridLeaderWindow === 21600)>6 часов</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_leader_window_sec')])
                        @error('hybrid_leader_window_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 hybrid-leader-only">
                            <label for="hybrid_leader_min_trades" class="block text-xs font-medium text-zinc-400">Мин. сделок для рейтинга</label>
                            <input type="number" name="hybrid_leader_min_trades" id="hybrid_leader_min_trades" min="1" max="500" required
                                value="{{ $hybridLeaderMinTrades }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_leader_min_trades')])
                        @error('hybrid_leader_min_trades')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_trigger" class="block text-xs font-medium text-zinc-400">Когда запускать</label>
                            @php $hybridTrigger = old('hybrid_trigger', $cfg['hybrid_trigger'] ?? \App\Support\HybridAnalystConfig::TRIGGER_ON_SIGNAL); @endphp
                            <select name="hybrid_trigger" id="hybrid_trigger" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="on_signal" @selected($hybridTrigger === 'on_signal')>При каждом CALL/PUT (запись сигнала)</option>
                                <option value="on_po_mirror" @selected($hybridTrigger === 'on_po_mirror')>Когда paper-сделка уходит на PO (mirror)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_trigger')])
                        @error('hybrid_trigger')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_publish" class="block text-xs font-medium text-zinc-400">Публикация в Telegram</label>
                            @php $hybridPublish = old('hybrid_publish', $cfg['hybrid_publish'] ?? \App\Support\HybridAnalystConfig::PUBLISH_ALWAYS); @endphp
                            <select name="hybrid_publish" id="hybrid_publish" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="always" @selected($hybridPublish === 'always')>Всегда (если LLM ответил)</option>
                                <option value="agree_source" @selected($hybridPublish === 'agree_source')>Только если LLM согласен с side source</option>
                                <option value="call_put_only" @selected($hybridPublish === 'call_put_only')>Только если LLM дал CALL или PUT</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_publish')])
                        @error('hybrid_publish')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="hybrid_min_success_percent" class="block text-xs font-medium text-zinc-400">Мин. вероятность успеха (%)</label>
                            <input type="number" name="hybrid_min_success_percent" id="hybrid_min_success_percent" min="0" max="100" required
                                value="{{ old('hybrid_min_success_percent', $cfg['hybrid_min_success_percent'] ?? 0) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('hybrid_min_success_percent')])
                        @error('hybrid_min_success_percent')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="space-y-3 border-t border-zinc-800 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Торговля гибрида</p>
                        <p class="text-[11px] text-zinc-500">
                            Те же фильтры, что для Telegram. Side — только из LLM (CALL/PUT). Экспирация — из source.
                            Сумма на PO — из профиля «Торговля». Сделка пишется на этот Chart Analyst (поверх source).
                        </p>
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="hybrid_paper_trades_enabled" value="0">
                            <input type="checkbox" name="hybrid_paper_trades_enabled" id="hybrid_paper_trades_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hybrid_paper_trades_enabled', $cfg['hybrid_paper_trades_enabled'] ?? false))>
                            <span>
                                <span class="text-sm font-medium text-zinc-300">Paper-сделки</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Создавать paper signal/trade на этом боте после успешного гибрид-фильтра.</span>
                            </span>
                        </label>
                        @error('hybrid_paper_trades_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="hybrid_mirror_trades_enabled" value="0">
                            <input type="checkbox" name="hybrid_mirror_trades_enabled" id="hybrid_mirror_trades_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hybrid_mirror_trades_enabled', $cfg['hybrid_mirror_trades_enabled'] ?? false))>
                            <span>
                                <span class="text-sm font-medium text-zinc-300">Зеркалировать на Pocket Option</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Как у других ботов: нужен paper + зеркало в профиле «Торговля». Без paper чекбокс выше PO не отправится.</span>
                            </span>
                        </label>
                        @error('hybrid_mirror_trades_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-3 border-t border-zinc-800 pt-3">
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="hybrid_telegram_llm_button_enabled" value="0">
                            <input type="checkbox" name="hybrid_telegram_llm_button_enabled" id="hybrid_telegram_llm_button_enabled" value="1"
                                class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('hybrid_telegram_llm_button_enabled', $cfg['hybrid_telegram_llm_button_enabled'] ?? true))>
                            <span>
                                <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Под гибрид-алертом и обычным ответом Chart Analyst в Telegram — повторный LLM по той же паре (без hybrid_source_signal). Нужен polling (<code class="text-zinc-400">telegram_enabled</code>).</span>
                            </span>
                        </label>
                        @error('hybrid_telegram_llm_button_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        <div class="space-y-1">
                            <label for="telegram_analyst_llm_question_hybrid" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_hybrid" rows="2"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_question')])
                        @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    // Режим source: fixed → селект бота активен; best_winrate → селект disabled.
                    const modeEl = document.getElementById('hybrid_source_mode');
                    const sourceEl = document.getElementById('hybrid_source_bot_id');
                    const hintEl = document.getElementById('hybrid-source-bot-hint');
                    const leaderBlocks = document.querySelectorAll('.hybrid-leader-only');
                    if (!modeEl || !sourceEl) return;

                    // Длинный список: вместо нативного popup (который режется overflow-hidden)
                    // раскрываем listbox с max-height и overflow-y-auto прямо в разметке.
                    if (sourceEl.dataset.scrollableSelect === '1') {
                        const maxVisible = Math.max(4, parseInt(sourceEl.dataset.scrollableSelectMax || '32', 10) || 32);
                        const maxHeight = sourceEl.dataset.scrollableSelectMaxHeight || '48rem';
                        const optionCount = sourceEl.options.length;

                        function expandSourceSelect() {
                            // size + overflow в DOM: нативный popup режется body { overflow: hidden }.
                            sourceEl.size = Math.min(optionCount, maxVisible);
                            sourceEl.style.maxHeight = maxHeight;
                            sourceEl.style.overflowY = 'auto';
                        }

                        function collapseSourceSelect() {
                            sourceEl.removeAttribute('size');
                            sourceEl.style.maxHeight = '';
                            sourceEl.style.overflowY = '';
                        }

                        sourceEl.addEventListener('focus', expandSourceSelect);
                        sourceEl.addEventListener('mousedown', function () {
                            if (!sourceEl.hasAttribute('size')) {
                                expandSourceSelect();
                            }
                        });
                        sourceEl.addEventListener('blur', function () {
                            window.setTimeout(collapseSourceSelect, 120);
                        });
                        sourceEl.addEventListener('change', collapseSourceSelect);
                    }

                    function sync() {
                        const auto = modeEl.value === 'best_winrate';
                        sourceEl.disabled = auto;
                        if (hintEl) {
                            hintEl.textContent = auto
                                ? 'Селект неактивен: source выбирается автоматически по лучшему paper winrate.'
                                : 'Один конкретный бот. Сигналы других ботов игнорируются.';
                        }
                        leaderBlocks.forEach(function (el) {
                            el.classList.toggle('opacity-50', !auto);
                        });
                    }
                    modeEl.addEventListener('change', sync);
                    sync();
                    // disabled select не уходит в POST — перед submit включаем, чтобы сохранить выбранный fixed id.
                    const form = modeEl.closest('form');
                    if (form) {
                        form.addEventListener('submit', function () {
                            sourceEl.disabled = false;
                        });
                    }
                })();
                </script>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram (Chart Analyst bot)</p>
                    <p class="text-[11px] text-zinc-500">
                        Long polling и queue worker (<code class="text-zinc-400">queue:work --queue=telegram-analyst</code>) — перезапуск кнопкой ниже после сохранения token/chat_id.
                    </p>
                    <p id="telegram-process-status-analyst" class="text-xs text-zinc-500">Проверка процессов…</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_analyst" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Включить Telegram-бота</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Интерактивное меню пар + запрос анализа по кнопке. Без галочки polling не стартует даже при сохранённом token.</span>
                        </span>
                    </label>
                    @include('bots._telegram-notify-result-field', [
                        'cfg' => $cfg,
                        'telegramNotifyResultId' => 'telegram_notify_result_analyst',
                    ])
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_skip_user_question" id="telegram_skip_user_question_analyst" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_skip_user_question', $cfg['telegram_skip_user_question'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Сразу анализ без вопроса</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">После выбора инструмента не спрашивать дополнительный вопрос — сразу ставить анализ в очередь. Применяется сразу после сохранения (poll перечитывает config из БД). Перезапуск poll нужен только если опция не сработала.</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token_analyst',
                                'hint' => 'Токен от @BotFather. Пустое поле при сохранении — оставить текущий. После смены token нажмите «Перезапустить poll + queue».',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id_analyst" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id_analyst"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_chat_id')])
                        @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        @include('bots._telegram-auto-subscribe-field', [
                            'cfg' => $cfg,
                            'telegramAutoSubscribeId' => 'telegram_auto_subscribe_analyst',
                        ])
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn-analyst"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id
                            </button>
                            <button type="button" id="telegram-test-btn-analyst"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-analyst"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status-analyst" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Напишите боту
                        @if($isImpulseAnalystBot)
                        (токен этого Chart Analyst Impulse LVL)
                        @elseif($isEngulfAnalystBot)
                        (токен этого Chart Analyst Engulfing)
                        @elseif($isSrlAnalystBot)
                        (токен этого Chart Analyst Sweep/Raid)
                        @else
                        <a href="https://t.me/chart_analyct_smc_flow_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@chart_analyct_smc_flow_bot</a>
                        @endif
                        → /start, затем «Получить chat_id».
                    </p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn-analyst');
                const testBtn = document.getElementById('telegram-test-btn-analyst');
                const restartBtn = document.getElementById('telegram-restart-btn-analyst');
                const statusEl = document.getElementById('telegram-action-status-analyst');
                const processStatusEl = document.getElementById('telegram-process-status-analyst');
                const chatInput = document.getElementById('telegram_chat_id_analyst');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
                const testUrl = @json(route('bots.telegram.test', $bot));
                const analystStatusUrl = @json(route('bots.telegram.analyst-status', $bot));
                const analystRestartUrl = @json(route('bots.telegram.analyst-restart', $bot));

                function setStatus(text, tone) {
                    if (!statusEl) return;
                    statusEl.textContent = text || '';
                    const cls = tone === 'error' ? 'text-rose-400'
                        : tone === 'pending' ? 'text-amber-300'
                        : 'text-emerald-300';
                    statusEl.className = 'text-xs ' + cls;
                }

                function renderProcessStatus(data) {
                    if (!processStatusEl || !data) return;
                    const poll = data.poll_running ? 'poll ✓' : 'poll ✗';
                    const queue = data.queue_running ? 'queue ✓' : 'queue ✗';
                    const pending = data.restart_pending ? ' · ожидание cron' : '';
                    const pids = [];
                    if (Array.isArray(data.poll_pids) && data.poll_pids.length) {
                        pids.push('poll ' + data.poll_pids.join(','));
                    }
                    if (Array.isArray(data.queue_pids) && data.queue_pids.length) {
                        pids.push('queue ' + data.queue_pids.join(','));
                    }
                    const pidHint = pids.length ? ' (' + pids.join('; ') + ')' : '';
                    const color = data.restart_pending
                        ? 'text-amber-300'
                        : (data.poll_running && data.queue_running ? 'text-emerald-300' : 'text-amber-300');
                    processStatusEl.textContent = 'Процессы: ' + poll + ', ' + queue + pending + pidHint;
                    processStatusEl.className = 'text-xs ' + color;
                }

                async function refreshProcessStatus() {
                    if (!processStatusEl) return null;
                    try {
                        const res = await fetch(analystStatusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (res.ok && data.ok) {
                            renderProcessStatus(data);
                            return data;
                        }
                        processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                    return null;
                }

                /** Ждём, пока cron (user o) выполнит отложенный перезапуск по флагу в storage. */
                async function waitForDeferredRestart(maxMs) {
                    const deadline = Date.now() + (maxMs || 90000);
                    while (Date.now() < deadline) {
                        await new Promise(function (r) { setTimeout(r, 3000); });
                        const data = await refreshProcessStatus();
                        if (data && !data.restart_pending) {
                            setStatus('Перезапуск выполнен. Poll и queue worker подхватили настройки из БД.', 'ok');
                            return true;
                        }
                    }
                    setStatus('Cron ещё не завершил перезапуск. Подождите ~1 мин или выполните на сервере: php artisan telegram:ensure-analyst-services', 'error');
                    return false;
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', 'pending');
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(analystRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', 'error');
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            if (data.deferred) {
                                setStatus(data.message || 'Отложенный перезапуск запрошен, ждём cron…', 'pending');
                                if (data.status) renderProcessStatus(data.status);
                                await waitForDeferredRestart(90000);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', 'ok');
                            if (data.status) renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, 'error');
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Запрос getUpdates…', false);
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — напишите боту /start', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + ')', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @elseif ($bot->type === 'strong_level')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Strong Level</strong> — бот ищет <strong>сильные уровни</strong> (конфлюенс нескольких источников),
                        ждёт касание ценой и входит на <strong>отскок</strong> или <strong>пробой</strong> после подтверждения M5/M1.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Источники уровней: Price Action DGT (S/R, POC/VA), SMC Flow (rolling 4H/1D, reversal S/R, 3BR-зоны), swing H1/M15.
                        Параметры индикаторов — на отдельных страницах:
                        <a href="{{ route('bots.indicators.price-action-dgt.edit', $bot) }}" class="text-blue-400 hover:underline">Price Action DGT</a>,
                        <a href="{{ route('bots.indicators.smc-flow.edit', $bot) }}" class="text-blue-400 hover:underline">SMC Flow</a>.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Цепочка решения: загрузка свечей → сбор уровней → кластеризация → фильтр силы/расстояния → режим (отскок/пробой) → подтверждение M5/M1 → paper-сделка.
                    </p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Данные и скан</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей M1 (count)</label>
                        <input type="number" name="count" id="count" min="200" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 500) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_closed_bars" class="block text-xs font-medium text-zinc-400">Мин. закрытых баров</label>
                        <input type="number" name="min_closed_bars" id="min_closed_bars" min="80" max="2000" required
                            value="{{ old('min_closed_bars', $cfg['min_closed_bars'] ?? 200) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_closed_bars')])
                        @error('min_closed_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 82) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
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

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_level_strength')])
                        @error('min_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_level_strength" class="block text-xs font-medium text-zinc-400">Макс. сила уровня (0 = выкл.)</label>
                        <input type="number" name="max_level_strength" id="max_level_strength" min="0" max="100" step="1" required
                            value="{{ old('max_level_strength', $cfg['max_level_strength'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('max_level_strength')])
                        @error('max_level_strength')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_sources" class="block text-xs font-medium text-zinc-400">Мин. источников конфлюенса</label>
                        <input type="number" name="min_sources" id="min_sources" min="1" max="6" required
                            value="{{ old('min_sources', $cfg['min_sources'] ?? 1) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_sources')])
                        @error('min_sources')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="cluster_atr_mult_m5" class="block text-xs font-medium text-zinc-400">Кластер уровней (× ATR M5)</label>
                        <input type="number" name="cluster_atr_mult_m5" id="cluster_atr_mult_m5" min="0.05" max="1" step="0.01" required
                            value="{{ old('cluster_atr_mult_m5', $cfg['cluster_atr_mult_m5'] ?? 0.28) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('cluster_atr_mult_m5')])
                        @error('cluster_atr_mult_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="touch_atr_mult_m1" class="block text-xs font-medium text-zinc-400">Зона касания (× ATR M1)</label>
                        <input type="number" name="touch_atr_mult_m1" id="touch_atr_mult_m1" min="0.05" max="1.5" step="0.01" required
                            value="{{ old('touch_atr_mult_m1', $cfg['touch_atr_mult_m1'] ?? 0.55) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('touch_atr_mult_m1')])
                        @error('touch_atr_mult_m1')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Режим входа и подтверждение</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="entry_mode" class="block text-xs font-medium text-zinc-400">Режим входа</label>
                        @php $entryMode = old('entry_mode', $cfg['entry_mode'] ?? 'both'); @endphp
                        <select name="entry_mode" id="entry_mode" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="both" @selected($entryMode === 'both')>Отскок и пробой</option>
                            <option value="bounce" @selected($entryMode === 'bounce')>Только отскок</option>
                            <option value="breakout" @selected($entryMode === 'breakout')>Только пробой</option>
                        </select>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('entry_mode')])
                        @error('entry_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="bounce_min_wick_ratio_m5" class="block text-xs font-medium text-zinc-400">Отскок: мин. тень M5 (0–1)</label>
                        <input type="number" name="bounce_min_wick_ratio_m5" id="bounce_min_wick_ratio_m5" min="0.15" max="0.8" step="0.01" required
                            value="{{ old('bounce_min_wick_ratio_m5', $cfg['bounce_min_wick_ratio_m5'] ?? 0.28) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('bounce_min_wick_ratio_m5')])
                        @error('bounce_min_wick_ratio_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec_bounce" class="block text-xs font-medium text-zinc-400">Экспирация отскок (сек)</label>
                        <input type="number" name="expiry_sec_bounce" id="expiry_sec_bounce" min="120" max="600" required
                            value="{{ old('expiry_sec_bounce', $cfg['expiry_sec_bounce'] ?? 180) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec_bounce')])
                        @error('expiry_sec_bounce')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec_breakout" class="block text-xs font-medium text-zinc-400">Экспирация пробой (сек)</label>
                        <input type="number" name="expiry_sec_breakout" id="expiry_sec_breakout" min="120" max="600" required
                            value="{{ old('expiry_sec_breakout', $cfg['expiry_sec_breakout'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec_breakout')])
                        @error('expiry_sec_breakout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_flat_strength_extra" class="block text-xs font-medium text-zinc-400">H1 флэт: доп. сила уровня</label>
                        <input type="number" name="h1_flat_strength_extra" id="h1_flat_strength_extra" min="0" max="40" step="1" required
                            value="{{ old('h1_flat_strength_extra', $cfg['h1_flat_strength_extra'] ?? 4) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('h1_flat_strength_extra')])
                        @error('h1_flat_strength_extra')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Дополнительные фильтры</p>
                    <div class="space-y-3 text-sm text-zinc-300">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="require_h1_alignment" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('require_h1_alignment', ($cfg['require_h1_alignment'] ?? false) ? '1' : '') === '1' || (old('require_h1_alignment') === null && ($cfg['require_h1_alignment'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">H1 bias не против входа</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL запрещён при явном H1 down, PUT — при H1 up. Во флэте H1 нужна доп. сила (поле выше), если не включён полный запрет flat.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="hidden" name="block_h1_flat" value="0">
                            <input type="checkbox" name="block_h1_flat" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('block_h1_flat', ($cfg['block_h1_flat'] ?? false) ? '1' : '') === '1' || (old('block_h1_flat') === null && ($cfg['block_h1_flat'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Запрет входа при H1 = flat</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Жёстко: при боковом H1 сделок нет (рекомендуется по статистике — flat давал ~48% WR).</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="bounce_require_m1_confirm" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('bounce_require_m1_confirm', ($cfg['bounce_require_m1_confirm'] ?? false) ? '1' : '') === '1' || (old('bounce_require_m1_confirm') === null && ($cfg['bounce_require_m1_confirm'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Отскок: M1 в сторону входа</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">CALL требует бычью M1, PUT — медвежью. Снимите для OTC: достаточно rejection на M5, M1 может быть нейтральной.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="breakout_require_retest" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('breakout_require_retest', ($cfg['breakout_require_retest'] ?? false) ? '1' : '') === '1' || (old('breakout_require_retest') === null && ($cfg['breakout_require_retest'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Пробой: требовать ретест уровня</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">После пробоя цена должна вернуться касанием к уровню на M5 и снова уйти в сторону пробоя. Отсекает «голые» пробои без подтверждения.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="breakout_vol_above_sma" value="1" class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                @checked(old('breakout_vol_above_sma', ($cfg['breakout_vol_above_sma'] ?? false) ? '1' : '') === '1' || (old('breakout_vol_above_sma') === null && ($cfg['breakout_vol_above_sma'] ?? false)))>
                            <span>
                                <span class="font-medium text-zinc-200">Пробой: объём M5 ≥ 90% от SMA(20)</span>
                                <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Свеча пробоя на M5 должна быть не слабее среднего объёма за 20 баров. Слабые пробои на низком объёме отфильтровываются.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Старшие таймфреймы (MTF)</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="m5_take" class="block text-xs font-medium text-zinc-400">Свечей M5</label>
                        <input type="number" name="m5_take" id="m5_take" min="20" max="300" required
                            value="{{ old('m5_take', $cfg['m5_take'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m5_take')])
                        @error('m5_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="m15_take" class="block text-xs font-medium text-zinc-400">Свечей M15</label>
                        <input type="number" name="m15_take" id="m15_take" min="20" max="300" required
                            value="{{ old('m15_take', $cfg['m15_take'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('m15_take')])
                        @error('m15_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="h1_take" class="block text-xs font-medium text-zinc-400">Свечей H1</label>
                        <input type="number" name="h1_take" id="h1_take" min="10" max="200" required
                            value="{{ old('h1_take', $cfg['h1_take'] ?? 12) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('h1_take')])
                        @error('h1_take')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram</p>
                    <p class="text-[11px] text-zinc-500">Push при новом сигнале и paper-сделке (cron <code class="text-zinc-400">bots:scan</code>). Token и chat_id — как у других ботов, один получатель.</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled_strong_level" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Без галочки сделки создаются в платформе, но сообщения в Telegram не уходят.</span>
                        </span>
                    </label>
                    @include('bots._telegram-notify-result-field', [
                        'cfg' => $cfg,
                        'telegramNotifyResultId' => 'telegram_notify_result_strong_level',
                    ])
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="telegram_notify_on" class="block text-xs font-medium text-zinc-400">Когда уведомлять</label>
                            @php $telegramNotifyOn = old('telegram_notify_on', $cfg['telegram_notify_on'] ?? 'signal_trade'); @endphp
                            <select name="telegram_notify_on" id="telegram_notify_on" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="signal_trade" @selected($telegramNotifyOn === 'signal_trade')>Новый сигнал + paper-сделка</option>
                                <option value="off" @selected($telegramNotifyOn === 'off')>Не уведомлять (даже если Telegram включён)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_notify_on')])
                        @error('telegram_notify_on')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_alert_format" class="block text-xs font-medium text-zinc-400">Формат сообщения</label>
                            @php $telegramAlertFormat = old('telegram_alert_format', $cfg['telegram_alert_format'] ?? 'brief'); @endphp
                            <select name="telegram_alert_format" id="telegram_alert_format" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="brief" @selected($telegramAlertFormat === 'brief')>Краткий</option>
                                <option value="detailed" @selected($telegramAlertFormat === 'detailed')>Развёрнутый</option>
                                <option value="card" @selected($telegramAlertFormat === 'card')>Как на карточке</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_alert_format')])
                        @error('telegram_alert_format')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_send_chart" class="block text-xs font-medium text-zinc-400">График в алерте</label>
                            @php
                                $telegramSendChart = old('telegram_send_chart');
                                if ($telegramSendChart === null) {
                                    $telegramSendChart = ($cfg['telegram_send_chart'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart" id="telegram_send_chart" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChart === '1')>Текст + график M1 (PNG)</option>
                                <option value="0" @selected((string) $telegramSendChart === '0')>Только текст (без картинки)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart')])
                        @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_send_chart_m5" class="block text-xs font-medium text-zinc-400">Доп. график M5</label>
                            @php
                                $telegramSendChartM5 = old('telegram_send_chart_m5');
                                if ($telegramSendChartM5 === null) {
                                    $telegramSendChartM5 = ($cfg['telegram_send_chart_m5'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart_m5" id="telegram_send_chart_m5" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChartM5 === '1')>Отправлять второй PNG M5</option>
                                <option value="0" @selected((string) $telegramSendChartM5 === '0')>Только M1 (без M5)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart_m5')])
                        @error('telegram_send_chart_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled" value="1"
                                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                                <span>
                                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                                    <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Inline-кнопка под алертом: callback в Strong Level → LLM-анализ Chart Analyst в этом же чате. Нужен poll (кнопка ниже).</span>
                                </span>
                            </label>
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_enabled')])
                        @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_analyst_bot_id" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                            @php $selectedAnalystId = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="">Авто (первый Chart Analyst)</option>
                                @foreach ($chartAnalystBots ?? [] as $analystBot)
                                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystId === (string) $analystBot->id)>
                                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                                    </option>
                                @endforeach
                            </select>
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_bot_id')])
                        @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_analyst_llm_question" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question" rows="2"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_question')])
                        @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        {{-- Статус poll растягиваем на всю строку, чтобы он не смешивался с полями ниже в двухколоночной сетке. --}}
                        <div class="space-y-1 sm:col-span-2">
                            <p id="telegram-process-status-strong-level" class="text-xs text-zinc-500">Проверка poll…</p>
                        </div>
                        {{-- У каждого элемента в grid должен быть свой контейнер, иначе соседняя колонка с action-кнопками «съезжает». --}}
                        <div class="space-y-1">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token_strong_level',
                                'hint' => 'Токен от @BotFather (напр. @strong_level_bot). Пустое поле при сохранении — оставить текущий.',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id_strong_level" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id_strong_level"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_chat_id')])
                        @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        @include('bots._telegram-auto-subscribe-field', [
                            'cfg' => $cfg,
                            'telegramAutoSubscribeId' => 'telegram_auto_subscribe_strong_level',
                        ])
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn-strong-level"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id
                            </button>
                            <button type="button" id="telegram-test-btn-strong-level"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-strong-level"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status-strong-level" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Напишите боту
                        <a href="https://t.me/strong_level_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@strong_level_bot</a>
                        → /start, сохраните token, затем «Получить chat_id».
                    </p>
                </div>

                <div class="rounded border border-amber-900/40 bg-amber-950/20 px-4 py-3 text-[11px] text-amber-100/80">
                    <p class="font-medium text-amber-200/90">Почему сигналов мало?</p>
                    <p class="mt-1">Раньше формула силы не давала 68 при 2 источниках — это исправлено. Для частоты: min_level_strength 55–58, min_sources 1, touch 0.55+, bounce wick 0.25–0.28, снять H1/M1/ретест/объём.</p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn-strong-level');
                const testBtn = document.getElementById('telegram-test-btn-strong-level');
                const restartBtn = document.getElementById('telegram-restart-btn-strong-level');
                const statusEl = document.getElementById('telegram-action-status-strong-level');
                const processStatusEl = document.getElementById('telegram-process-status-strong-level');
                const chatInput = document.getElementById('telegram_chat_id_strong_level');
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
                        else processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', false);
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(slRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', false);
                            renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Останавливаем poll и ждём /start (до 15 сек)…', false);
                        discoverBtn.disabled = true;
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — нажмите кнопку и сразу /start @strong_level_bot', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + '). Сохраните настройки.', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            discoverBtn.disabled = false;
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки, если меняли token/chat_id)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @elseif ($bot->type === 'strong_trend_demarker')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Strong Trend + DeMarker</strong> — серия одноцветных свечей
                        подтверждается выходом DeMarker из зоны.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Если «Неотработок до сделки» &gt; 0, бот сначала проверяет сигналы виртуально по каждой паре.
                        После N подряд LOSS следующий сигнал открывает paper-сделку. 0 — сразу вход.
                        Виртуальный WIN или DRAW сбрасывает счётчик этой пары.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
                        @php $demarkerTimeframe = (int) old('timeframe', $cfg['timeframe'] ?? \App\Services\CandleService::TF_S30); @endphp
                        <select name="timeframe" id="timeframe" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($demarkerTimeframe === \App\Services\CandleService::TF_S30)>S30 — сделка 30 сек</option>
                            <option value="1" @selected($demarkerTimeframe === 1)>M1 — сделка 60 сек</option>
                        </select>
                        @error('timeframe')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="streak_len" class="block text-xs font-medium text-zinc-400">Свечей подряд (0 = выкл.)</label>
                        <input type="number" name="streak_len" id="streak_len" min="0" max="30" required
                            value="{{ old('streak_len', $cfg['streak_len'] ?? 6) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">0 — только выход DeMarker из зоны. 1–30 — N одноцветных закрытых свечей <span class="text-zinc-300">до входа</span> осциллятора в нижнюю/верхнюю зону.</p>
                        @error('streak_len')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="losses_before_entry" class="block text-xs font-medium text-zinc-400">Неотработок до сделки (0 = выкл.)</label>
                        <input type="number" name="losses_before_entry" id="losses_before_entry" min="0" max="20" required
                            value="{{ old('losses_before_entry', $cfg['losses_before_entry'] ?? 2) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">0 — сразу paper-сделка. 2: первые два убытка виртуальные, на следующем валидном сигнале — вход.</p>
                        @error('losses_before_entry')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="candle_strictness" class="block text-xs font-medium text-zinc-400">Строгость свечей</label>
                        @php $demarkerStrictness = old('candle_strictness', $cfg['candle_strictness'] ?? 'strict'); @endphp
                        <select name="candle_strictness" id="candle_strictness" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="strict" @selected($demarkerStrictness === 'strict')>Строго (close &gt; open / close &lt; open)</option>
                            <option value="with_doji" @selected($demarkerStrictness === 'with_doji')>С доджи (close ≥ open / close ≤ open)</option>
                        </select>
                        <p class="text-[11px] text-zinc-500">Как в Strong Trend Telegram: влияет на определение бычьей/медвежьей серии.</p>
                        @error('candle_strictness')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела в диапазоне (0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Средняя |close−open| / (high−low) по серии должна быть не ниже порога.</p>
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">DeMarker: выход из зоны</p>
                <div class="grid gap-4 rounded border border-zinc-800 bg-zinc-900/40 p-4 sm:grid-cols-3">
                    <div class="space-y-1">
                        <label for="demarker_period" class="block text-xs font-medium text-zinc-400">Период</label>
                        <input type="number" name="demarker_period" id="demarker_period" min="1" max="500" required
                            value="{{ old('demarker_period', $cfg['demarker_period'] ?? 6) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('demarker_period')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="demarker_oversold" class="block text-xs font-medium text-zinc-400">Нижняя зона (%)</label>
                        <input type="number" name="demarker_oversold" id="demarker_oversold" min="0" max="49.99" step="0.01" required
                            value="{{ old('demarker_oversold', $cfg['demarker_oversold'] ?? 10) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Выход вверх из нижней зоны → CALL. Серия свечей (если включена) должна быть до входа в эту зону.</p>
                        @error('demarker_oversold')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="demarker_overbought" class="block text-xs font-medium text-zinc-400">Верхняя зона (%)</label>
                        <input type="number" name="demarker_overbought" id="demarker_overbought" min="50.01" max="100" step="0.01" required
                            value="{{ old('demarker_overbought', $cfg['demarker_overbought'] ?? 90) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Выход вниз из верхней зоны → PUT. Серия свечей (если включена) должна быть до входа в эту зону.</p>
                        @error('demarker_overbought')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <input type="hidden" name="expiry_sec" value="{{ $demarkerTimeframe === \App\Services\CandleService::TF_S30 ? 30 : 60 }}">
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 30) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="dedupe_bucket_sec" class="block text-xs font-medium text-zinc-400">Защита от дубля (сек)</label>
                        <input type="number" name="dedupe_bucket_sec" id="dedupe_bucket_sec" min="30" max="3600" required
                            value="{{ old('dedupe_bucket_sec', $cfg['dedupe_bucket_sec'] ?? ($demarkerTimeframe === \App\Services\CandleService::TF_S30 ? 30 : 60)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('dedupe_bucket_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
            </div>
        @elseif ($bot->type === \App\Support\DemarkerDualFastConfig::BOT_TYPE)
            @php
                $ddf = \App\Support\DemarkerDualFastConfig::defaultConfig();
                $demarkerTimeframe = (int) old('timeframe', $cfg['timeframe'] ?? $ddf['timeframe']);
            @endphp
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Dual DeMarker Fast</strong> — выход основного DeMarker с дельтой,
                        подтверждение быстрым DeM2 в окне N баров, мин. свечей в зоне и фильтр импульса.
                        Второе колено открывается только после LOSS первого.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="timeframe" class="block text-xs font-medium text-zinc-400">Таймфрейм</label>
                        <select name="timeframe" id="timeframe" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="{{ \App\Services\CandleService::TF_S30 }}" @selected($demarkerTimeframe === \App\Services\CandleService::TF_S30)>S30 — сделка 30 сек</option>
                            <option value="1" @selected($demarkerTimeframe === 1)>M1 — сделка 60 сек</option>
                        </select>
                        @error('timeframe')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? $ddf['count']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="streak_len" class="block text-xs font-medium text-zinc-400">Свечей подряд (0 = выкл.)</label>
                        <input type="number" name="streak_len" id="streak_len" min="0" max="30" required
                            value="{{ old('streak_len', $cfg['streak_len'] ?? $ddf['streak_len']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Опциональный фильтр поверх dual-DeMarker: N одноцветных до входа в зону.</p>
                        @error('streak_len')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="entries_per_signal" class="block text-xs font-medium text-zinc-400">Колен на сигнал</label>
                        <input type="number" name="entries_per_signal" id="entries_per_signal" min="1" max="5" required
                            value="{{ old('entries_per_signal', $cfg['entries_per_signal'] ?? $ddf['entries_per_signal']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">2 = после LOSS первого сразу второе колено в ту же сторону.</p>
                        @error('entries_per_signal')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="candle_strictness" class="block text-xs font-medium text-zinc-400">Строгость свечей (для streak)</label>
                        @php $demarkerStrictness = old('candle_strictness', $cfg['candle_strictness'] ?? $ddf['candle_strictness']); @endphp
                        <select name="candle_strictness" id="candle_strictness" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="strict" @selected($demarkerStrictness === 'strict')>Строго</option>
                            <option value="with_doji" @selected($demarkerStrictness === 'with_doji')>С доджи</option>
                        </select>
                        @error('candle_strictness')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела (streak, 0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? $ddf['min_body_to_range_ratio']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">DeMarker 1 (основной)</p>
                <div class="grid gap-4 rounded border border-zinc-800 bg-zinc-900/40 p-4 sm:grid-cols-4">
                    <div class="space-y-1">
                        <label for="demarker_period" class="block text-xs font-medium text-zinc-400">Период</label>
                        <input type="number" name="demarker_period" id="demarker_period" min="1" max="500" required
                            value="{{ old('demarker_period', $cfg['demarker_period'] ?? $ddf['demarker_period']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('demarker_period')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="demarker_oversold" class="block text-xs font-medium text-zinc-400">Нижняя зона %</label>
                        <input type="number" name="demarker_oversold" id="demarker_oversold" min="0" max="49.99" step="0.01" required
                            value="{{ old('demarker_oversold', $cfg['demarker_oversold'] ?? $ddf['demarker_oversold']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('demarker_oversold')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="demarker_overbought" class="block text-xs font-medium text-zinc-400">Верхняя зона %</label>
                        <input type="number" name="demarker_overbought" id="demarker_overbought" min="50.01" max="100" step="0.01" required
                            value="{{ old('demarker_overbought', $cfg['demarker_overbought'] ?? $ddf['demarker_overbought']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('demarker_overbought')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="demarker_level_delta" class="block text-xs font-medium text-zinc-400">LEVEL_DELTA</label>
                        <input type="number" name="demarker_level_delta" id="demarker_level_delta" min="0" max="50" step="0.1" required
                            value="{{ old('demarker_level_delta', $cfg['demarker_level_delta'] ?? $ddf['demarker_level_delta']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">CALL: ≤OS → ≥OS+Δ; PUT: ≥OB → ≤OB−Δ.</p>
                        @error('demarker_level_delta')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">DeMarker 2 (быстрый)</p>
                <div class="grid gap-4 rounded border border-zinc-800 bg-zinc-900/40 p-4 sm:grid-cols-3">
                    <div class="space-y-1 sm:col-span-3">
                        <label class="inline-flex items-center gap-2 text-xs text-zinc-300">
                            <input type="checkbox" name="second_demarker_enabled" value="1"
                                @checked(old('second_demarker_enabled', $cfg['second_demarker_enabled'] ?? $ddf['second_demarker_enabled']))
                                class="rounded border-zinc-600 bg-zinc-900 text-emerald-500">
                            Включить подтверждение DeM2
                        </label>
                    </div>
                    <div class="space-y-1">
                        <label for="second_demarker_period" class="block text-xs font-medium text-zinc-400">Период</label>
                        <input type="number" name="second_demarker_period" id="second_demarker_period" min="1" max="500" required
                            value="{{ old('second_demarker_period', $cfg['second_demarker_period'] ?? $ddf['second_demarker_period']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('second_demarker_period')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="second_demarker_oversold" class="block text-xs font-medium text-zinc-400">Нижняя %</label>
                        <input type="number" name="second_demarker_oversold" id="second_demarker_oversold" min="0" max="49.99" step="0.01" required
                            value="{{ old('second_demarker_oversold', $cfg['second_demarker_oversold'] ?? $ddf['second_demarker_oversold']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('second_demarker_oversold')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="second_demarker_overbought" class="block text-xs font-medium text-zinc-400">Верхняя %</label>
                        <input type="number" name="second_demarker_overbought" id="second_demarker_overbought" min="50.01" max="100" step="0.01" required
                            value="{{ old('second_demarker_overbought', $cfg['second_demarker_overbought'] ?? $ddf['second_demarker_overbought']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('second_demarker_overbought')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="second_demarker_level_delta" class="block text-xs font-medium text-zinc-400">LEVEL_DELTA</label>
                        <input type="number" name="second_demarker_level_delta" id="second_demarker_level_delta" min="0" max="50" step="0.1" required
                            value="{{ old('second_demarker_level_delta', $cfg['second_demarker_level_delta'] ?? $ddf['second_demarker_level_delta']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('second_demarker_level_delta')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="second_demarker_confirm_bars" class="block text-xs font-medium text-zinc-400">Окно подтверждения (баров)</label>
                        <input type="number" name="second_demarker_confirm_bars" id="second_demarker_confirm_bars" min="1" max="30" required
                            value="{{ old('second_demarker_confirm_bars', $cfg['second_demarker_confirm_bars'] ?? $ddf['second_demarker_confirm_bars']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">DeM2 может подтвердить на текущем или на N−1 предыдущих барах.</p>
                        @error('second_demarker_confirm_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Фильтры зоны и импульса</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="space-y-1">
                        <label for="min_candles_in_zone" class="block text-xs font-medium text-zinc-400">Мин. свечей в зоне DeM1</label>
                        <input type="number" name="min_candles_in_zone" id="min_candles_in_zone" min="1" max="50" required
                            value="{{ old('min_candles_in_zone', $cfg['min_candles_in_zone'] ?? $ddf['min_candles_in_zone']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        <p class="text-[11px] text-zinc-500">Бар выхода не считается.</p>
                        @error('min_candles_in_zone')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_exit_candle_ratio" class="block text-xs font-medium text-zinc-400">Макс. |H−L| / avg</label>
                        <input type="number" name="max_exit_candle_ratio" id="max_exit_candle_ratio" min="0.1" max="20" step="0.1" required
                            value="{{ old('max_exit_candle_ratio', $cfg['max_exit_candle_ratio'] ?? $ddf['max_exit_candle_ratio']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('max_exit_candle_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="exit_candle_avg_bars" class="block text-xs font-medium text-zinc-400">Баров для avg |H−L|</label>
                        <input type="number" name="exit_candle_avg_bars" id="exit_candle_avg_bars" min="1" max="100" required
                            value="{{ old('exit_candle_avg_bars', $cfg['exit_candle_avg_bars'] ?? $ddf['exit_candle_avg_bars']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('exit_candle_avg_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <input type="hidden" name="expiry_sec" value="{{ $demarkerTimeframe === \App\Services\CandleService::TF_S30 ? 30 : 60 }}">
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? $ddf['min_confidence']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? $ddf['min_payout']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? $ddf['stale_after_sec']) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="dedupe_bucket_sec" class="block text-xs font-medium text-zinc-400">Защита от дубля (сек)</label>
                        <input type="number" name="dedupe_bucket_sec" id="dedupe_bucket_sec" min="30" max="3600" required
                            value="{{ old('dedupe_bucket_sec', $cfg['dedupe_bucket_sec'] ?? ($demarkerTimeframe === \App\Services\CandleService::TF_S30 ? 30 : 60)) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                        @error('dedupe_bucket_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                @include('bots._strategy-telegram-settings', ['bot' => $bot, 'cfg' => $cfg])
            </div>
        @elseif ($bot->type === 'strong_trend_telegram')
            <div class="space-y-5 border-t border-zinc-800 pt-4">
                <div class="space-y-2 text-sm text-zinc-400">
                    <p>
                        <strong class="text-zinc-300">Strong Trend Telegram</strong> — детектор импульса на M1:
                        <strong>N закрытых свечей подряд</strong> в одну сторону → push-алерт в Telegram.
                    </p>
                    <p class="text-[11px] text-zinc-500">
                        Режим исполнения задаётся в настройке ниже: <code class="text-zinc-400">Только алерты</code>
                        или <code class="text-zinc-400">Сделки + алерты</code>.
                        CALL = серия бычьих свечей (тренд вверх), PUT = медвежья серия.
                        Опционально можно ждать одну контр-свечу и входить в продолжение.
                    </p>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Детектор серии</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="execution_mode" class="block text-xs font-medium text-zinc-400">Режим исполнения</label>
                        @php $trendExecutionMode = old('execution_mode', $cfg['execution_mode'] ?? (($cfg['notify_only'] ?? true) ? 'alert_only' : 'signal_trade')); @endphp
                        <select name="execution_mode" id="execution_mode" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="alert_only" @selected($trendExecutionMode === 'alert_only')>Только алерты (без сделок)</option>
                            <option value="signal_trade" @selected($trendExecutionMode === 'signal_trade')>Сделки + алерты (как strong_level)</option>
                        </select>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('execution_mode')])
                        @error('execution_mode')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="count" class="block text-xs font-medium text-zinc-400">Свечей в выборке (count)</label>
                        <input type="number" name="count" id="count" min="10" max="5000" required
                            value="{{ old('count', $cfg['count'] ?? 120) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('count')])
                        @error('count')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="streak_len" class="block text-xs font-medium text-zinc-400">Свечей подряд (streak_len)</label>
                        <input type="number" name="streak_len" id="streak_len" min="2" max="30" required
                            value="{{ old('streak_len', $cfg['streak_len'] ?? 6) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('streak_len')])
                        @error('streak_len')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="expiry_sec" class="block text-xs font-medium text-zinc-400">Экспирация сделки (сек)</label>
                        <input type="number" name="expiry_sec" id="expiry_sec" min="30" max="86400" required
                            value="{{ old('expiry_sec', $cfg['expiry_sec'] ?? 60) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('expiry_sec')])
                        @error('expiry_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_confidence" class="block text-xs font-medium text-zinc-400">Мин. confidence (0–1)</label>
                        <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01" required
                            value="{{ old('min_confidence', $cfg['min_confidence'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_confidence', $bot->type)])
                        @error('min_confidence')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_payout" class="block text-xs font-medium text-zinc-400">Мин. payout (%)</label>
                        <input type="number" name="min_payout" id="min_payout" min="0" max="100" step="0.1" required
                            value="{{ old('min_payout', $cfg['min_payout'] ?? 80) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_payout')])
                        @error('min_payout')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="stale_after_sec" class="block text-xs font-medium text-zinc-400">Свежесть текущей свечи (сек)</label>
                        <input type="number" name="stale_after_sec" id="stale_after_sec" min="5" max="600" required
                            value="{{ old('stale_after_sec', $cfg['stale_after_sec'] ?? 45) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('stale_after_sec')])
                        @error('stale_after_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="dedupe_bucket_sec" class="block text-xs font-medium text-zinc-400">Dedupe (сек)</label>
                        <input type="number" name="dedupe_bucket_sec" id="dedupe_bucket_sec" min="60" max="3600" required
                            value="{{ old('dedupe_bucket_sec', $cfg['dedupe_bucket_sec'] ?? 300) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('dedupe_bucket_sec')])
                        @error('dedupe_bucket_sec')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="candle_strictness" class="block text-xs font-medium text-zinc-400">Строгость свечей</label>
                        @php $strictness = old('candle_strictness', $cfg['candle_strictness'] ?? 'strict'); @endphp
                        <select name="candle_strictness" id="candle_strictness" required
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                            <option value="strict" @selected($strictness === 'strict')>Строго (close &gt; open / close &lt; open)</option>
                            <option value="with_doji" @selected($strictness === 'with_doji')>С доджи (close ≥ open / close ≤ open)</option>
                        </select>

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('candle_strictness')])
                        @error('candle_strictness')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="min_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Мин. доля тела в диапазоне (0 = выкл.)</label>
                        <input type="number" name="min_body_to_range_ratio" id="min_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('min_body_to_range_ratio', $cfg['min_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('min_body_to_range_ratio')])
                        @error('min_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1">
                        <label for="max_body_to_range_ratio" class="block text-xs font-medium text-zinc-400">Макс. доля тела (0 = выкл.)</label>
                        <input type="number" name="max_body_to_range_ratio" id="max_body_to_range_ratio" min="0" max="1" step="0.01" required
                            value="{{ old('max_body_to_range_ratio', $cfg['max_body_to_range_ratio'] ?? 0) }}"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                        @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('max_body_to_range_ratio')])
                        @error('max_body_to_range_ratio')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Контр-свеча и продолжение</p>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <input type="hidden" name="wait_opposite_then_continue" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="wait_opposite_then_continue" id="wait_opposite_then_continue" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('wait_opposite_then_continue', $cfg['wait_opposite_then_continue'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Ждать контр-свечу, входить в продолжение</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                                После серии из N свечей одного цвета ждём ещё одну свечу <em>в обратную</em> сторону,
                                затем сигнал в сторону исходного импульса (6↑ + 1↓ → CALL; 6↓ + 1↑ → PUT).
                                Без галочки — вход сразу на N-й свече серии, как раньше.
                            </span>
                        </span>
                    </label>
                    @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('wait_opposite_then_continue')])
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Откат к EMA/HMA (не входить на голой N-й свече)</p>
                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <input type="hidden" name="require_pullback_to_ma" value="0">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="require_pullback_to_ma" id="require_pullback_to_ma" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('require_pullback_to_ma', $cfg['require_pullback_to_ma'] ?? false))>
                        <span>
                            <span class="text-sm font-medium text-zinc-200">Требовать откат к MA перед входом</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">
                                Мягкий фильтр late-entry: в серии N свечей цена должна коснуться зоны EMA/HMA
                                <em>или</em> не улететь дальше «макс. удаления × ATR» от MA. Без галочки — вход сразу на N-й свече.
                            </span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label for="pullback_bars" class="block text-xs font-medium text-zinc-400">Баров на откат (legacy)</label>
                            <input type="number" name="pullback_bars" id="pullback_bars" min="1" max="5" required
                                value="{{ old('pullback_bars', $cfg['pullback_bars'] ?? 2) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('pullback_bars')])
                        @error('pullback_bars')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_ma_type" class="block text-xs font-medium text-zinc-400">Тип MA</label>
                            @php $pullbackMaType = old('pullback_ma_type', $cfg['pullback_ma_type'] ?? 'ema'); @endphp
                            <select name="pullback_ma_type" id="pullback_ma_type" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="ema" @selected($pullbackMaType === 'ema')>EMA</option>
                                <option value="hma" @selected($pullbackMaType === 'hma')>HMA (Hull)</option>
                            </select>
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('pullback_ma_type')])
                        @error('pullback_ma_type')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_ma_length" class="block text-xs font-medium text-zinc-400">Длина MA</label>
                            <input type="number" name="pullback_ma_length" id="pullback_ma_length" min="2" max="100" required
                                value="{{ old('pullback_ma_length', $cfg['pullback_ma_length'] ?? 9) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('pullback_ma_length')])
                        @error('pullback_ma_length')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_touch_atr_mult" class="block text-xs font-medium text-zinc-400">Зона касания MA (× ATR)</label>
                            <input type="number" name="pullback_touch_atr_mult" id="pullback_touch_atr_mult" min="0.05" max="2" step="0.01" required
                                value="{{ old('pullback_touch_atr_mult', $cfg['pullback_touch_atr_mult'] ?? 0.35) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('pullback_touch_atr_mult')])
                        @error('pullback_touch_atr_mult')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="pullback_max_extension_atr" class="block text-xs font-medium text-zinc-400">Макс. удаление от MA (× ATR)</label>
                            <input type="number" name="pullback_max_extension_atr" id="pullback_max_extension_atr" min="0.5" max="5" step="0.1" required
                                value="{{ old('pullback_max_extension_atr', $cfg['pullback_max_extension_atr'] ?? 1.8) }}"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('pullback_max_extension_atr')])
                        @error('pullback_max_extension_atr')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                <div class="space-y-3 rounded border border-zinc-800 bg-zinc-900/40 p-4">
                    <p class="text-sm font-medium text-zinc-300">Telegram</p>
                    <p class="text-[11px] text-zinc-500">Алерты отправляются при каждом срабатывании scan (cron <code class="text-zinc-400">bots:scan</code>). Сохраните token и chat_id, затем «Отправить тест».</p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="telegram_enabled" id="telegram_enabled" value="1"
                            class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                            @checked(old('telegram_enabled', $cfg['telegram_enabled'] ?? true))>
                        <span>
                            <span class="text-sm font-medium text-zinc-300">Отправлять алерты в Telegram</span>
                            <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Без галочки сигналы пишутся в БД, но сообщения в Telegram не уходят.</span>
                        </span>
                    </label>
                    @include('bots._telegram-notify-result-field', [
                        'cfg' => $cfg,
                        'telegramNotifyResultId' => 'telegram_notify_result_trend',
                    ])
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1 sm:col-span-2">
                            <label class="flex items-start gap-2">
                                <input type="checkbox" name="telegram_analyst_llm_enabled" id="telegram_analyst_llm_enabled_trend" value="1"
                                    class="mt-0.5 rounded border-zinc-600 bg-zinc-800 text-blue-500"
                                    @checked(old('telegram_analyst_llm_enabled', $cfg['telegram_analyst_llm_enabled'] ?? true))>
                                <span>
                                    <span class="text-sm font-medium text-zinc-300">Кнопка «{{ \App\Services\Telegram\StrongLevelTelegramLlmButton::BUTTON_LABEL }}»</span>
                                    <span class="mt-0.5 block text-[11px] font-normal text-zinc-500">Inline-кнопка под алертом: callback в @trend1123_bot → LLM-анализ Chart Analyst в этом же чате. Нужен poll (кнопка ниже).</span>
                                </span>
                            </label>
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_enabled')])
                        @error('telegram_analyst_llm_enabled')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_analyst_bot_id_trend" class="block text-xs font-medium text-zinc-400">Бот Chart Analyst</label>
                            @php $selectedAnalystIdTrend = old('telegram_analyst_bot_id', $cfg['telegram_analyst_bot_id'] ?? ''); @endphp
                            <select name="telegram_analyst_bot_id" id="telegram_analyst_bot_id_trend"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="">Авто (первый Chart Analyst)</option>
                                @foreach ($chartAnalystBots ?? [] as $analystBot)
                                    <option value="{{ $analystBot->id }}" @selected((string) $selectedAnalystIdTrend === (string) $analystBot->id)>
                                        #{{ $analystBot->id }} — {{ $analystBot->name }}@if(!$analystBot->is_active) (выкл.)@endif
                                    </option>
                                @endforeach
                            </select>
                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_bot_id')])
                        @error('telegram_analyst_bot_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_analyst_llm_question_trend" class="block text-xs font-medium text-zinc-400">Вопрос к LLM по кнопке</label>
                            <textarea name="telegram_analyst_llm_question" id="telegram_analyst_llm_question_trend" rows="2"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">{{ old('telegram_analyst_llm_question', $cfg['telegram_analyst_llm_question'] ?? \App\Services\Telegram\StrongLevelTelegramLlmButton::DEFAULT_LLM_QUESTION) }}</textarea>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_analyst_llm_question')])
                        @error('telegram_analyst_llm_question')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <p id="telegram-process-status-trend" class="text-xs text-zinc-500">Проверка poll…</p>
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_send_chart_trend" class="block text-xs font-medium text-zinc-400">Миниатюра M1 в алерте</label>
                            @php
                                $telegramSendChartTrend = old('telegram_send_chart');
                                if ($telegramSendChartTrend === null) {
                                    $telegramSendChartTrend = ($cfg['telegram_send_chart'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart" id="telegram_send_chart_trend" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChartTrend === '1')>Текст + графики PNG</option>
                                <option value="0" @selected((string) $telegramSendChartTrend === '0')>Только текст (без графика)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart')])
                        @error('telegram_send_chart')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            <label for="telegram_send_chart_m5_trend" class="block text-xs font-medium text-zinc-400">Доп. график M5</label>
                            @php
                                $telegramSendChartM5Trend = old('telegram_send_chart_m5');
                                if ($telegramSendChartM5Trend === null) {
                                    $telegramSendChartM5Trend = ($cfg['telegram_send_chart_m5'] ?? true) ? '1' : '0';
                                }
                            @endphp
                            <select name="telegram_send_chart_m5" id="telegram_send_chart_m5_trend" required
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100">
                                <option value="1" @selected((string) $telegramSendChartM5Trend === '1')>Отправлять второй PNG M5</option>
                                <option value="0" @selected((string) $telegramSendChartM5Trend === '0')>Только M1 (без M5)</option>
                            </select>

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_send_chart_m5')])
                        @error('telegram_send_chart_m5')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        <div class="space-y-1 sm:col-span-2">
                            @include('bots._telegram-bot-token-field', [
                                'inputId' => 'telegram_bot_token',
                                'placeholder' => '8814450509:AAF...',
                                'hint' => 'Токен от @BotFather для бота-нотификатора. Пустое поле при сохранении — оставить текущий.',
                            ])
                        </div>
                        <div class="space-y-1">
                            <label for="telegram_chat_id" class="block text-xs font-medium text-zinc-400">Chat ID</label>
                            <input type="text" name="telegram_chat_id" id="telegram_chat_id"
                                value="{{ old('telegram_chat_id', $cfg['telegram_chat_id'] ?? '') }}"
                                placeholder="123456789"
                                class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 font-mono">

                            @include('partials.field-hint', ['text' => \App\Support\SettingsFieldHints::bot('telegram_chat_id')])
                        @error('telegram_chat_id')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        </div>
                        @include('bots._telegram-auto-subscribe-field', [
                            'cfg' => $cfg,
                            'telegramAutoSubscribeId' => 'telegram_auto_subscribe_trend',
                        ])
                        <div class="space-y-1 flex flex-col justify-end gap-2">
                            <button type="button" id="telegram-discover-btn"
                                class="rounded border border-zinc-600 bg-zinc-800 px-3 py-2 text-xs font-medium text-zinc-200 hover:bg-zinc-700">
                                Получить chat_id из Telegram
                            </button>
                            <button type="button" id="telegram-test-btn"
                                class="rounded border border-blue-700 bg-blue-950/50 px-3 py-2 text-xs font-medium text-blue-200 hover:bg-blue-900/50">
                                Отправить тест
                            </button>
                            <button type="button" id="telegram-restart-btn-trend"
                                class="rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-200 hover:bg-amber-900/50">
                                Перезапустить poll + queue
                            </button>
                        </div>
                    </div>
                    <p id="telegram-action-status" class="text-xs text-zinc-500"></p>
                    <p class="text-[11px] text-zinc-500">
                        Если «Получить chat_id» возвращает пустой список — откройте
                        <a href="https://t.me/trend1123_bot" target="_blank" rel="noopener" class="text-blue-400 hover:underline">@trend1123_bot</a>,
                        нажмите Start, затем повторите.
                    </p>
                </div>
            </div>
            <script>
            (function () {
                const discoverBtn = document.getElementById('telegram-discover-btn');
                const testBtn = document.getElementById('telegram-test-btn');
                const restartBtn = document.getElementById('telegram-restart-btn-trend');
                const statusEl = document.getElementById('telegram-action-status');
                const processStatusEl = document.getElementById('telegram-process-status-trend');
                const chatInput = document.getElementById('telegram_chat_id');
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const discoverUrl = @json(route('bots.telegram.discover-chat', $bot));
                const testUrl = @json(route('bots.telegram.test', $bot));
                const pollStatusUrl = @json(route('bots.telegram.strong-level-status', $bot));
                const pollRestartUrl = @json(route('bots.telegram.strong-level-restart', $bot));

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
                        const res = await fetch(pollStatusUrl, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        if (res.ok && data.ok) renderProcessStatus(data);
                        else processStatusEl.textContent = 'Не удалось проверить процессы';
                    } catch (e) {
                        processStatusEl.textContent = 'Ошибка проверки процессов';
                    }
                }

                refreshProcessStatus();

                if (restartBtn) {
                    restartBtn.addEventListener('click', async function () {
                        setStatus('Перезапуск poll и queue worker…', false);
                        restartBtn.disabled = true;
                        try {
                            const res = await fetch(pollRestartUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.message || 'Не удалось перезапустить', true);
                                if (data.status) renderProcessStatus(data.status);
                                return;
                            }
                            setStatus(data.message || 'Перезапущено', false);
                            renderProcessStatus(data.status);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        } finally {
                            restartBtn.disabled = false;
                        }
                    });
                }

                if (discoverBtn) {
                    discoverBtn.addEventListener('click', async function () {
                        setStatus('Запрос getUpdates…', false);
                        try {
                            const res = await fetch(discoverUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || data.hint || 'Ошибка getUpdates', true);
                                return;
                            }
                            const chats = data.chats || [];
                            if (chats.length === 0) {
                                setStatus(data.hint || 'Чатов не найдено — напишите боту /start', true);
                                return;
                            }
                            const first = chats[0];
                            if (chatInput && first.id) chatInput.value = first.id;
                            const label = first.username ? '@' + first.username : (first.first_name || first.title || first.id);
                            setStatus('Найден chat_id: ' + first.id + ' (' + label + ')', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }

                if (testBtn) {
                    testBtn.addEventListener('click', async function () {
                        setStatus('Отправка теста… (сначала сохраните настройки, если меняли token/chat_id)', false);
                        try {
                            const res = await fetch(testUrl, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            if (!res.ok || !data.ok) {
                                setStatus(data.error || 'Не удалось отправить', true);
                                return;
                            }
                            setStatus('Тестовое сообщение отправлено', false);
                        } catch (e) {
                            setStatus('Сеть: ' + e.message, true);
                        }
                    });
                }
            })();
            </script>
        @else
            <div class="space-y-2 border-t border-zinc-800 pt-4">
                <label for="config_json" class="block text-sm font-medium text-zinc-300">Конфигурация (JSON)</label>
                <textarea name="config_json" id="config_json" rows="14" required
                    class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono text-xs text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">{{ old('config_json', json_encode($configForJsonField, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') }}</textarea>
                <p class="text-xs text-zinc-500">Ключ <code class="text-zinc-400">price_action_dgt</code> задан выше отдельным полем и в этот JSON не входит.</p>
                @error('config_json')
                    <p class="text-sm text-rose-400">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="flex items-center gap-3 border-t border-zinc-800 pt-4">
            <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-400">
                Сохранить
            </button>
            <a href="{{ route('bots.show', $bot) }}" class="text-sm text-zinc-500 hover:text-zinc-300">Отмена</a>
        </div>
    </form>

    {{-- Отдельная форма очистки: не внутри PUT-формы сохранения настроек, иначе submit уйдёт не туда. --}}
    @php
        // Счётчики строк из DataCleanupService::countBotScopedRows() — только для этого бота.
        $cleanupCounts = isset($botCleanupRowCounts) && is_array($botCleanupRowCounts)
            ? $botCleanupRowCounts
            : ['signals' => 0, 'trades' => 0, 'analysis_runs' => 0, 'llm_logs' => 0, 'bot_runs' => 0, 'backtest_runs' => 0, 'backtest_trades' => 0, 'total' => 0];
        $fmtCleanupCount = static fn (int $n): string => number_format($n, 0, ',', ' ');
    @endphp
    <section class="space-y-4 rounded border border-rose-900/50 bg-zinc-950/40 p-6">
        <div>
            <h2 class="text-lg font-medium text-zinc-100">Очистка истории бота</h2>
            <p class="mt-2 text-sm text-zinc-400">
                Удаляет исторические записи <strong class="font-medium text-zinc-300">только этого бота</strong>
                ({{ $bot->name }}, id={{ $bot->id }}), старше выбранного периода. Действие необратимо.
            </p>
        </div>

        <div class="rounded border border-zinc-800 bg-zinc-900/40 px-4 py-3 text-sm text-zinc-400">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="mb-2 font-medium text-zinc-300">Что удаляется</p>
                <p class="text-xs text-zinc-500">
                    Сейчас в БД: <span class="font-medium tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['total']) }}</span> строк
                </p>
            </div>
            <ul class="mt-2 space-y-1.5 text-xs leading-relaxed">
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span><code class="text-zinc-300">bot_signals</code> — сигналы (<code class="text-zinc-400">signal_at</code>)</span>
                    <span class="shrink-0 tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['signals']) }}</span>
                </li>
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span><code class="text-zinc-300">bot_trades</code> — сделки (<code class="text-zinc-400">entry_time</code>)</span>
                    <span class="shrink-0 tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['trades']) }}</span>
                </li>
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span><code class="text-zinc-300">bot_analysis_runs</code> — прогоны анализа LLM (<code class="text-zinc-400">started_at</code>)</span>
                    <span class="shrink-0 tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['analysis_runs']) }}</span>
                </li>
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span><code class="text-zinc-300">bot_llm_logs</code> — сырые LLM-логи (<code class="text-zinc-400">created_at</code>)</span>
                    <span class="shrink-0 tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['llm_logs']) }}</span>
                </li>
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span><code class="text-zinc-300">bot_runs</code> — записи запусков движка (<code class="text-zinc-400">started_at</code>)</span>
                    <span class="shrink-0 tabular-nums text-zinc-300">{{ $fmtCleanupCount((int) $cleanupCounts['bot_runs']) }}</span>
                </li>
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <span>
                        <code class="text-zinc-300">bot_backtest_runs</code> — прогоны бэктеста (<code class="text-zinc-400">created_at</code>)
                        @if ((int) $cleanupCounts['backtest_trades'] > 0)
                            <span class="text-zinc-500">+ <code class="text-zinc-400">bot_backtest_trades</code> каскадом</span>
                        @endif
                    </span>
                    <span class="shrink-0 tabular-nums text-zinc-300">
                        {{ $fmtCleanupCount((int) $cleanupCounts['backtest_runs']) }}
                        @if ((int) $cleanupCounts['backtest_trades'] > 0)
                            <span class="text-zinc-500">/ {{ $fmtCleanupCount((int) $cleanupCounts['backtest_trades']) }}</span>
                        @endif
                    </span>
                </li>
            </ul>
            <p class="mt-3 text-xs text-zinc-500">
                Вариант «0 часов» стирает <strong class="text-zinc-400">всю</strong> историю бота в перечисленных таблицах.
                Остальные варианты оставляют данные за последние N часов от текущего момента.
                Цифры справа — полное количество строк <strong class="text-zinc-400">сейчас</strong>, не только старше выбранного окна.
            </p>
        </div>

        <div class="rounded border border-zinc-800 bg-zinc-900/20 px-4 py-3 text-xs text-zinc-500">
            <p class="font-medium text-zinc-400">Что не удаляется</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                <li>Свечи <code class="text-zinc-400">instrument_candles</code> — общие для всей платформы</li>
                <li>Настройки и конфигурация бота (<code class="text-zinc-400">bots</code>, индикаторы и т.д.)</li>
                <li>Данные других ботов</li>
            </ul>
        </div>

        <form method="post"
              action="{{ route('bots.cleanup-recent', $bot) }}"
              class="flex flex-wrap items-end gap-3"
              onsubmit="const h=this.retention_hours.value; const botName=@json($bot->name); return confirm(h==='0' ? 'Удалить ВСЮ историю бота «' + botName + '» без исключения? Действие необратимо.' : 'Удалить у бота «' + botName + '» все записи старше выбранного периода? Действие необратимо.');">
            @csrf
            <label class="flex flex-col gap-1 text-sm text-zinc-200">
                <span>Удалить данные старше</span>
                <select name="retention_hours"
                        class="min-w-[10rem] rounded border border-zinc-600 bg-zinc-950 px-2 py-2 text-zinc-100 focus:border-transparent focus:ring-2 focus:ring-rose-600">
                    <option value="0">0 часов (всё)</option>
                    <option value="1">1 час</option>
                    <option value="3">3 часа</option>
                    <option value="6">6 часов</option>
                    <option value="12">12 часов</option>
                    <option value="24" selected>24 часа</option>
                    <option value="48">48 часов</option>
                </select>
            </label>
            <button type="submit"
                    class="inline-flex items-center justify-center rounded bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400">
                Очистить историю
            </button>
        </form>
    </section>
</div>
@endsection
