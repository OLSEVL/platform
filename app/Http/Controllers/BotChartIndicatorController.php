<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\IndicatorSetting;
use App\Services\Indicators\PriceActionDgtIndicatorService;
use App\Support\PriceActionDgtSettingsForm;
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

        return view('bots.indicators.order-blocks', ['bot' => $bot]);
    }

    public function editFvg(Bot $bot): View
    {
        $this->ensureBotOwner($bot);

        return view('bots.indicators.fvg', ['bot' => $bot]);
    }

    private function ensureBotOwner(Bot $bot): void
    {
        abort_unless((int) $bot->user_id === (int) auth()->id(), 403);
    }
}
