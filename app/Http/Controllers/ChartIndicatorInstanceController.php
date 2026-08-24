<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\ChartIndicatorInstance;
use App\Services\ChartIndicatorInstanceService;
use App\Support\ChartIndicatorInstanceRegistry;
use App\Support\ClassicIndicatorChartTitle;
use App\Support\EmaSettingsForm;
use App\Support\SmaSettingsForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Несколько экземпляров классических индикаторов на графике одного бота.
 */
class ChartIndicatorInstanceController extends Controller
{
    public function index(Bot $bot, ChartIndicatorInstanceService $instances): JsonResponse
    {
        $this->ensureBotOwner($bot);

        return response()->json([
            'instances' => $instances->listSerialized($bot),
        ]);
    }

    public function store(Request $request, Bot $bot, ChartIndicatorInstanceService $instances): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(ChartIndicatorInstanceRegistry::validationRuleIn())],
        ]);

        $created = $instances->create($bot, (string) $validated['type']);

        return response()->json(['instance' => $created], 201);
    }

    public function updateVisibility(Request $request, Bot $bot, ChartIndicatorInstance $instance, ChartIndicatorInstanceService $instances): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $validated = $request->validate([
            'visible' => ['required', 'boolean'],
        ]);

        $updated = $instances->setVisible($bot, $instance, (bool) $validated['visible']);

        return response()->json(['instance' => $updated]);
    }

    public function destroy(Bot $bot, ChartIndicatorInstance $instance, ChartIndicatorInstanceService $instances): JsonResponse
    {
        $this->ensureBotOwner($bot);
        $instances->delete($bot, $instance);

        return response()->json(['ok' => true]);
    }

    /**
     * «Сделать как у бота»: создать/обновить экземпляры индикаторов по торговому config.
     * Dual DeMarker Fast → два DeMarker с period/зонами из настроек бота.
     */
    public function syncTrading(Bot $bot, ChartIndicatorInstanceService $instances): JsonResponse
    {
        $this->ensureBotOwner($bot);

        return response()->json([
            'instances' => $instances->syncTradingInstances($bot),
        ]);
    }

    public function edit(Bot $bot, ChartIndicatorInstance $instance, ChartIndicatorInstanceService $instances): View
    {
        $this->ensureBotOwner($bot);
        abort_unless(ChartIndicatorInstance::isSupportedType($instance->indicator_type), 404);

        $p = $instances->mergedSettings($bot, $instance);
        $apiKey = ChartIndicatorInstanceRegistry::apiKey($instance->indicator_type);
        $typeLabel = ClassicIndicatorChartTitle::nameForApiKey($apiKey);

        return view('bots.indicators.chart-instance', [
            'bot' => $bot,
            'instance' => $instance,
            'p' => $p,
            'typeLabel' => $typeLabel,
            'helpKey' => $instance->indicator_type,
        ]);
    }

    public function update(Request $request, Bot $bot, ChartIndicatorInstance $instance, ChartIndicatorInstanceService $instances): RedirectResponse
    {
        $this->ensureBotOwner($bot);
        abort_unless(ChartIndicatorInstance::isSupportedType($instance->indicator_type), 404);

        $helpKey = (string) $instance->indicator_type;
        $formClass = ChartIndicatorInstanceRegistry::formClass($helpKey);
        $typeLabel = ClassicIndicatorChartTitle::nameForApiKey(ChartIndicatorInstanceRegistry::apiKey($helpKey));

        if ($request->boolean('use_expert_json')) {
            $request->validate(['expert_settings_json' => ['required', 'string', 'max:65535']]);
            $raw = trim((string) $request->input('expert_settings_json', ''));
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return back()->withInput()->withErrors(['expert_settings_json' => 'Некорректный JSON.']);
            }
            $params = array_replace(
                $instances->mergedSettings($bot, $instance),
                $decoded,
            );
        } elseif (in_array($helpKey, ['sma', 'ema'], true)) {
            $params = match ($helpKey) {
                'sma' => SmaSettingsForm::buildFromRequest($request),
                'ema' => EmaSettingsForm::buildFromRequest($request),
                default => [],
            };
        } else {
            $params = $formClass::buildFromRequest($request);
        }

        $instances->updateSettings($bot, $instance, $params);

        return redirect()
            ->route('bots.chart-instances.edit', [$bot, $instance])
            ->with('status', "Настройки {$typeLabel} (экземпляр #{$instance->id}) сохранены.");
    }

    private function ensureBotOwner(Bot $bot): void
    {
        abort_unless((int) $bot->user_id === (int) auth()->id(), 403);
    }
}
