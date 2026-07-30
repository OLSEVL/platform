<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\IndicatorSetting;
use App\Services\Indicators\AtrIndicatorService;
use App\Services\Indicators\BollingerIndicatorService;
use App\Services\Indicators\EmaIndicatorService;
use App\Services\Indicators\MacdIndicatorService;
use App\Services\Indicators\OrderBlocksIndicatorService;
use App\Support\AtrSettingsForm;
use App\Support\BollingerSettingsForm;
use App\Support\EmaSettingsForm;
use App\Support\MacdSettingsForm;
use App\Support\OrderBlocksSettingsForm;
use App\Support\CciSettingsForm;
use App\Support\StochasticSettingsForm;
use App\Services\Indicators\CciIndicatorService;
use App\Services\Indicators\StochasticIndicatorService;
use App\Services\Indicators\LiquiditySwingsIndicatorService;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Services\Indicators\RsiIndicatorService;
use App\Services\Indicators\SmaIndicatorService;
use App\Services\Indicators\SmcFlowIndicatorService;
use App\Support\LiquiditySwingsSettingsForm;
use App\Support\PriceActionDgtSettingsForm;
use App\Support\RsiSettingsForm;
use App\Support\SmaSettingsForm;
use App\Support\SmcFlowSettingsForm;
use App\Support\VwmaSettingsForm;
use App\Services\Indicators\VwmaIndicatorService;
use App\Support\KeltnerSettingsForm;
use App\Services\Indicators\KeltnerIndicatorService;
use App\Support\EnvelopsSettingsForm;
use App\Services\Indicators\EnvelopsIndicatorService;
use App\Support\ParabolicSarSettingsForm;
use App\Services\Indicators\ParabolicSarIndicatorService;
use App\Support\SuperTrendSettingsForm;
use App\Services\Indicators\SuperTrendIndicatorService;
use App\Support\AdxSettingsForm;
use App\Services\Indicators\AdxIndicatorService;
use App\Support\AlligatorSettingsForm;
use App\Services\Indicators\AlligatorIndicatorService;
use App\Support\AoSettingsForm;
use App\Services\Indicators\AoIndicatorService;
use App\Support\AroonSettingsForm;
use App\Services\Indicators\AroonIndicatorService;
use App\Support\HmaSettingsForm;
use App\Services\Indicators\HmaIndicatorService;
use App\Support\IchimokuSettingsForm;
use App\Services\Indicators\IchimokuIndicatorService;
use App\Support\NrtrSettingsForm;
use App\Services\Indicators\NrtrIndicatorService;
use App\Support\PriceChannelSettingsForm;
use App\Services\Indicators\PriceChannelIndicatorService;
use App\Support\WilliamsRangeSettingsForm;
use App\Services\Indicators\WilliamsRangeIndicatorService;
use App\Support\MomentumSettingsForm;
use App\Services\Indicators\MomentumIndicatorService;
use App\Support\RocSettingsForm;
use App\Services\Indicators\RocIndicatorService;
use App\Support\OsmaSettingsForm;
use App\Services\Indicators\OsmaIndicatorService;
use App\Support\TrixSettingsForm;
use App\Services\Indicators\TrixIndicatorService;
use App\Support\CmoSettingsForm;
use App\Services\Indicators\CmoIndicatorService;
use App\Support\DemarkerSettingsForm;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Support\RviSettingsForm;
use App\Services\Indicators\RviIndicatorService;
use App\Support\UltimateSettingsForm;
use App\Services\Indicators\UltimateIndicatorService;
use App\Support\SmiSettingsForm;
use App\Services\Indicators\SmiIndicatorService;
use App\Support\StdDevSettingsForm;
use App\Services\Indicators\StdDevIndicatorService;
use App\Support\SmaChannelSettingsForm;
use App\Services\Indicators\SmaChannelIndicatorService;
use App\Support\IvashovRangeSettingsForm;
use App\Services\Indicators\IvashovRangeIndicatorService;
use App\Support\LinRegSettingsForm;
use App\Services\Indicators\LinRegIndicatorService;
use App\Support\LinRegChannelSettingsForm;
use App\Services\Indicators\LinRegChannelIndicatorService;
use App\Support\KalmanSettingsForm;
use App\Services\Indicators\KalmanIndicatorService;
use App\Support\FractalSettingsForm;
use App\Services\Indicators\FractalIndicatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Отдельные страницы настроек оверлей-индикаторов графика бота (не смешиваем с общими настройками бота).
 */
