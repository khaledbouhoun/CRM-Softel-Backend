<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AnalyticsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function index(AnalyticsRequest $request, AnalyticsService $analytics): JsonResponse
    {
        return ApiResponse::success(
            $analytics->report(
                (string) $request->validated('range', 'month'),
                $request->validated('from'),
                $request->validated('to'),
            ),
            'Analytics retrieved successfully.',
        );
    }
}
