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
use App\Models\Prospect;
use App\Rules\ValidWilaya;
use App\Services\PortalProfileService;
use App\Support\Wilayas;
use Illuminate\Support\Facades\DB;
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
        private readonly PortalProfileService $profile,
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
        $items = Product::query()->onWeb()->with('pack')->orderBy('name')->get()->map(fn ($p) => PortalPresenter::product($p))->all();

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

        return ApiResponse::success(PortalPresenter::account($account) + ['onboarding' => $this->profile->onboarding($account), 'request_defaults' => $this->profile->requestDefaults($account)], 'Profile retrieved successfully.');
    }

    /** Profile page: personal details, request history and where things stand. */
    public function profile(Request $request): JsonResponse
    {
        $account = $this->account($request)->load('prospect');
        $this->scheduling->releaseExpired();
        $requests = PortalRequest::query()->with(['product', 'appointment.type', 'appointment.product'])
            ->where('portal_account_id', $account->id)->latest('id')->get();

        return ApiResponse::success([
            'account' => PortalPresenter::account($account),
            'onboarding' => $this->profile->onboarding($account),
            'request_defaults' => $this->profile->requestDefaults($account),
            'status' => [
                'account_type' => $account->prospect?->isCustomer() ? 'customer' : ($account->prospect_id ? 'prospect' : 'visitor'),
                'open_requests' => $requests->whereIn('status', ['pending', 'scheduled'])->count(),
                'latest_request' => $requests->first() ? ['id' => $requests->first()->id, 'type' => $requests->first()->type, 'status' => $requests->first()->status] : null,
                'owned_products' => count($this->ownedProductIds($account)),
            ],
            'requests' => $requests->map(fn ($r) => PortalPresenter::request($r))->all(),
        ], 'Profile retrieved successfully.', 200, ['requests_total' => $requests->count()]);
    }

    /** The 58 wilayas for the request form and the profile address. */
    public function wilayas(): JsonResponse
    {
        return ApiResponse::success(Wilayas::all(), 'Wilayas retrieved successfully.', 200, ['total' => count(Wilayas::all())]);
    }

    /**
     * Product checklist: products of one pack are alternatives (radio group, picking one clears the others),
     * every other product is an independent checkbox. The server enforces the same rule on submit.
     */
    public function checklist(): JsonResponse
    {
        $products = Product::query()->onWeb()->with('pack')->orderBy('name')->get();
        $groups = [];

        foreach ($products->whereNotNull('pack_id')->groupBy('pack_id') as $packId => $members) {
            $ids = $members->pluck('id')->all();
            $pack = $members->first()->pack;
            $groups[] = [
                'type' => 'pack', 'selection' => 'single', 'pack' => ['id' => $pack->id, 'code' => $pack->code, 'name' => $pack->name],
                'products' => $members->map(fn ($p) => PortalPresenter::product($p) + ['exclusive_with' => array_values(array_diff($ids, [$p->id]))])->values()->all(),
            ];
        }

        $groups[] = [
            'type' => 'independent', 'selection' => 'multiple', 'pack' => null,
            'products' => $products->whereNull('pack_id')->map(fn ($p) => PortalPresenter::product($p) + ['exclusive_with' => []])->values()->all(),
        ];

        return ApiResponse::success($groups, 'Checklist retrieved successfully.', 200, ['total' => $products->count()]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if (is_string($request->input('wilaya'))) {
            $request->merge(['wilaya' => Wilayas::canonical($request->input('wilaya'))]);
        }

        $account->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 .\-()\/]{4,28}$/'],
            'locale' => ['sometimes', 'in:ar,en,fr'],
            'company' => ['sometimes', 'nullable', 'string', 'max:150'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'wilaya' => ['sometimes', 'nullable', 'string', 'max:100', new ValidWilaya],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_activity' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]));

        if ($account->wasChanged('locale') && $account->prospect_id) {
            Prospect::query()->whereKey($account->prospect_id)->update(['language' => $account->locale]);
        }

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
        $data = $request->validated();
        $account = $this->account($request);

        if (! isset($data['product_ids'])) {
            return ApiResponse::created(PortalPresenter::request($this->requests->submit($account, $data)), 'Request received.');
        }

        // Several products: one request per product, all-or-nothing.
        // For demos, the appointment slot is booked on the first product and linked to the batch.
        $ids = $data['product_ids'];
        unset($data['product_ids']);
        $created = DB::transaction(function () use ($account, $data, $ids) {
            $appointmentId = null;
            $res = [];
            foreach ($ids as $index => $id) {
                $subData = $data + ['product_id' => $id];
                if ($index > 0 && $appointmentId) {
                    $subData['appointment_id'] = $appointmentId;
                    unset($subData['slot_start']);
                }
                $req = $this->requests->submit($account, $subData);
                if ($index === 0 && $req->appointment_id) {
                    $appointmentId = $req->appointment_id;
                }
                $res[] = PortalPresenter::request($req);
            }

            return $res;
        });

        return ApiResponse::created($created, 'Requests received.');
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
