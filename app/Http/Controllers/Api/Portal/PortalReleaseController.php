<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PortalAccount;
use App\Models\ProductRelease;
use App\Services\ProductReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Releases the signed-in customer is entitled to (audience: all customers, owners of the product, or named customers). */
class PortalReleaseController extends Controller
{
    public function __construct(private readonly ProductReleaseService $releases) {}

    private function visible(Request $request)
    {
        /** @var PortalAccount $account */
        $account = $request->attributes->get('portal_account');

        return ProductRelease::query()->with('product')->visibleToCustomer((int) ($account->prospect_id ?? 0));
    }

    private function present(ProductRelease $r): array
    {
        return [
            'id' => $r->id, 'product' => ['id' => $r->product->id, 'name' => $r->product->name],
            'version_number' => $r->version_number, 'changelog' => $r->changelog,
            'release_date' => $r->release_date->toDateString(), 'download_url' => $r->download_url,
            'installer' => $r->hasInstaller() ? [
                'name' => $r->installer_name, 'size' => $r->installer_size,
                'download_url' => route('portal.releases.download', ['id' => $r->id], false),
            ] : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->visible($request)->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', (int) $id))
            ->orderByDesc('release_date')->orderByDesc('id');
        $items = $query->limit(200)->get();

        return ApiResponse::success($items->map(fn ($r) => $this->present($r))->all(), 'Releases retrieved successfully.', 200, ['total' => $items->count()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->visible($request)->findOrFail($id)), 'Release retrieved successfully.');
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $release = $this->visible($request)->findOrFail($id);
        $disk = $this->releases->storageDisk();
        abort_unless($release->hasInstaller() && Storage::disk($disk)->exists($release->installer_path), 404);

        return Storage::disk($disk)->download($release->installer_path, $release->installer_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
