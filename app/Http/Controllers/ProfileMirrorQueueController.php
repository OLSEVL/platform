<?php

namespace App\Http\Controllers;

use App\Models\UserTradingSetting;
use App\Services\Mirror\MirrorQueueDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileMirrorQueueController extends Controller
{
    public function index(): View
    {
        $settings = $this->settingsForUser((int) auth()->id());
        $window = MirrorQueueDashboardService::normalizeWindow(
            (int) ($settings->mirror_queue_hours ?? MirrorQueueDashboardService::DEFAULT_WINDOW_HOURS),
            (int) ($settings->mirror_queue_limit ?? MirrorQueueDashboardService::DEFAULT_WINDOW_LIMIT),
        );

        return view('profile.mirror-queue', [
            'queueHours' => $window['hours'],
            'queueLimit' => $window['limit'],
            'hoursOptions' => [1, 2, 3, 6, 12, 24, 48, 72, 168],
            'limitOptions' => [50, 80, 100, 150, 200, 300, 500],
        ]);
    }

    /** JSON для polling с фронта (обновление каждые 2 с). */
    public function data(Request $request, MirrorQueueDashboardService $dashboard): JsonResponse
    {
        $userId = (int) auth()->id();
        $settings = $this->settingsForUser($userId);

        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:'.MirrorQueueDashboardService::MIN_WINDOW_HOURS, 'max:'.MirrorQueueDashboardService::MAX_WINDOW_HOURS],
            'limit' => ['sometimes', 'integer', 'min:'.MirrorQueueDashboardService::MIN_WINDOW_LIMIT, 'max:'.MirrorQueueDashboardService::MAX_WINDOW_LIMIT],
        ]);

        $window = MirrorQueueDashboardService::normalizeWindow(
            isset($validated['hours'])
                ? (int) $validated['hours']
                : (int) ($settings->mirror_queue_hours ?? MirrorQueueDashboardService::DEFAULT_WINDOW_HOURS),
            isset($validated['limit'])
                ? (int) $validated['limit']
                : (int) ($settings->mirror_queue_limit ?? MirrorQueueDashboardService::DEFAULT_WINDOW_LIMIT),
        );

        return response()->json($dashboard->buildSnapshotForUser(
            $userId,
            $window['hours'],
            $window['limit'],
        ));
    }

    /** Сохранить окно/лимит таблицы очереди (настройка на странице /profile/mirror-queue). */
    public function updateWindow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:'.MirrorQueueDashboardService::MIN_WINDOW_HOURS, 'max:'.MirrorQueueDashboardService::MAX_WINDOW_HOURS],
            'limit' => ['required', 'integer', 'min:'.MirrorQueueDashboardService::MIN_WINDOW_LIMIT, 'max:'.MirrorQueueDashboardService::MAX_WINDOW_LIMIT],
        ]);

        $window = MirrorQueueDashboardService::normalizeWindow(
            (int) $validated['hours'],
            (int) $validated['limit'],
        );

        $settings = $this->settingsForUser((int) auth()->id());
        $settings->update([
            'mirror_queue_hours' => $window['hours'],
            'mirror_queue_limit' => $window['limit'],
        ]);

        return response()->json([
            'ok' => true,
            'hours' => $window['hours'],
            'limit' => $window['limit'],
        ]);
    }

    private function settingsForUser(int $userId): UserTradingSetting
    {
        return UserTradingSetting::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'is_demo' => true,
                'order_amount' => 1,
                'order_amounts' => [1.0],
                'martingale_step' => 0,
                'mirror_trades_enabled' => false,
                'mirror_wait_previous_settled' => false,
                'mirror_queue_hours' => MirrorQueueDashboardService::DEFAULT_WINDOW_HOURS,
                'mirror_queue_limit' => MirrorQueueDashboardService::DEFAULT_WINDOW_LIMIT,
            ]
        );
    }
}
