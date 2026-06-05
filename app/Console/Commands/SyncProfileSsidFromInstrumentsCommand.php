<?php

namespace App\Console\Commands;

use App\Models\PocketInstrument;
use App\Models\User;
use App\Models\UserTradingSetting;
use App\Support\PocketSsidNormalizer;
use Illuminate\Console\Command;

class SyncProfileSsidFromInstrumentsCommand extends Command
{
    protected $signature = 'profile:sync-ssid-from-instruments {--user_id=}';

    protected $description = 'ВНИМАНИЕ: копирует session_token ingest в профиль — часто другой кабинет PO. Только для отладки.';

    public function handle(): int
    {
        if (! $this->confirm('Токен из «Инструментов» обычно НЕ тот кабинет, что в профиле. Продолжить?', false)) {
            $this->warn('Отменено. Вставьте SSID вручную в Профиль → Торговля.');

            return self::SUCCESS;
        }

        $userId = $this->option('user_id') ? (int) $this->option('user_id') : User::query()->orderBy('id')->value('id');
        if (! $userId) {
            $this->error('Нет пользователей');

            return self::FAILURE;
        }

        $instrument = PocketInstrument::query()
            ->where('is_active', true)
            ->whereNotNull('session_token')
            ->where('session_token', '!=', '')
            ->orderByDesc('priority')
            ->first();

        if (! $instrument) {
            $this->error('Нет активного инструмента с session_token');

            return self::FAILURE;
        }

        $ssid = PocketSsidNormalizer::buildAuthString(
            trim((string) $instrument->session_token),
            (int) ($instrument->uid ?? 0),
            (bool) $instrument->is_demo
        );

        $settings = UserTradingSetting::query()->firstOrCreate(
            ['user_id' => $userId],
            ['order_amount' => 1, 'mirror_trades_enabled' => false, 'is_demo' => true]
        );
        $settings->pocket_ssid = $ssid;
        $settings->is_demo = (bool) $instrument->is_demo;
        $settings->save();

        $this->info("user_id={$userId}: SSID обновлён из инструмента {$instrument->instrument}");

        return self::SUCCESS;
    }
}
