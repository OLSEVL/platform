<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\ChartIndicatorInstance;
use App\Services\Indicators\DemarkerIndicatorService;
use App\Support\BotTradingChartLayers;
use App\Support\ChartIndicatorInstanceRegistry;
use App\Support\ClassicIndicatorChartTitle;
use App\Support\DemarkerDualFastConfig;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * CRUD и расчёт экземпляров классических индикаторов на графике бота.
 */
class ChartIndicatorInstanceService
{
    /** Длины по умолчанию для новых экземпляров (чередуются). */
    private const DEFAULT_LENGTHS = [9, 21, 50, 100, 200];

    /** Цвета для новых линий — чтобы несколько экземпляров одного типа отличались. */
    private const COLOR_PALETTE = [
        '#f59e0b',
        '#22c55e',
        '#a855f7',
        '#ec4899',
        '#06b6d4',
        '#eab308',
    ];

    /**
     * @return Collection<int, ChartIndicatorInstance>
     */
    public function listForBot(Bot $bot, ?string $type = null): Collection
    {
        $query = ChartIndicatorInstance::query()
            ->where('bot_id', $bot->id)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($type !== null) {
            $query->where('indicator_type', $type);
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Bot $bot, string $type): array
    {
        if (! ChartIndicatorInstanceRegistry::isSupported($type)) {
            throw ValidationException::withMessages([
                'type' => 'Этот тип индикатора не поддерживает несколько экземпляров.',
            ]);
        }

        $existing = $this->listForBot($bot, $type);
        $defaults = $this->defaultParamsForType($type);
        $entry = ChartIndicatorInstanceRegistry::entry($type);
        $index = $existing->count();

        // Чередуем period/length и основной цвет — чтобы новые экземпляры отличались на графике.
        $varyKey = $entry['vary_key'] ?? null;
        if (is_string($varyKey) && $varyKey !== '') {
            $defaults[$varyKey] = self::DEFAULT_LENGTHS[$index % count(self::DEFAULT_LENGTHS)];
        }
        $colorKey = (string) ($entry['color_key'] ?? 'color');
        $defaults[$colorKey] = self::COLOR_PALETTE[$index % count(self::COLOR_PALETTE)];

        $maxSort = (int) ChartIndicatorInstance::query()
            ->where('bot_id', $bot->id)
            ->max('sort_order');

        $instance = ChartIndicatorInstance::query()->create([
            'bot_id' => $bot->id,
            'indicator_type' => $type,
            'settings' => $defaults,
            'visible' => true,
            'sort_order' => $maxSort + 1,
        ]);

        return $this->serializeInstance($instance, $bot);
    }

    public function delete(Bot $bot, ChartIndicatorInstance $instance): void
    {
        $this->assertInstanceBelongsToBot($bot, $instance);
        $instance->delete();
    }

    /**
     * Нужна ли синхронизация экземпляров при «Сделать как у бота» (не legacy-чекбоксы).
     */
    public static function needsTradingSync(Bot $bot): bool
    {
        return BotTradingChartLayers::usesChartIndicatorInstances($bot);
    }

    /**
     * Приводит chart_indicator_instances к торговой конфигурации бота.
     *
     * @return list<array<string, mixed>>
     */
    public function syncTradingInstances(Bot $bot): array
    {
        $slots = $this->resolveTradingInstanceSlots($bot);
        if ($slots === null) {
            return $this->listSerialized($bot);
        }

        $types = array_values(array_unique(array_column($slots, 'type')));
        ChartIndicatorInstance::query()
            ->where('bot_id', $bot->id)
            ->whereIn('indicator_type', $types)
            ->delete();

        foreach ($slots as $index => $slot) {
            ChartIndicatorInstance::query()->create([
                'bot_id' => $bot->id,
                'indicator_type' => $slot['type'],
                'settings' => $slot['settings'],
                'visible' => true,
                'sort_order' => $index,
            ]);
        }

        return $this->listSerialized($bot);
    }

    /**
     * Слоты индикаторов для «Сделать как у бота» — period/зоны из config стратегии.
     *
     * @return list<array{type: string, settings: array<string, mixed>}>|null
     */
    private function resolveTradingInstanceSlots(Bot $bot): ?array
    {
        $type = (string) $bot->type;
        $cfg = is_array($bot->config) ? $bot->config : [];

        return match ($type) {
            DemarkerDualFastConfig::BOT_TYPE => $this->demarkerDualFastSlots($bot, $cfg),
            'strong_trend_demarker' => [
                $this->tradingSlot($bot, 'demarker', 'demarker', '#f59e0b'),
            ],
            'rsi_contrtrend' => [
                $this->tradingSlot($bot, 'rsi', 'rsi', '#1e90ff'),
                $this->tradingSlot($bot, 'sma', 'sma', '#f59e0b'),
            ],
            'rsi_trade' => [
                $this->tradingSlot($bot, 'rsi', 'rsi', '#1e90ff'),
            ],
            'sma_stochastic' => [
                $this->tradingSlot($bot, 'sma', 'sma', '#f59e0b'),
                $this->tradingSlot($bot, 'stochastic', 'stochastic', '#22c55e'),
            ],
            'momentum_macd' => [
                $this->tradingSlot($bot, 'macd', 'macd', '#1e90ff'),
                $this->tradingSlot($bot, 'momentum', 'momentum', '#f59e0b'),
            ],
            'cci_trade' => [
                $this->tradingSlot($bot, 'cci', 'cci', '#5f9ea0'),
            ],
            'williams_range_trade' => [
                $this->tradingSlot($bot, 'williams', 'williams', '#1e90ff'),
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return list<array{type: string, settings: array<string, mixed>}>
     */
    private function demarkerDualFastSlots(Bot $bot, array $cfg): array
    {
        $slots = [$this->demarkerParamsFromBotConfig($cfg, primary: true)];
        if ((bool) ($cfg['second_demarker_enabled'] ?? true)) {
            $slots[] = $this->demarkerParamsFromBotConfig($cfg, primary: false);
        }
        $colors = ['#f59e0b', '#22c55e'];
        $out = [];
        foreach ($slots as $index => $settings) {
            $settings['color'] = $colors[$index % count($colors)];
            $out[] = ['type' => 'demarker', 'settings' => $settings];
        }

        return $out;
    }

    /**
     * @return array{type: string, settings: array<string, mixed>}
     */
    private function tradingSlot(Bot $bot, string $helpKey, string $layerKey, string $color): array
    {
        $settings = BotTradingChartLayers::overlayParams(
            $bot,
            $layerKey,
            ChartIndicatorInstanceRegistry::defaultParams($helpKey),
        );
        $entry = ChartIndicatorInstanceRegistry::entry($helpKey);
        $colorKey = (string) ($entry['color_key'] ?? 'color');
        $settings[$colorKey] = $color;

        return ['type' => $helpKey, 'settings' => $settings];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function demarkerParamsFromBotConfig(array $cfg, bool $primary): array
    {
        $defaults = DemarkerIndicatorService::defaultParams();
        if ($primary) {
            $merged = BotTradingChartLayers::overlayParams(
                $this->botStubForOverlay($cfg),
                'demarker',
                $defaults,
            );

            return array_replace($defaults, $merged);
        }

        return array_replace($defaults, [
            'period' => max(1, min(500, (int) ($cfg['second_demarker_period'] ?? 5))),
            'level_oversold' => max(0.0, min(1.0, (float) ($cfg['second_demarker_oversold'] ?? 5) / 100)),
            'level_overbought' => max(0.0, min(1.0, (float) ($cfg['second_demarker_overbought'] ?? 95) / 100)),
            'show_levels' => true,
            'show_line' => true,
        ]);
    }

    /**
     * overlayParams читает type+config с модели Bot — минимальная заглушка для sync Dual DeMarker.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function botStubForOverlay(array $cfg): Bot
    {
        $bot = new Bot;
        $bot->type = DemarkerDualFastConfig::BOT_TYPE;
        $bot->config = $cfg;

        return $bot;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateSettings(Bot $bot, ChartIndicatorInstance $instance, array $settings): array
    {
        $this->assertInstanceBelongsToBot($bot, $instance);
        $merged = array_replace(
            $this->defaultParamsForType($instance->indicator_type),
            is_array($instance->settings) ? $instance->settings : [],
            $settings,
        );
        $instance->update(['settings' => $merged]);

        return $this->serializeInstance($instance->fresh(), $bot);
    }

    public function setVisible(Bot $bot, ChartIndicatorInstance $instance, bool $visible): array
    {
        $this->assertInstanceBelongsToBot($bot, $instance);
        $instance->update(['visible' => $visible]);

        return $this->serializeInstance($instance->fresh(), $bot);
    }

    /**
     * @param  list<int>  $instanceIds
     * @param  list<array{time:int,open:float,high:float,low:float,close:float,volume?:float}>  $rows
     * @return list<array<string, mixed>>
     */
    public function computeForChart(Bot $bot, array $instanceIds, array $rows): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $instances = ChartIndicatorInstance::query()
            ->where('bot_id', $bot->id)
            ->whereIn('id', $instanceIds)
            ->where('visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($instances as $instance) {
            $helpKey = (string) $instance->indicator_type;
            if (! ChartIndicatorInstanceRegistry::isSupported($helpKey)) {
                continue;
            }
            $entry = ChartIndicatorInstanceRegistry::entry($helpKey);
            $apiKey = ChartIndicatorInstanceRegistry::apiKey($helpKey);
            $params = is_array($instance->settings) ? $instance->settings : [];
            // Экземпляры хранят полный набор params; overlayParams — только для legacy-чекбокса.
            $computed = $this->computeType($helpKey, $rows, $params);
            $title = ClassicIndicatorChartTitle::format(
                ClassicIndicatorChartTitle::nameForApiKey($apiKey),
                $params,
                $entry['title_keys'],
            );
            $out[] = [
                'id' => $instance->id,
                'type' => $helpKey,
                'api_key' => $apiKey,
                'placement' => $entry['placement'],
                'render' => $entry['render'],
                'series_key' => $entry['series_key'] ?? null,
                'title' => $title,
                'params' => $params,
                'series' => $computed['series'] ?? [],
                'style' => $computed['style'] ?? [],
                'levels' => $computed['levels'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSerialized(Bot $bot): array
    {
        return $this->listForBot($bot)
            ->map(fn (ChartIndicatorInstance $i) => $this->serializeInstance($i, $bot))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeInstance(ChartIndicatorInstance $instance, Bot $bot): array
    {
        $helpKey = (string) $instance->indicator_type;
        $entry = ChartIndicatorInstanceRegistry::entry($helpKey);
        $apiKey = ChartIndicatorInstanceRegistry::apiKey($helpKey);
        $params = is_array($instance->settings) ? $instance->settings : [];
        $title = ClassicIndicatorChartTitle::format(
            ClassicIndicatorChartTitle::nameForApiKey($apiKey),
            $params,
            $entry['title_keys'],
        );

        return [
            'id' => $instance->id,
            'type' => $helpKey,
            'title' => $title,
            'visible' => (bool) $instance->visible,
            'sort_order' => (int) $instance->sort_order,
            'settings' => $params,
            'settings_url' => route('bots.chart-instances.edit', [$bot, $instance]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedSettings(Bot $bot, ChartIndicatorInstance $instance): array
    {
        $this->assertInstanceBelongsToBot($bot, $instance);

        return array_replace(
            $this->defaultParamsForType($instance->indicator_type),
            is_array($instance->settings) ? $instance->settings : [],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{series: array<string, mixed>, style: array<string, mixed>, levels?: array<string, mixed>}
     */
    private function computeType(string $helpKey, array $rows, array $params): array
    {
        $serviceClass = ChartIndicatorInstanceRegistry::serviceClass($helpKey);

        return app($serviceClass)->compute($rows, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultParamsForType(string $helpKey): array
    {
        return ChartIndicatorInstanceRegistry::defaultParams($helpKey);
    }

    private function assertInstanceBelongsToBot(Bot $bot, ChartIndicatorInstance $instance): void
    {
        abort_unless((int) $instance->bot_id === (int) $bot->id, 404);
    }
}
