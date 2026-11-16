<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SchedulingSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;

class SchedulingSettingsController extends Controller
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->scheduling->settings(), 'Scheduling settings retrieved successfully.');
    }

    public function update(SchedulingSettingsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->scheduling->updateSettings($request->validated()), 'Scheduling settings updated.');
    }
}
