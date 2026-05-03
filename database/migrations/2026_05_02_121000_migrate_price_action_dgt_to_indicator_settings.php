<?php

use App\Models\Bot;
use App\Models\IndicatorSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Однократно переносим price_action_dgt из JSON config бота в indicator_settings,
     * если для бота ещё нет строки (избегаем перезаписи уже сохранённых настроек).
     */
    public function up(): void
    {
        Bot::query()->orderBy('id')->chunk(100, function ($bots): void {
            foreach ($bots as $bot) {
                if (! $bot instanceof Bot) {
                    continue;
                }
                $cfg = is_array($bot->config) ? $bot->config : [];
                $legacy = $cfg[IndicatorSetting::KEY_PRICE_ACTION_DGT] ?? null;
                if (! is_array($legacy) || $legacy === []) {
                    continue;
                }
                $exists = IndicatorSetting::query()
                    ->where('bot_id', $bot->id)
                    ->where('indicator_key', IndicatorSetting::KEY_PRICE_ACTION_DGT)
                    ->exists();
                if ($exists) {
                    continue;
                }
                IndicatorSetting::query()->create([
                    'bot_id' => $bot->id,
                    'indicator_key' => IndicatorSetting::KEY_PRICE_ACTION_DGT,
                    'settings' => $legacy,
                ]);
                unset($cfg[IndicatorSetting::KEY_PRICE_ACTION_DGT]);
                $bot->config = $cfg;
                $bot->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Обратный перенос не выполняем: источник правды остаётся в indicator_settings.
    }
};
