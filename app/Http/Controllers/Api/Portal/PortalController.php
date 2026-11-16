<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PortalSubmitRequest;
use App\Http\Resources\Api\V1\PortalPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\Intervention;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\ProductResource;
use App\Models\Trial;
use App\Services\AppointmentService;
use App\Services\PortalRequestService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Customer-scoped reads and requests. Every query is filtered by the signed-in account / its prospect. */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalRequestService $requests,
        private readonly AppointmentService $appointments,
        private readonly SchedulingService $scheduling,
    ) {}

    private function account(Request $request): PortalAccount
    {
        return $request->attributes->get('portal_account');
    }

    /** Products the account's company has purchased. */
    private function ownedProductIds(PortalAccount $a): array
    {
        return $a->prospect_id
            ? Product::query()->whereHas('prospects', fn ($q) => $q->where('prospects.id', $a->prospect_id)->whereNotNull('prospect_products.purchased_at'))->pluck('id')->all()
            : [];
    }

    public function products(): JsonResponse
    {
        $items = Product::query()->active()->orderBy('name')->get()->map(fn ($p) => PortalPresenter::product($p))->all();

        return ApiResponse::success($items, 'Products retrieved successfully.', 200, ['total' => count($items)]);
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'], config('app.timezone'));
        $to = Carbon::parse($data['to'], config('app.timezone'));

        if ($from->diffInDays($to) > 62) {
            return ApiResponse::error('Please request at most 62 days.', 422, 'RANGE_TOO_LARGE');
        }

        return ApiResponse::success([
            'timezone' => config('app.timezone'),
            'slot_minutes' => (int) $this->scheduling->setting('slot_minutes'),
            'hold_expiry_minutes' => (int) $this->scheduling->setting('hold_expiry_minutes'),
            'slots' => $this->scheduling->availableSlots($from, $to),
        ], 'Availability retrieved successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $account = $this->account($request)->load('prospect');

        return ApiResponse::success(PortalPresenter::account($account), 'Profile retrieved successfully.');
    }

    public function updateMe(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $account->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 .\-()\/]{4,28}$/'],
            'locale' => ['sometimes', 'in:ar,en,fr'],
        ]));

        return ApiResponse::success(PortalPresenter::account($account->load('prospect')), 'Profile updated.');
    }

    public function overview(Request $request): JsonResponse
    {
        $account = $this->account($request)->load('prospect');
        $this->scheduling->releaseExpired();

        $upcoming = $this->appointmentsFor($account)->whereIn('status', Appointment::BLOCKING)
            ->where('starts_at', '>=', now()->format('Y-m-d H:i:s'))->orderBy('starts_at')->limit(5)->get();

        return ApiResponse::success([
            'account' => PortalPresenter::account($account),
            'open_requests' => PortalRequest::query()->where('portal_account_id', $account->id)->whereIn('status', ['pending', 'scheduled'])->count(),
            'upcoming_appointments' => $upcoming->map(fn ($a) => PortalPresenter::appointment($a))->all(),
            'active_trials' => $this->trialsFor($account)->activeNow()->get()->map(fn ($t) => PortalPresenter::trial($t))->all(),
            'products_count' => count($this->ownedProductIds($account)),
        ], 'Overview retrieved successfully.');
    }

    // ---- requests

    public function submit(PortalSubmitRequest $request): JsonResponse
    {
        $created = $this->requests->submit($this->account($request), $request->validated());

        return ApiResponse::created(PortalPresenter::request($created), 'Request received.');
    }

    public function requestsIndex(Request $request): JsonResponse
    {
        $this->scheduling->releaseExpired();
        $items = PortalRequest::query()->with(['product', 'appointment.type', 'appointment.product'])
            ->where('portal_account_id', $this->account($request)->id)->latest('id')->get();

        return ApiResponse::success($items->map(fn ($r) => PortalPresenter::request($r))->all(), 'Requests retrieved successfully.', 200, ['total' => $items->count()]);
    }

    public function requestShow(Request $request, int $id): JsonResponse
    {
        $this->scheduling->releaseExpired();

        return ApiResponse::success(PortalPresenter::request($this->ownRequest($request, $id)), 'Request retrieved successfully.');
    }

    public function requestCancel(Request $request, int $id): JsonResponse
    {
        $cancelled = $this->requests->cancel($this->ownRequest($request, $id));

        return ApiResponse::success(PortalPresenter::request($cancelled->load(['product', 'appointment.type', 'appointment.product'])), 'Request cancelled.');
    }

    private function ownRequest(Request $request, int $id): PortalRequest
    {
        return PortalRequest::query()->with(['product', 'appointment.type', 'appointment.product'])
            ->where('portal_account_id', $this->account($request)->id)->findOrFail($id);
    }

    // ---- appointments

    public function appointmentsIndex(Request $request): JsonResponse
    {
        $this->scheduling->releaseExpired();
        $items = $this->appointmentsFor($this->account($request))->whereNotIn('status', [Appointment::EXPIRED])->orderBy('starts_at')->get();

        return ApiResponse::success($items->map(fn ($a) => PortalPresenter::appointment($a))->all(), 'Appointments retrieved successfully.', 200, ['total' => $items->count()]);
    }

    public function appointmentAccept(Request $request, int $id): JsonResponse
    {
        $appointment = $this->appointments->accept($this->ownAppointment($request, $id));

        return ApiResponse::success(PortalPresenter::appointment($appointment->load(['type', 'product'])), 'Appointment confirmed.');
    }

    public function appointmentDecline(Request $request, int $id): JsonResponse
    {
        $appointment = $this->appointments->cancel($this->ownAppointment($request, $id), null, 'customer');

        return ApiResponse::success(PortalPresenter::appointment($appointment->load(['type', 'product'])), 'Appointment declined.');
    }

    private function ownAppointment(Request $request, int $id): Appointment
    {
        return $this->appointmentsFor($this->account($request))->findOrFail($id);
    }

    private function appointmentsFor(PortalAccount $account)
    {
        return Appointment::query()->with(['type', 'product'])->where('prospect_id', $account->prospect_id ?? 0);
    }

    // ---- trials, products, resources, updates

    public function trials(Request $request): JsonResponse
    {
        $items = $this->trialsFor($this->account($request))->orderByDesc('starts_at')->get();

        return ApiResponse::success($items->map(fn ($t) => PortalPresenter::trial($t))->all(), 'Trials retrieved successfully.', 200, ['total' => $items->count()]);
    }

    private function trialsFor(PortalAccount $account)
    {
        return Trial::query()->with('product')->whereNull('revoked_at')->where('prospect_id', $account->prospect_id ?? 0);
    }

    public function ownedProducts(Request $request): JsonResponse
    {
        $ids = $this->ownedProductIds($this->account($request));
        $items = Product::query()->whereIn('id', $ids)->orderBy('name')->get();

        return ApiResponse::success($items->map(fn ($p) => PortalPresenter::product($p))->all(), 'Products retrieved successfully.', 200, ['total' => $items->count()]);
    }

    public function resources(Request $request): JsonResponse
    {
        $ids = $this->ownedProductIds($this->account($request));
        $items = ProductResource::query()->whereIn('product_id', $ids)->where('is_active', true)->where('customer_visible', true)
            ->orderBy('product_id')->orderBy('sort_order')->get();

        return ApiResponse::success($items->map(fn ($r) => PortalPresenter::resource($r))->all(), 'Resources retrieved successfully.', 200, ['total' => $items->count()]);
    }

    /** Published fixes/updates/releases for the customer's own products or customers explicitly targeted. */
    public function updates(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $owned = $this->ownedProductIds($account);

        $items = Intervention::query()->with(['type', 'product'])
            ->where('source', 'employee')->where('is_published', true)
            ->where(function ($q) use ($account, $owned) {
                $q->whereHas('customers', fn ($c) => $c->where('prospects.id', $account->prospect_id ?? 0))
                    ->orWhere(fn ($g) => $g->whereDoesntHave('customers')->whereIn('product_id', $owned));
            })->orderByDesc('published_at')->limit(100)->get();

        return ApiResponse::success($items->map(fn ($i) => PortalPresenter::update($i))->all(), 'Updates retrieved successfully.', 200, ['total' => $items->count()]);
    }
}
