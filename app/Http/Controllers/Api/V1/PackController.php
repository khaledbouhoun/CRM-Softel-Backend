<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PackRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Pack;
use Illuminate\Http\JsonResponse;

/** Product suites: a customer picks at most one product (tier) per pack. */
class PackController extends Controller
{
    private function present(Pack $pack): array
    {
        return [
            'id' => $pack->id, 'name' => $pack->name, 'code' => $pack->code, 'description' => $pack->description,
            'products' => $pack->relationLoaded('products')
                ? $pack->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'is_active' => $p->is_active])->values()->all()
                : null,
        ];
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            Pack::query()->with(['products' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()->map(fn ($p) => $this->present($p))->all(),
            'Packs retrieved successfully.',
        );
    }

    public function show(Pack $pack): JsonResponse
    {
        return ApiResponse::success($this->present($pack->load('products')), 'Pack retrieved successfully.');
    }

    public function store(PackRequest $request): JsonResponse
    {
        return ApiResponse::created($this->present(Pack::query()->create($request->validated())->load('products')), 'Pack created successfully.');
    }

    public function update(PackRequest $request, Pack $pack): JsonResponse
    {
        $pack->update($request->validated());

        return ApiResponse::success($this->present($pack->load('products')), 'Pack updated successfully.');
    }

    /** Its products stay (they simply become standalone). */
    public function destroy(Pack $pack): JsonResponse
    {
        $pack->delete();

        return ApiResponse::success(null, 'Pack deleted successfully.');
    }
}
