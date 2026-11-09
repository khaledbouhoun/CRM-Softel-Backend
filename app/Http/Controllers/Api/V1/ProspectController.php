<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexProspectRequest;
use App\Http\Requests\Api\V1\StoreProspectRequest;
use App\Http\Requests\Api\V1\UpdateProspectRequest;
use App\Http\Resources\Api\V1\ProspectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Prospect;
use App\Repositories\ProspectRepository;
use App\Services\ProspectService;
use Illuminate\Http\JsonResponse;

class ProspectController extends Controller
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly ProspectRepository $repository,
    ) {}

    public function index(IndexProspectRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->repository->paginate($request->validated()),
            ProspectResource::class,
            'Prospects retrieved successfully.',
        );
    }

    public function show(Prospect $prospect): JsonResponse
    {
        return ApiResponse::success(
            new ProspectResource($this->repository->findForDisplay($prospect->id)),
            'Prospect retrieved successfully.',
        );
    }

    public function store(StoreProspectRequest $request): JsonResponse
    {
        $prospect = $this->prospects->create($request->validated());

        return ApiResponse::created(
            new ProspectResource($this->repository->findForDisplay($prospect->id)),
            'Prospect created successfully.',
        );
    }

    public function update(UpdateProspectRequest $request, Prospect $prospect): JsonResponse
    {
        $this->prospects->update($prospect, $request->validated());

        return ApiResponse::success(
            new ProspectResource($this->repository->findForDisplay($prospect->id)),
            'Prospect updated successfully.',
        );
    }

    public function destroy(Prospect $prospect): JsonResponse
    {
        $this->prospects->delete($prospect);

        return ApiResponse::success(null, 'Prospect deleted successfully.');
    }
}
