<?php

namespace App\Http\Controllers;

use App\Models\PocketInstrument;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index');
    }

    public function refreshProxies()
    {
        $apiKey = (string) config('services.webshare.api_key', '');
        if ($apiKey === '') {
            return back()->with('error', 'WEBSHARE_API_KEY не задан в .env');
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => "Token {$apiKey}",
                ])
                ->get('https://proxy.webshare.io/api/v2/proxy/list/', [
                    'mode' => 'direct',
                    'page' => 1,
                    'page_size' => 1000,
                ]);

            $response->throw();
        } catch (RequestException $e) {
            return back()->with('error', 'Ошибка запроса к Webshare API: '.$e->getMessage());
        }

        $rows = $response->json('results');
        if (!is_array($rows) || count($rows) === 0) {
            return back()->with('error', 'Webshare вернул пустой список прокси.');
        }

        $proxyPool = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $username = $row['username'] ?? null;
            $password = $row['password'] ?? null;
            $address = $row['proxy_address'] ?? null;
            $port = $row['port'] ?? null;
            $isValid = $row['valid'] ?? true;

            if (!$isValid || !$username || !$password || !$address || !$port) {
                continue;
            }

            $proxyPool[] = sprintf(
                'http://%s:%s@%s:%s',
                rawurlencode((string) $username),
                rawurlencode((string) $password),
                (string) $address,
                (string) $port,
            );
        }

        if (count($proxyPool) === 0) {
            return back()->with('error', 'Нет валидных прокси для назначения.');
        }

        $activeInstruments = PocketInstrument::query()
            ->active()
            ->orderByPriority()
            ->get(['id']);

        if ($activeInstruments->isEmpty()) {
            return back()->with('error', 'Нет активных инструментов.');
        }

        $proxyCount = count($proxyPool);
        DB::transaction(function () use ($activeInstruments, $proxyPool, $proxyCount) {
            foreach ($activeInstruments as $index => $instrument) {
                $instrument->update([
                    'proxy_url' => $proxyPool[$index % $proxyCount],
                ]);
            }
        });

        return back()->with(
            'success',
            sprintf(
                'Прокси обновлены: %d активных инструментов, %d прокси (циклическое назначение).',
                $activeInstruments->count(),
                $proxyCount,
            )
        );
    }
}
