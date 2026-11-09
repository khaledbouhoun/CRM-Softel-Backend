<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfigListRequest;
use App\Http\Requests\Api\V1\ReorderRequest;
use App\Http\Requests\Api\V1\SalesStageRequest;
use App\Http\Resources\Api\V1\SalesStageResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalesStage;
use App\Services\SalesStageService;
use Illuminate\Http\JsonResponse;

class SalesStageController extends Controller
{
    public function __construct(private readonly SalesStageService $stages) {}

    /** Full pipeline in display order. Filters: is_active, stage_type. */
    public function index(ConfigListRequest $request): JsonResponse
    {
        $query = SalesStage::query()->withCount('prospects')->ordered();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($type = $request->validated('stage_type')) {
            $query->where('stage_type', $type);
        }

        return ApiResponse::collection($query->get(), SalesStageResource::class, 'Sales stages retrieved successfully.');
    }

    public function show(SalesStage $salesStage): JsonResponse
    {
        return ApiResponse::success(
            new SalesStageResource($salesStage->loadCount('prospects')),
            'Sales stage retrieved successfully.',
        );
    }

    public function store(SalesStageRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new SalesStageResource($this->stages->create($request->validated())->loadCount('prospects')),
            'Sales stage created successfully.',
        );
    }

    public function update(SalesStageRequest $request, SalesStage $salesStage): JsonResponse
    {
        return ApiResponse::success(
            new SalesStageResource($this->stages->update($salesStage, $request->validated())->loadCount('prospects')),
            'Sales stage updated successfully.',
        );
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->stages->reorder($request->validated('ids'));

        return ApiResponse::collection(
            SalesStage::query()->withCount('prospects')->ordered()->get(),
            SalesStageResource::class,
            'Sales stages reordered successfully.',
        );
    }

    public function destroy(SalesStage $salesStage): JsonResponse
    {
        $this->stages->delete($salesStage);

        return ApiResponse::success(null, 'Sales stage deleted successfully.');
    }
}
