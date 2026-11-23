<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PortalAccount;
use App\Models\ProductRelease;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\ProductReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Releases the signed-in customer is entitled to (audience: all customers, owners of the product, or named customers). */
class PortalReleaseController extends Controller
{
    public function __construct(private readonly ProductReleaseService $releases, private readonly BillingService $billing) {}

    private function visible(Request $request)
    {
        /** @var PortalAccount $account */
        $account = $request->attributes->get('portal_account');

        return ProductRelease::query()->with('product')->visibleToCustomer((int) ($account->prospect_id ?? 0));
    }

    private function maintenance(Request $request): ?Subscription
    {
        /** @var PortalAccount $account */
        $account = $request->attributes->get('portal_account');

        return $account->prospect_id ? $this->billing->maintenance((int) $account->prospect_id) : null;
    }

    /**
     * Business rule: while maintenance is active every version can be downloaded. Once it has expired, versions
     * released after the expiry date are locked. Customers with no maintenance plan set up are not restricted.
     */
    private function locked(ProductRelease $r, ?Subscription $maintenance): bool
    {
        return $maintenance !== null && ! $maintenance->isActive() && $maintenance->expires_at !== null
            && $r->release_date->copy()->startOfDay()->gt($maintenance->expires_at->copy()->startOfDay());
    }

    private function present(ProductRelease $r, ?Subscription $maintenance = null): array
    {
        $locked = $this->locked($r, $maintenance);

        return [
            'maintenance_required' => $locked,
            'id' => $r->id, 'product' => ['id' => $r->product->id, 'name' => $r->product->name, 'slug' => $r->product->slug],
            'version_number' => $r->version_number, 'changelog' => $r->changelog,
            'release_date' => $r->release_date->toDateString(), 'download_url' => $locked ? null : $r->download_url,
            'installer' => $r->hasInstaller() && ! $locked ? [
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

        $maintenance = $this->maintenance($request);

        return ApiResponse::success($items->map(fn ($r) => $this->present($r, $maintenance))->all(), 'Releases retrieved successfully.', 200, [
            'total' => $items->count(),
            'maintenance_active' => $maintenance === null || $maintenance->isActive(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->visible($request)->findOrFail($id), $this->maintenance($request)), 'Release retrieved successfully.');
    }

    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $release = $this->visible($request)->findOrFail($id);

        if ($this->locked($release, $this->maintenance($request))) {
            return ApiResponse::error('An active maintenance subscription is required for this version.', 403, 'MAINTENANCE_REQUIRED');
        }

        $disk = $this->releases->storageDisk();
        abort_unless($release->hasInstaller() && Storage::disk($disk)->exists($release->installer_path), 404);

        return Storage::disk($disk)->download($release->installer_path, $release->installer_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
