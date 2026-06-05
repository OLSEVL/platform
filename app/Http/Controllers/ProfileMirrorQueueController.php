<?php

namespace App\Http\Controllers;

use App\Services\Mirror\MirrorQueueDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ProfileMirrorQueueController extends Controller
{
    public function index(): View
    {
        return view('profile.mirror-queue');
    }

    /** JSON для polling с фронта (обновление каждые 2 с). */
    public function data(MirrorQueueDashboardService $dashboard): JsonResponse
    {
        $userId = (int) auth()->id();

        return response()->json($dashboard->buildSnapshotForUser($userId));
    }
}
