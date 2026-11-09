<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangeStageRequest;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Resources\Api\V1\ProspectResource;
use App\Http\Resources\Api\V1\ProspectStageHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Prospect;
use App\Repositories\ProspectRepository;
use App\Services\StageTransitionService;
use Illuminate\Http\JsonResponse;

class ProspectStageController extends Controller
{
    public function __construct(private readonly ProspectRepository $repository) {}

    public function change(ChangeStageRequest $request, Prospect $prospect, StageTransitionService $transitions): JsonResponse
    {
        $history = $transitions->change($prospect, $request->validated());

        return ApiResponse::success(
            [
                'prospect' => (new ProspectResource($this->repository->findForDisplay($prospect->id)))->resolve($request),
                'stage_history' => (new ProspectStageHistoryResource($history))->resolve($request),
            ],
            'Prospect stage changed successfully.',
        );
    }

    /** Pagination only (per_page, page, direction); newest first by default. */
    public function history(PaginationRequest $request, Prospect $prospect): JsonResponse
    {
        return ApiResponse::paginated(
            $this->repository->stageHistory($prospect, $request->validated()),
            ProspectStageHistoryResource::class,
            'Stage history retrieved successfully.',
        );
    }
}
