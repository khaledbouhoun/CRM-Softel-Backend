<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Requests\Api\V1\ProductReleaseRequest;
use App\Http\Requests\Api\V1\ReleaseInstallerRequest;
use App\Http\Resources\Api\V1\ProductReleaseResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProductRelease;
use App\Services\ProductReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff management of product releases (the dedicated updates module). */
class ProductReleaseController extends Controller
{
    private const WITH = ['product', 'customers'];

    public function __construct(private readonly ProductReleaseService $releases) {}

    private function resource(ProductRelease $r): ProductReleaseResource
    {
        return new ProductReleaseResource($r->load(self::WITH));
    }

    /** Filters: product_id, audience, is_published. Newest release first. */
    public function index(PaginationRequest $request): JsonResponse
    {
        $paginator = ProductRelease::query()->with(self::WITH)
            ->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', (int) $id))
            ->when($request->query('audience'), fn ($q, $a) => $q->where('audience', $a))
            ->when($request->has('is_published'), fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->orderByDesc('release_date')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', config('crm.pagination.default_per_page')), config('crm.pagination.max_per_page')));

        return ApiResponse::paginated($paginator, ProductReleaseResource::class, 'Releases retrieved successfully.');
    }

    public function show(ProductRelease $release): JsonResponse
    {
        return ApiResponse::success($this->resource($release), 'Release retrieved successfully.');
    }

    public function store(ProductReleaseRequest $request): JsonResponse
    {
        $release = $this->releases->create($request->validated(), $request->file('installer'), $this->actor($request));

        return ApiResponse::created($this->resource($release), 'Release created successfully.');
    }

    public function update(ProductReleaseRequest $request, ProductRelease $release): JsonResponse
    {
        return ApiResponse::success($this->resource($this->releases->update($release, $request->validated())), 'Release updated successfully.');
    }

    public function destroy(ProductRelease $release): JsonResponse
    {
        $this->releases->delete($release);

        return ApiResponse::success(null, 'Release deleted successfully.');
    }

    /** PUT cannot carry multipart data in PHP, so the installer has its own POST. */
    public function uploadInstaller(ReleaseInstallerRequest $request, ProductRelease $release): JsonResponse
    {
        return ApiResponse::success($this->resource($this->releases->attachInstaller($release, $request->file('installer'))), 'Installer uploaded.');
    }

    public function removeInstaller(ProductRelease $release): JsonResponse
    {
        return ApiResponse::success($this->resource($this->releases->removeInstaller($release)), 'Installer removed.');
    }

    public function download(ProductRelease $release): StreamedResponse
    {
        abort_unless($release->hasInstaller() && Storage::disk($this->releases->storageDisk())->exists($release->installer_path), 404);

        return Storage::disk($this->releases->storageDisk())->download($release->installer_path, $release->installer_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
