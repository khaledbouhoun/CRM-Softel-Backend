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
        $trial = $this->trials->activate(
            $prospect, Product::query()->findOrFail($v['product_id']), $v['url'],
            isset($v['starts_at']) ? Carbon::parse($v['starts_at'], $tz) : null,
            isset($v['ends_at']) ? Carbon::parse($v['ends_at'], $tz) : null,
            $this->actor($request), $v['internal_notes'] ?? null,
        );

        return ApiResponse::created(new TrialResource($trial->load('product')), 'Trial activated.');
    }

    public function revoke(Trial $trial): JsonResponse
    {
        return ApiResponse::success(new TrialResource($this->trials->revoke($trial)->load('product')), 'Trial access revoked.');
    }
}
