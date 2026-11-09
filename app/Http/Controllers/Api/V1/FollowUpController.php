<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseFollowUpRequest;
use App\Http\Requests\Api\V1\CompleteFollowUpRequest;
use App\Http\Requests\Api\V1\ConvertFollowUpRequest;
use App\Http\Requests\Api\V1\IndexFollowUpRequest;
use App\Http\Requests\Api\V1\UpdateFollowUpRequest;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Http\Resources\Api\V1\FollowUpTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\FollowUpTask;
use App\Repositories\FollowUpRepository;
use App\Services\FollowUpService;
use Illuminate\Http\JsonResponse;

class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUpService $followUps) {}

    public function index(IndexFollowUpRequest $request, FollowUpRepository $repository): JsonResponse
    {
        return ApiResponse::paginated(
            $repository->paginate($request->validated()),
            FollowUpTaskResource::class,
            'Follow-ups retrieved successfully.',
        );
    }

    public function show(FollowUpTask $followUp): JsonResponse
    {
        return ApiResponse::success(
            new FollowUpTaskResource($followUp->load(['prospect', 'activity'])),
            'Follow-up retrieved successfully.',
        );
    }

    public function update(UpdateFollowUpRequest $request, FollowUpTask $followUp): JsonResponse
    {
        return ApiResponse::success(
            new FollowUpTaskResource($this->followUps->update($followUp, $request->validated())),
            'Follow-up updated successfully.',
        );
    }

    public function complete(CompleteFollowUpRequest $request, FollowUpTask $followUp): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::success(
            new FollowUpTaskResource($this->followUps->complete(
                $followUp,
                $data['closing_note'] ?? null,
                ($data['create_activity'] ?? false) ? $data : null,
            )),
            'Follow-up completed successfully.',
        );
    }

    /** Idempotent: 201 when the activity is created, 200 when it already existed. */
    public function convertToActivity(ConvertFollowUpRequest $request, FollowUpTask $followUp): JsonResponse
    {
        [$activity, $created] = $this->followUps->convertToActivity($followUp, $request->validated());

        return $created
            ? ApiResponse::created(new ActivityResource($activity), 'Follow-up converted into an activity successfully.')
            : ApiResponse::success(new ActivityResource($activity), 'This follow-up was already converted into an activity.');
    }

    public function cancel(CloseFollowUpRequest $request, FollowUpTask $followUp): JsonResponse
    {
        return ApiResponse::success(
            new FollowUpTaskResource($this->followUps->cancel($followUp, $request->validated('closing_note'))),
            'Follow-up cancelled successfully.',
        );
    }
}
