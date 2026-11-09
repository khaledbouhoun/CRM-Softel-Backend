<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfigListRequest;
use App\Http\Requests\Api\V1\LossReasonRequest;
use App\Http\Requests\Api\V1\ReorderRequest;
use App\Http\Resources\Api\V1\LossReasonResource;
use App\Http\Responses\ApiResponse;
use App\Models\LossReason;
use App\Services\LossReasonService;
use Illuminate\Http\JsonResponse;

class LossReasonController extends Controller
{
    public function __construct(private readonly LossReasonService $reasons) {}

    /** Full list in display order. Filter: is_active. */
    public function index(ConfigListRequest $request): JsonResponse
    {
        $query = LossReason::query()->ordered();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ApiResponse::collection($query->get(), LossReasonResource::class, 'Loss reasons retrieved successfully.');
    }

    public function show(LossReason $lossReason): JsonResponse
    {
        return ApiResponse::success(new LossReasonResource($lossReason), 'Loss reason retrieved successfully.');
    }

    public function store(LossReasonRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new LossReasonResource($this->reasons->create($request->validated())),
            'Loss reason created successfully.',
        );
    }

    public function update(LossReasonRequest $request, LossReason $lossReason): JsonResponse
    {
        return ApiResponse::success(
            new LossReasonResource($this->reasons->update($lossReason, $request->validated())),
            'Loss reason updated successfully.',
        );
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->reasons->reorder($request->validated('ids'));

        return ApiResponse::collection(
            LossReason::query()->ordered()->get(),
            LossReasonResource::class,
            'Loss reasons reordered successfully.',
        );
    }

    public function destroy(LossReason $lossReason): JsonResponse
    {
        $this->reasons->delete($lossReason);

        return ApiResponse::success(null, 'Loss reason deleted successfully.');
    }
}
