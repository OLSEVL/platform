<?php

namespace App\Http\Controllers;

use App\Services\PlatformProcessMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web-диагностика фоновых процессов платформы (/settings/processes).
 */
class PlatformDiagnosticsController extends Controller
{
    private const ALLOWED_KEYS = [
        'bots_scan',
        'queue_default',
        'queue_telegram_analyst',
        'telegram_analyst_poll',
        'proxies_probe_loop',
        'pocket_options_proxy',
    ];

    public function index(PlatformProcessMonitorService $monitor): View
    {
        return view('settings.processes', [
            'initialSnapshot' => $monitor->snapshot(),
        ]);
    }

    public function liveAjax(PlatformProcessMonitorService $monitor): JsonResponse
    {
        return response()->json($monitor->snapshot());
    }

    public function kill(Request $request, string $key, PlatformProcessMonitorService $monitor): JsonResponse
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_process'], 422);
        }

        $botId = $request->input('bot_id') !== null ? (int) $request->input('bot_id') : null;
        $result = $monitor->kill($key, $botId);
        $result['status'] = $monitor->snapshot();

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function restart(Request $request, string $key, PlatformProcessMonitorService $monitor): JsonResponse
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            return response()->json(['ok' => false, 'error' => 'unsupported_process'], 422);
        }

        $botId = $request->input('bot_id') !== null ? (int) $request->input('bot_id') : null;
        $result = $monitor->restart($key, $botId);
        if (! isset($result['status'])) {
            $result['status'] = $monitor->snapshot();
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
