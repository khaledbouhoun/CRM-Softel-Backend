<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProspectFormOptionRequest;
use App\Http\Requests\Api\V1\ReorderProspectFormOptionRequest;
use App\Http\Resources\Api\V1\ProspectFormOptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProspectFormOption;
use App\Services\ProspectFormOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProspectFormOptionController extends Controller
{
    public function __construct(private readonly ProspectFormOptionService $options) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProspectFormOption::query()->ordered();

        if ($request->filled('type')) {
            $query->ofType($request->string('type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ApiResponse::collection(
            $query->get(),
            ProspectFormOptionResource::class,
            'Prospect form options retrieved successfully.'
        );
    }

    public function show(ProspectFormOption $prospectFormOption): JsonResponse
    {
        return ApiResponse::success(
            new ProspectFormOptionResource($prospectFormOption),
            'Prospect form option retrieved successfully.'
        );
    }

    public function store(ProspectFormOptionRequest $request): JsonResponse
    {
        $created = $this->options->create($request->validated());

        return ApiResponse::created(
            new ProspectFormOptionResource($created),
            'Prospect form option created successfully.'
        );
    }

    public function update(ProspectFormOptionRequest $request, ProspectFormOption $prospectFormOption): JsonResponse
    {
        $updated = $this->options->update($prospectFormOption, $request->validated());

        return ApiResponse::success(
            new ProspectFormOptionResource($updated),
            'Prospect form option updated successfully.'
        );
    }

    public function reorder(ReorderProspectFormOptionRequest $request): JsonResponse
    {
        $type = $request->validated('type');
        $this->options->reorder($type, $request->validated('ids'));

        return ApiResponse::collection(
            ProspectFormOption::query()->ofType($type)->ordered()->get(),
            ProspectFormOptionResource::class,
            'Prospect form options reordered successfully.'
        );
    }

    public function destroy(ProspectFormOption $prospectFormOption): JsonResponse
    {
        $this->options->delete($prospectFormOption);

        return ApiResponse::success(null, 'Prospect form option deleted successfully.');
    }
}
