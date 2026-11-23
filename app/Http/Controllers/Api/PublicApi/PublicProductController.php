<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicProductPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public marketing catalogue (GET /api/public/v1/products[/{slug}]). No authentication: only products
 * flagged "on web" and active are exposed, and the response is cacheable by the website / a CDN.
 * Optional ?locale=ar|fr|en adds a resolved "localized" block; the raw multilingual objects are always returned.
 */
class PublicProductController extends Controller
{
    private const CACHE = 'public, max-age=300';

    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $items = Product::query()->onWeb()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Product $p) => filled($p->slug))
            ->map(fn (Product $p) => PublicProductPresenter::item($p, $locale))->values()->all();

        return ApiResponse::success($items, 'Products retrieved successfully.', 200, ['total' => count($items)])
            ->header('Cache-Control', self::CACHE);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->onWeb()->where('slug', $slug)->first();

        if (! $product) {
            return ApiResponse::error('Product not found.', 404, 'NOT_FOUND');
        }

        return ApiResponse::success(PublicProductPresenter::item($product, $this->locale($request)), 'Product retrieved successfully.', 200)
            ->header('Cache-Control', self::CACHE);
    }

    private function locale(Request $request): ?string
    {
        $locale = (string) ($request->query('locale') ?: $request->header('X-Locale', ''));

        return in_array($locale, PublicProductPresenter::LOCALES, true) ? $locale : null;
    }
}
