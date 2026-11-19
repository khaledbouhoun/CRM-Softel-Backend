<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActivateTrialRequest;
use App\Http\Resources\Api\V1\TrialResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Trial;
use App\Services\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrialController extends Controller
{
    public function __construct(private readonly TrialService $trials) {}

    public function index(Prospect $prospect): JsonResponse
    {
        return ApiResponse::collection(
            Trial::query()->with('product')->where('prospect_id', $prospect->id)->orderByDesc('starts_at')->get(),
            TrialResource::class,
            'Trials retrieved successfully.',
        );
    }

    public function store(ActivateTrialRequest $request, Prospect $prospect): JsonResponse
    {
        $v = $request->validated();
        $tz = config('app.timezone');
        $start = isset($v['starts_at']) ? Carbon::parse($v['starts_at'], $tz) : null;
        $end = isset($v['ends_at']) ? Carbon::parse($v['ends_at'], $tz) : null;
        $activate = fn (int $productId) => $this->trials->activate(
            $prospect, Product::query()->findOrFail($productId), $start?->copy(), $end?->copy(),
            $this->actor($request), $v['internal_notes'] ?? null,
        )->load('product');

        if (! isset($v['product_ids'])) {
            return ApiResponse::created(new TrialResource($activate($v['product_id'])), 'Trial activated.');
        }

        // Several products: one trial each, all-or-nothing.
        $trials = DB::transaction(fn () => collect($v['product_ids'])->map($activate)->all());

        return ApiResponse::created(array_map(fn ($t) => (new TrialResource($t))->resolve(), $trials), 'Trials activated.');
    }

    public function revoke(Trial $trial): JsonResponse
    {
        return ApiResponse::success(new TrialResource($this->trials->revoke($trial)->load('product')), 'Trial access revoked.');
    }
}
