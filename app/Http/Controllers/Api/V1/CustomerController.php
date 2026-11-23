<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductRelation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConvertCustomerRequest;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Http\Resources\Api\V1\InterventionResource;
use App\Http\Resources\Api\V1\ProspectStageHistoryResource;
use App\Http\Resources\Api\V1\ProspectResource;
use App\Http\Resources\Api\V1\TrialResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Intervention;
use App\Models\Prospect;
use App\Models\Subscription;
use App\Models\Trial;
use App\Services\ProspectConversionService;
use Illuminate\Http\JsonResponse;

/** Customers = converted prospects (same rows, same ids). */
class CustomerController extends Controller
{
    /** "Expiring" in the customer list = maintenance ending within this many days. */
    private const EXPIRING_DAYS = 60;

    public function __construct(private readonly ProspectConversionService $conversion) {}

    /**
     * Filters: search (company, contact, phone, e-mail), product_id (+ product_relation),
     * maintenance (all|active|expiring|expired_none), portal (all|has_account|no_account).
     */
    public function index(PaginationRequest $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $now = now()->format('Y-m-d H:i:s');
        $soon = now()->addDays(self::EXPIRING_DAYS)->endOfDay()->format('Y-m-d H:i:s');
        $activePlan = fn ($q) => $q->where('type', Subscription::MAINTENANCE)->where('status', 'active')->where('expires_at', '>=', $now);

        $paginator = Prospect::query()->with([...Prospect::defaultRelations(), 'subscriptions'])->withCount('portalAccounts')
            ->whereNotNull('converted_at')
            ->when($request->filled('product_id'), fn ($q) => $q->forProduct(
                (int) $request->query('product_id'),
                in_array($request->query('product_relation'), ProductRelation::values(), true) ? $request->query('product_relation') : 'owned',
            ))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('contact_person', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($request->query('maintenance'), fn ($q, $m) => match ($m) {
                'active' => $q->whereHas('subscriptions', $activePlan),
                'expiring' => $q->whereHas('subscriptions', fn ($s) => $activePlan($s)->where('expires_at', '<=', $soon)),
                'expired_none' => $q->whereDoesntHave('subscriptions', $activePlan),
                default => $q,
            })
            ->when($request->query('portal'), fn ($q, $m) => match ($m) {
                'has_account' => $q->whereHas('portalAccounts'),
                'no_account' => $q->whereDoesntHave('portalAccounts'),
                default => $q,
            })
            ->tap(fn ($q) => $this->applySort($q, (string) $request->query('sort', 'converted_at'), $request->query('direction') === 'asc' ? 'asc' : 'desc'))
            ->paginate(min((int) $request->query('per_page', config('crm.pagination.default_per_page')), config('crm.pagination.max_per_page')));

        return ApiResponse::paginated($paginator, ProspectResource::class, 'Customers retrieved successfully.');
    }

    /** Sort: name|city|phone|converted_at|maintenance (plan end date; no plan last). */
    private function applySort($query, string $sort, string $direction): void
    {
        match ($sort) {
            'name', 'city', 'phone', 'converted_at' => $query->orderBy($sort, $direction),
            'maintenance' => $query->orderBy(
                Subscription::query()->select('expires_at')->whereColumn('subscriptions.prospect_id', 'prospects.id')->where('type', Subscription::MAINTENANCE)->limit(1),
                $direction,
            ),
            default => $query->orderByDesc('converted_at'),
        };
        $query->orderBy('id');
    }

    /** Customer profile: prospect data + linked products (with purchase date), portal accounts, trials, upcoming appointments. */
    public function show(Prospect $prospect): JsonResponse
    {
        $prospect->load([...Prospect::defaultRelations(), 'stageHistories.fromStage', 'stageHistories.toStage', 'stageHistories.lossReason']);
        $products = $prospect->products()->withPivot('purchased_at')->get();
        $accounts = $prospect->portalAccounts()->get(['id', 'name', 'email', 'phone', 'is_active', 'last_login_at']);

        return ApiResponse::success([
            'prospect' => (new ProspectResource($prospect))->resolve(),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'purchased_at' => $p->pivot->purchased_at])->all(),
            'portal_accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone,
                'is_active' => $a->is_active, 'last_login_at' => $a->last_login_at?->toIso8601String(),
            ])->all(),
            'stage_history' => $prospect->stageHistories->sortBy([['changed_at', 'asc'], ['id', 'asc']])->values()
                ->map(fn ($h) => (new ProspectStageHistoryResource($h))->resolve())->all(),
            'activities' => $prospect->activities()->with(['prospect', 'followUp'])->orderByDesc('activity_at')->orderByDesc('id')->get()
                ->map(fn ($a) => (new ActivityResource($a))->resolve())->all(),
            'subscriptions' => $prospect->subscriptions()->orderByDesc('starts_at')->orderByDesc('id')->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'type' => $s->type, 'status' => $s->state(), 'is_active' => $s->isActive(),
                    'days_remaining' => $s->daysRemaining(),
                    'starts_at' => $s->starts_at?->toIso8601String(), 'expires_at' => $s->expires_at?->toIso8601String(),
                ])->all(),
            'interventions' => Intervention::query()->with(['type', 'status', 'priority', 'product', 'prospect', 'customers'])
                ->where(fn ($q) => $q->where('prospect_id', $prospect->id)->orWhereHas('customers', fn ($c) => $c->where('prospects.id', $prospect->id)))
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($i) => (new InterventionResource($i))->resolve())->all(),
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
