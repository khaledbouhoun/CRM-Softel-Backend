<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfigListRequest;
use App\Http\Requests\Api\V1\ProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** Full list ordered by name. Filters: is_active, search. */
    public function index(ConfigListRequest $request): JsonResponse
    {
        $query = Product::query()->withCount('prospects')->orderBy('name')->orderBy('id');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (filled($search = $request->validated('search'))) {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return ApiResponse::collection($query->get(), ProductResource::class, 'Products retrieved successfully.');
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::success(
            new ProductResource($product->loadCount('prospects')),
            'Product retrieved successfully.',
        );
    }

    public function store(ProductRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new ProductResource($this->products->create($request->validated())->loadCount('prospects')),
            'Product created successfully.',
        );
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        return ApiResponse::success(
            new ProductResource($this->products->update($product, $request->validated())->loadCount('prospects')),
            'Product updated successfully.',
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->products->delete($product);

        return ApiResponse::success(null, 'Product deleted successfully.');
    }
}
