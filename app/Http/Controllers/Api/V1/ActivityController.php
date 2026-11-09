<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexActivityRequest;
use App\Http\Requests\Api\V1\LinkActivityRequest;
use App\Http\Requests\Api\V1\StoreNativeActivityRequest;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Http\Responses\ApiResponse;
use App\Models\Activity;
use App\Models\Prospect;
use App\Repositories\ActivityRepository;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function index(IndexActivityRequest $request, ActivityRepository $repository): JsonResponse
    {
        return ApiResponse::paginated(
            $repository->paginate($request->validated()),
            ActivityResource::class,
            'Activities retrieved successfully.',
        );
    }

    public function show(Activity $activity): JsonResponse
    {
        return ApiResponse::success(
            new ActivityResource($activity->load(['prospect.salesStage', 'followUp'])),
            'Activity retrieved successfully.',
        );
    }

    /** Native activity: the prospect is optional. */
    public function store(StoreNativeActivityRequest $request, ActivityService $activities): JsonResponse
    {
        return ApiResponse::created(
            new ActivityResource($activities->createNative($request->validated())),
            'Activity created successfully.',
        );
    }

    public function linkProspect(LinkActivityRequest $request, Activity $activity, ActivityService $activities): JsonResponse
    {
        $prospect = Prospect::query()->findOrFail($request->validated('prospect_id'));

        return ApiResponse::success(
            new ActivityResource($activities->link($activity, $prospect, (bool) $request->validated('confirm_relink', false))),
            'Activity linked to the prospect successfully.',
        );
    }
}
