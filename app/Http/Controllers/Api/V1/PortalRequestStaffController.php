<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Requests\Api\V1\UpdatePortalRequestRequest;
use App\Http\Resources\Api\V1\PortalRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\PortalRequest;
use Illuminate\Http\JsonResponse;

/** Staff view of what visitors asked for through the website. */
class PortalRequestStaffController extends Controller
{
    public function index(PaginationRequest $request): JsonResponse
    {
        $paginator = PortalRequest::query()->with('product')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', config('crm.pagination.default_per_page')), config('crm.pagination.max_per_page')));

        return ApiResponse::paginated($paginator, PortalRequestResource::class, 'Requests retrieved successfully.');
    }

    public function show(PortalRequest $portalRequest): JsonResponse
    {
        return ApiResponse::success(new PortalRequestResource($portalRequest->load('product')), 'Request retrieved successfully.');
    }

    /** For quotation/trial requests (demos change status through their appointment). */
    public function update(UpdatePortalRequestRequest $request, PortalRequest $portalRequest): JsonResponse
    {
        $portalRequest->update($request->validated());

        return ApiResponse::success(new PortalRequestResource($portalRequest->load('product')), 'Request updated.');
    }
}
