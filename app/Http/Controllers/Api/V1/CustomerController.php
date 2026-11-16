<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConvertCustomerRequest;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Http\Resources\Api\V1\ProspectResource;
use App\Http\Resources\Api\V1\TrialResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Models\Trial;
use App\Services\ProspectConversionService;
use Illuminate\Http\JsonResponse;

/** Customers = converted prospects (same rows, same ids). */
class CustomerController extends Controller
{
    public function __construct(private readonly ProspectConversionService $conversion) {}

    public function index(PaginationRequest $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $paginator = Prospect::query()->with(Prospect::defaultRelations())->withCount('portalAccounts')
            ->whereNotNull('converted_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('contact_person', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderByDesc('converted_at')
            ->paginate(min((int) $request->query('per_page', config('crm.pagination.default_per_page')), config('crm.pagination.max_per_page')));

        return ApiResponse::paginated($paginator, ProspectResource::class, 'Customers retrieved successfully.');
    }

    /** Customer profile: prospect data + linked products (with purchase date), portal accounts, trials, upcoming appointments. */
    public function show(Prospect $prospect): JsonResponse
    {
        $prospect->load(Prospect::defaultRelations());
        $products = $prospect->products()->withPivot('purchased_at')->get();
        $accounts = $prospect->portalAccounts()->get(['id', 'name', 'email', 'phone', 'is_active', 'last_login_at']);

        return ApiResponse::success([
            'prospect' => (new ProspectResource($prospect))->resolve(),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'purchased_at' => $p->pivot->purchased_at])->all(),
            'portal_accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone,
                'is_active' => $a->is_active, 'last_login_at' => $a->last_login_at?->toIso8601String(),
            ])->all(),
            'trials' => Trial::query()->with('product')->where('prospect_id', $prospect->id)->orderByDesc('starts_at')->get()
                ->map(fn ($t) => (new TrialResource($t))->resolve())->all(),
            'appointments' => Appointment::query()->with(['type', 'prospect', 'product'])->where('prospect_id', $prospect->id)->orderByDesc('starts_at')->limit(50)->get()
                ->map(fn ($a) => (new AppointmentResource($a))->resolve())->all(),
        ], 'Customer retrieved successfully.');
    }

    public function convert(ConvertCustomerRequest $request, Prospect $prospect): JsonResponse
    {
        $converted = $this->conversion->convert(
            $prospect, $request->validated('product_ids', []), $request->validated('note'),
            $this->actor($request), $request->validated('won_stage_id'),
        );

        return ApiResponse::success(new ProspectResource($converted), 'Prospect converted to customer.');
    }
}
