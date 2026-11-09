<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardRequest;
use App\Http\Responses\ApiResponse;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::success(
            $dashboard->summary(
                (int) $request->validated('recent_limit', config('crm.dashboard.recent_activities_limit')),
                (int) $request->validated('upcoming_days', config('crm.dashboard.upcoming_days')),
            ),
            'Dashboard retrieved successfully.',
        );
    }
}
