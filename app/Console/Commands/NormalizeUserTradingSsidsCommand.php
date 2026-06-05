<?php

namespace App\Console\Commands;

use App\Models\UserTradingSetting;
use App\Support\PocketSsidNormalizer;
use Illuminate\Console\Command;

class NormalizeUserTradingSsidsCommand extends Command
{
    protected $signature = 'profile:normalize-ssids';

    protected $description = 'Нормализовать сохранённые SSID в формат 42["auth",...]';

    public function handle(): int
    {
        $updated = 0;
        foreach (UserTradingSetting::query()->whereNotNull('pocket_ssid')->cursor() as $settings) {
            $normalized = PocketSsidNormalizer::normalize(
                (string) $settings->pocket_ssid,
                (bool) $settings->is_demo
            );
            if ($normalized === null) {
                $this->warn("user_id={$settings->user_id}: не удалось нормализовать SSID");

                continue;
            }
            if ($normalized !== $settings->pocket_ssid || ! PocketSsidNormalizer::isValidPocketAuthString((string) $settings->pocket_ssid)) {
                $settings->pocket_ssid = $normalized;
                $settings->save();
                $updated++;
                $this->info("user_id={$settings->user_id}: SSID исправлен (session + isDemo)");
            }
        }

        $this->info("Готово. Обновлено записей: {$updated}");

        return self::SUCCESS;
    }
}
