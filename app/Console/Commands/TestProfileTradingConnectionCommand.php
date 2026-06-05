<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserTradingSetting;
use App\Services\PocketOptionsProxyClient;
use App\Support\PocketMirrorProxy;
use App\Support\PocketSsidNormalizer;
use Illuminate\Console\Command;

class TestProfileTradingConnectionCommand extends Command
{
    protected $signature = 'profile:test-po-connection {--user_id=}';

    protected $description = 'Проверить SSID профиля: формат и auth на PO через proxy (без ордера)';

    public function handle(PocketOptionsProxyClient $proxy): int
    {
        $userId = $this->option('user_id') ? (int) $this->option('user_id') : User::query()->orderBy('id')->value('id');
        $settings = UserTradingSetting::query()->where('user_id', $userId)->first();
        if (! $settings) {
            $this->error('Нет user_trading_settings');

            return self::FAILURE;
        }

        $ssid = PocketSsidNormalizer::normalize((string) $settings->pocket_ssid, (bool) $settings->is_demo);
        if ($ssid === null || ! PocketSsidNormalizer::isValidPocketAuthString($ssid)) {
            $this->error('SSID неверного формата. Сохраните auth из DevTools (ключ session, не sessionToken).');

            return self::FAILURE;
        }

        $this->line('SSID format: OK');
        $this->line(substr($ssid, 0, 80).'...');

        $proxyUrl = PocketMirrorProxy::resolveForInstrument('EURUSD_otc');
        $this->line('Proxy: '.($proxyUrl ?: 'нет (прямое подключение)'));

        try {
            $result = $proxy->testAuth($ssid, (bool) $settings->is_demo, $proxyUrl);
        } catch (\Throwable $e) {
            $this->error('PO auth failed: '.$e->getMessage());
            $this->warn('Проверьте platform-pocket-proxy и обновите SSID в профиле.');

            return self::FAILURE;
        }
        if ($result['ok'] ?? false) {
            $this->info('PO auth: OK (SSID принят, WebSocket подключён)');

            return self::SUCCESS;
        }

        $this->error('PO auth failed: '.($result['error'] ?? 'unknown'));
        $this->warn('Обновите SSID в Профиль → Торговля из браузера того кабинета, где смотрите сделки.');

        return self::FAILURE;
    }
}
