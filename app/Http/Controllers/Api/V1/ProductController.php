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
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    /** Full list ordered by name. Filters: is_active, search, pack_id, has_demo, prospect_id (+ relation: linked|interested|owned). */
    public function index(ConfigListRequest $request): JsonResponse
    {
        $query = Product::query()->withCount('prospects')->with('pack')->orderBy('name')->orderBy('id');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('prospect_id')) {
            $query->forProspect((int) $request->validated('prospect_id'), $request->validated('relation') ?? 'linked');
        }

        if ($request->filled('pack_id')) {
            $query->where('pack_id', (int) $request->validated('pack_id'));
        }

        if ($request->has('has_demo') && $request->boolean('has_demo')) {
            $query->withDemo();
        }

        if (filled($search = $request->validated('search'))) {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return ApiResponse::collection($query->get(), ProductResource::class, 'Products retrieved successfully.');
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::success(
            new ProductResource($product->loadCount('prospects')->load('pack')),
            'Product retrieved successfully.',
        );
    }

    public function store(ProductRequest $request): JsonResponse
    {
        return ApiResponse::created(
            new ProductResource($this->products->create($request->validated())->loadCount('prospects')->load('pack')),
            'Product created successfully.',
        );
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        return ApiResponse::success(
            new ProductResource($this->products->update($product, $request->validated())->loadCount('prospects')->load('pack')),
            'Product updated successfully.',
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->products->delete($product);

        return ApiResponse::success(null, 'Product deleted successfully.');
    }

    public function uploadDemo(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.config('crm.uploads.installer_max_kb', 1048576)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        $file = $request->file('file');
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext = $file->getClientOriginalExtension() ?: 'exe';
        $safeName = Str::slug($filename).'_'.time().'.'.$ext;

        $path = $file->storeAs('products/demos', $safeName, 'public');
        $url = asset('storage/'.$path);

        if ($request->filled('product_id')) {
            $product = Product::find($request->input('product_id'));
            if ($product) {
                $product->update(['demo_url' => $url]);
            }
        }

        return ApiResponse::success([
            'url' => $url,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'path' => $path,
        ], 'Demo file uploaded successfully.');
    }
}