class BotChartIndicatorController extends Controller
{
    public function editPriceActionDgt(Bot $bot): View
    {
        $this->ensureBotOwner($bot);
        $pa = IndicatorSetting::mergedPriceActionDgtParams($bot);

        return view('bots.indicators.price-action-dgt', [
            'bot' => $bot,
            'pa' => $pa,
        ]);
    }

    public function updatePriceActionDgt(Request $request, Bot $bot): RedirectResponse
    {
        $this->ensureBotOwner($bot);
        if ($request->boolean('use_expert_json')) {
            $request->validate([
                'expert_settings_json' => ['required', 'string', 'max:65535'],
            ]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace(PriceActionDgtIndicatorService::defaultParams(), $decoded);
        } else {
            $params = PriceActionDgtSettingsForm::buildFromRequest($request);
        }
        IndicatorSetting::updateOrCreate(
            ['bot_id' => $bot->id, 'indicator_key' => IndicatorSetting::KEY_PRICE_ACTION_DGT],
            ['settings' => $params]
        );
        $config = is_array($bot->config) ? $bot->config : [];
        unset($config[IndicatorSetting::KEY_PRICE_ACTION_DGT]);
        $bot->update(['config' => $config]);

        return redirect()->route('bots.indicators.price-action-dgt.edit', $bot)
            ->with('status', 'Настройки Price Action (DGT) сохранены.');
    }

    public function editOrderBlocks(Bot $bot): View
    {
        $this->ensureBotOwner($bot);
        $ob = IndicatorSetting::mergedOrderBlocksParams($bot);

        return view('bots.indicators.order-blocks', [
            'bot' => $bot,
            'ob' => $ob,
        ]);
    }

    public function updateOrderBlocks(Request $request, Bot $bot): RedirectResponse
    {
        $this->ensureBotOwner($bot);
        if ($request->boolean('use_expert_json')) {
            $request->validate([
                'expert_settings_json' => ['required', 'string', 'max:65535'],
            ]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace(OrderBlocksIndicatorService::defaultParams(), $decoded);
        } else {
            $params = OrderBlocksSettingsForm::buildFromRequest($request);
        }
        IndicatorSetting::updateOrCreate(
            ['bot_id' => $bot->id, 'indicator_key' => IndicatorSetting::KEY_ORDER_BLOCKS],
            ['settings' => $params]
        );
        $config = is_array($bot->config) ? $bot->config : [];
        unset($config[IndicatorSetting::KEY_ORDER_BLOCKS]);
        $bot->update(['config' => $config]);

        return redirect()->route('bots.indicators.order-blocks.edit', $bot)
            ->with('status', 'Настройки ордерблоков сохранены.');
    }

    public function editFvg(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.fvg', ['bot' => $bot]);
    }

    public function editLiquiditySwings(Bot $bot): View
    {
        $this->ensureBotOwner($bot);
        $ls = IndicatorSetting::mergedLiquiditySwingsParams($bot);

        return view('bots.indicators.liquidity-swings', [
            'bot' => $bot,
            'ls' => $ls,
        ]);
    }

    public function updateLiquiditySwings(Request $request, Bot $bot): RedirectResponse
    {
        $this->ensureBotOwner($bot);
        if ($request->boolean('use_expert_json')) {
            $request->validate([
                'expert_settings_json' => ['required', 'string', 'max:65535'],
            ]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace(LiquiditySwingsIndicatorService::defaultParams(), $decoded);
        } else {
            $params = LiquiditySwingsSettingsForm::buildFromRequest($request);
        }
        IndicatorSetting::updateOrCreate(
            ['bot_id' => $bot->id, 'indicator_key' => IndicatorSetting::KEY_LIQUIDITY_SWINGS],
            ['settings' => $params]
        );
        $config = is_array($bot->config) ? $bot->config : [];
        unset($config[IndicatorSetting::KEY_LIQUIDITY_SWINGS]);
        $bot->update(['config' => $config]);

        return redirect()->route('bots.indicators.liquidity-swings.edit', $bot)
            ->with('status', 'Настройки Liquidity Swings сохранены.');
    }

    public function editSmcFlow(Bot $bot): View
    {
        $this->ensureBotOwner($bot);
        $smc = IndicatorSetting::mergedSmcFlowParams($bot);

        return view('bots.indicators.smc-flow', [
            'bot' => $bot,
            'smc' => $smc,
        ]);
    }

    public function updateSmcFlow(Request $request, Bot $bot): RedirectResponse
    {
        $this->ensureBotOwner($bot);
        if ($request->boolean('use_expert_json')) {
            $request->validate([
                'expert_settings_json' => ['required', 'string', 'max:65535'],
            ]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace(SmcFlowIndicatorService::defaultParams(), $decoded);
        } else {
            $params = SmcFlowSettingsForm::buildFromRequest($request);
        }
        IndicatorSetting::updateOrCreate(
            ['bot_id' => $bot->id, 'indicator_key' => IndicatorSetting::KEY_SMC_FLOW],
            ['settings' => $params]
        );
        $config = is_array($bot->config) ? $bot->config : [];
        unset($config[IndicatorSetting::KEY_SMC_FLOW]);
        $bot->update(['config' => $config]);

        return redirect()->route('bots.indicators.smc-flow.edit', $bot)
            ->with('status', 'Настройки SMC Flow сохранены.');
    }

    public function editBollinger(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.bollinger', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedBollingerParams($bot),
        ]);
    }

    public function updateBollinger(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_BOLLINGER, BollingerIndicatorService::class, BollingerSettingsForm::class, 'bots.indicators.bollinger.edit', 'Bollinger');
    }

    public function editRsi(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.rsi', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedRsiParams($bot),
        ]);
    }

    public function updateRsi(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_RSI, RsiIndicatorService::class, RsiSettingsForm::class, 'bots.indicators.rsi.edit', 'RSI');
    }

    public function editSma(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.sma', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedSmaParams($bot),
        ]);
    }

    public function updateSma(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_SMA, SmaIndicatorService::class, SmaSettingsForm::class, 'bots.indicators.sma.edit', 'SMA');
    }

    public function editEma(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.ema', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedEmaParams($bot),
        ]);
    }

    public function updateEma(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_EMA, EmaIndicatorService::class, EmaSettingsForm::class, 'bots.indicators.ema.edit', 'EMA');
    }

    public function editAtr(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.atr', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedAtrParams($bot),
        ]);
    }

    public function updateAtr(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ATR, AtrIndicatorService::class, AtrSettingsForm::class, 'bots.indicators.atr.edit', 'ATR');
    }

    public function editMacd(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.macd', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedMacdParams($bot),
        ]);
    }

    public function updateMacd(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_MACD, MacdIndicatorService::class, MacdSettingsForm::class, 'bots.indicators.macd.edit', 'MACD');
    }

    public function editStochastic(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.stochastic', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedStochasticParams($bot),
        ]);
    }

    public function updateStochastic(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_STOCHASTIC, StochasticIndicatorService::class, StochasticSettingsForm::class, 'bots.indicators.stochastic.edit', 'Stochastic');
    }

    public function editCci(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.cci', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedCciParams($bot),
        ]);
    }

    public function updateCci(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_CCI, CciIndicatorService::class, CciSettingsForm::class, 'bots.indicators.cci.edit', 'CCI');
    }

    public function editVwma(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.vwma', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedVwmaParams($bot),
        ]);
    }

    public function updateVwma(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_VWMA, VwmaIndicatorService::class, VwmaSettingsForm::class, 'bots.indicators.vwma.edit', 'VWMA');
    }

    public function editKeltner(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.keltner', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedKeltnerParams($bot),
        ]);
    }

    public function updateKeltner(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_KELTNER, KeltnerIndicatorService::class, KeltnerSettingsForm::class, 'bots.indicators.keltner.edit', 'Keltner');
    }

    public function editEnvelops(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.envelops', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedEnvelopsParams($bot),
        ]);
    }

    public function updateEnvelops(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ENVELOPS, EnvelopsIndicatorService::class, EnvelopsSettingsForm::class, 'bots.indicators.envelops.edit', 'Envelops');
    }

    public function editParabolicSar(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.parabolic_sar', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedParabolicSarParams($bot),
        ]);
    }

    public function updateParabolicSar(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_PARABOLIC_SAR, ParabolicSarIndicatorService::class, ParabolicSarSettingsForm::class, 'bots.indicators.parabolic-sar.edit', 'Parabolic SAR');
    }

    public function editSuperTrend(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.supertrend', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedSuperTrendParams($bot),
        ]);
    }

    public function updateSuperTrend(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_SUPERTREND, SuperTrendIndicatorService::class, SuperTrendSettingsForm::class, 'bots.indicators.supertrend.edit', 'SuperTrend');
    }

    public function editAdx(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.adx', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedAdxParams($bot),
        ]);
    }

    public function updateAdx(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ADX, AdxIndicatorService::class, AdxSettingsForm::class, 'bots.indicators.adx.edit', 'ADX');
    }

    public function editWilliams(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.williams', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedWilliamsParams($bot),
        ]);
    }

    public function updateWilliams(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_WILLIAMS, WilliamsRangeIndicatorService::class, WilliamsRangeSettingsForm::class, 'bots.indicators.williams.edit', 'Williams %R');
    }

    public function editAo(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.ao', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedAoParams($bot),
        ]);
    }

    public function updateAo(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_AO, AoIndicatorService::class, AoSettingsForm::class, 'bots.indicators.ao.edit', 'AO');
    }

    public function editAlligator(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.alligator', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedAlligatorParams($bot),
        ]);
    }

    public function updateAlligator(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ALLIGATOR, AlligatorIndicatorService::class, AlligatorSettingsForm::class, 'bots.indicators.alligator.edit', 'Alligator');
    }

    public function editIchimoku(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.ichimoku', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedIchimokuParams($bot),
        ]);
    }

    public function updateIchimoku(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ICHIMOKU, IchimokuIndicatorService::class, IchimokuSettingsForm::class, 'bots.indicators.ichimoku.edit', 'Ichimoku');
    }

    public function editPriceChannel(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.price-channel', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedPriceChannelParams($bot),
        ]);
    }

    public function updatePriceChannel(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_PRICE_CHANNEL, PriceChannelIndicatorService::class, PriceChannelSettingsForm::class, 'bots.indicators.price-channel.edit', 'Price Channel');
    }

    public function editNrtr(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.nrtr', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedNrtrParams($bot),
        ]);
    }

    public function updateNrtr(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_NRTR, NrtrIndicatorService::class, NrtrSettingsForm::class, 'bots.indicators.nrtr.edit', 'NRTR');
    }

    public function editHma(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.hma', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedHmaParams($bot),
        ]);
    }

    public function updateHma(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_HMA, HmaIndicatorService::class, HmaSettingsForm::class, 'bots.indicators.hma.edit', 'HMA');
    }

    public function editAroon(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.aroon', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedAroonParams($bot),
        ]);
    }

    public function updateAroon(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_AROON, AroonIndicatorService::class, AroonSettingsForm::class, 'bots.indicators.aroon.edit', 'Aroon');
    }

    public function editMomentum(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.momentum', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedMomentumParams($bot),
        ]);
    }

    public function updateMomentum(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_MOMENTUM, MomentumIndicatorService::class, MomentumSettingsForm::class, 'bots.indicators.momentum.edit', 'Momentum');
    }

    public function editRoc(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.roc', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedRocParams($bot),
        ]);
    }

    public function updateRoc(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ROC, RocIndicatorService::class, RocSettingsForm::class, 'bots.indicators.roc.edit', 'ROC');
    }

    public function editOsma(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.osma', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedOsmaParams($bot),
        ]);
    }

    public function updateOsma(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_OSMA, OsmaIndicatorService::class, OsmaSettingsForm::class, 'bots.indicators.osma.edit', 'OsMa');
    }

    public function editTrix(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.trix', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedTrixParams($bot),
        ]);
    }

    public function updateTrix(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_TRIX, TrixIndicatorService::class, TrixSettingsForm::class, 'bots.indicators.trix.edit', 'Trix');
    }

    public function editCmo(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.cmo', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedCmoParams($bot),
        ]);
    }

    public function updateCmo(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_CMO, CmoIndicatorService::class, CmoSettingsForm::class, 'bots.indicators.cmo.edit', 'CMO');
    }

    public function editDemarker(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.demarker', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedDemarkerParams($bot),
        ]);
    }

    public function updateDemarker(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_DEMARKER, DemarkerIndicatorService::class, DemarkerSettingsForm::class, 'bots.indicators.demarker.edit', 'DeMarker');
    }

    public function editRvi(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.rvi', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedRviParams($bot),
        ]);
    }

    public function updateRvi(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_RVI, RviIndicatorService::class, RviSettingsForm::class, 'bots.indicators.rvi.edit', 'RVI');
    }

    public function editUltimate(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.ultimate', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedUltimateParams($bot),
        ]);
    }

    public function updateUltimate(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_ULTIMATE, UltimateIndicatorService::class, UltimateSettingsForm::class, 'bots.indicators.ultimate.edit', 'Ultimate Oscillator');
    }

    public function editSmi(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.smi', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedSmiParams($bot),
        ]);
    }

    public function updateSmi(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_SMI, SmiIndicatorService::class, SmiSettingsForm::class, 'bots.indicators.smi.edit', 'SMI');
    }

    public function editStdDev(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.stddev', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedStdDevParams($bot),
        ]);
    }

    public function updateStdDev(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_STDDEV, StdDevIndicatorService::class, StdDevSettingsForm::class, 'bots.indicators.stddev.edit', 'StdDev');
    }

    public function editSmaChannel(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.sma-channel', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedSmaChannelParams($bot),
        ]);
    }

    public function updateSmaChannel(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_SMA_CHANNEL, SmaChannelIndicatorService::class, SmaChannelSettingsForm::class, 'bots.indicators.sma-channel.edit', 'SMA Channel');
    }

    public function editIvashov(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.ivashov', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedIvashovParams($bot),
        ]);
    }

    public function updateIvashov(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_IVASHOV, IvashovRangeIndicatorService::class, IvashovRangeSettingsForm::class, 'bots.indicators.ivashov.edit', 'Ivashov Range');
    }

    public function editLinReg(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.linreg', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedLinRegParams($bot),
        ]);
    }

    public function updateLinReg(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_LINREG, LinRegIndicatorService::class, LinRegSettingsForm::class, 'bots.indicators.linreg.edit', 'Linear Regression');
    }

    public function editLinRegChannel(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.linreg-channel', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedLinRegChannelParams($bot),
        ]);
    }

    public function updateLinRegChannel(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_LINREG_CHANNEL, LinRegChannelIndicatorService::class, LinRegChannelSettingsForm::class, 'bots.indicators.linreg-channel.edit', 'LR Channel');
    }

    public function editKalman(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.kalman', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedKalmanParams($bot),
        ]);
    }

    public function updateKalman(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_KALMAN, KalmanIndicatorService::class, KalmanSettingsForm::class, 'bots.indicators.kalman.edit', 'Kalman');
    }

    public function editFractal(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.fractal', [
            'bot' => $bot,
            'p' => IndicatorSetting::mergedFractalParams($bot),
        ]);
    }

    public function updateFractal(Request $request, Bot $bot): RedirectResponse
    {
        return $this->saveClassic($request, $bot, IndicatorSetting::KEY_FRACTAL, FractalIndicatorService::class, FractalSettingsForm::class, 'bots.indicators.fractal.edit', 'Fractal');
    }

    /**
     * Общее сохранение классического индикатора (форма или expert JSON).
     *
     * @param  class-string  $serviceClass
     * @param  class-string  $formClass
     */
    private function saveClassic(
        Request $request,
        Bot $bot,
        string $key,
        string $serviceClass,
        string $formClass,
        string $redirectRoute,
        string $label,
    ): RedirectResponse {
        $this->ensureBotOwner($bot);
        if ($request->boolean('use_expert_json')) {
            $request->validate([
                'expert_settings_json' => ['required', 'string', 'max:65535'],
            ]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace($serviceClass::defaultParams(), $decoded);
        } else {
            $params = $formClass::buildFromRequest($request);
        }
        IndicatorSetting::updateOrCreate(
            ['bot_id' => $bot->id, 'indicator_key' => $key],
            ['settings' => $params]
        );
        $config = is_array($bot->config) ? $bot->config : [];
        unset($config[$key]);
        $bot->update(['config' => $config]);

        return redirect()->route($redirectRoute, $bot)
            ->with('status', "Настройки {$label} сохранены.");
    }

    private function ensureBotOwner(Bot $bot): void
    {
        abort_unless((int) $bot->user_id === (int) auth()->id(), 403);
    }
}
