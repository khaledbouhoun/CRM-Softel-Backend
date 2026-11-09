<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexFollowUpRequest;
use App\Http\Requests\Api\V1\StoreFollowUpRequest;
use App\Http\Resources\Api\V1\FollowUpTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\Prospect;
use App\Repositories\FollowUpRepository;
use App\Services\FollowUpService;
use Illuminate\Http\JsonResponse;

class ProspectFollowUpController extends Controller
{
    public function index(IndexFollowUpRequest $request, Prospect $prospect, FollowUpRepository $repository): JsonResponse
    {
        return ApiResponse::paginated(
            $repository->paginate($request->validated(), $prospect),
            FollowUpTaskResource::class,
            'Follow-ups retrieved successfully.',
        );
    }

    public function store(StoreFollowUpRequest $request, Prospect $prospect, FollowUpService $followUps): JsonResponse
    {
        return ApiResponse::created(
            new FollowUpTaskResource($followUps->create($prospect, $request->validated())),
            'Follow-up created successfully.',
        );
    }
}
