<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexActivityRequest;
use App\Http\Requests\Api\V1\StoreActivityRequest;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Http\Responses\ApiResponse;
use App\Models\Prospect;
use App\Repositories\ActivityRepository;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;

class ProspectActivityController extends Controller
{
    public function index(IndexActivityRequest $request, Prospect $prospect, ActivityRepository $repository): JsonResponse
    {
        return ApiResponse::paginated(
            $repository->paginate($request->validated(), $prospect),
            ActivityResource::class,
            'Activities retrieved successfully.',
        );
    }

    public function store(StoreActivityRequest $request, Prospect $prospect, ActivityService $activities): JsonResponse
    {
        return ApiResponse::created(
            new ActivityResource($activities->create($prospect, $request->validated())),
            'Activity created successfully.',
        );
    }
}
