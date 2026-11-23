<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BillingInvoiceRequest;
use App\Http\Requests\Api\V1\BillingMaintenanceRequest;
use App\Http\Requests\Api\V1\BillingMarkPaidRequest;
use App\Http\Requests\Api\V1\BillingQuoteRequest;
use App\Http\Requests\Api\V1\BillingReceiptRequest;
use App\Http\Requests\Api\V1\PaginationRequest;
use App\Http\Resources\Api\V1\BillingPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\Prospect;
use App\Models\RenewalRequest;
use App\Models\Subscription;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff side of billing: who is covered, pending cash renewals, invoices and scanned receipts. */
class BillingStaffController extends Controller
{
    private const SORTS = ['name' => 'prospects.name', 'expires_at' => 'm.expires_at', 'converted_at' => 'prospects.converted_at'];

    public function __construct(private readonly BillingService $billing) {}

    /**
     * Customers with their maintenance status.
     * Filters: status (active|expiring_soon|expired|none), search, has_pending_renewal. Sort: name|expires_at|converted_at.
     */
    public function subscriptions(PaginationRequest $request): JsonResponse
    {
        $now = now()->format('Y-m-d H:i:s');
        $soon = now()->addDays(Subscription::EXPIRING_SOON_DAYS)->startOfDay()->format('Y-m-d H:i:s');
        $search = trim((string) $request->query('search', ''));
        $sort = self::SORTS[$request->query('sort', 'name')] ?? self::SORTS['name'];
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $query = Prospect::query()
            ->leftJoin('subscriptions as m', fn ($j) => $j->on('m.prospect_id', '=', 'prospects.id')->where('m.type', Subscription::MAINTENANCE))
            ->select('prospects.*')
            ->whereNotNull('prospects.converted_at')
            ->with(['subscriptions'])
            ->withCount(['renewalRequests as pending_renewals_count' => fn ($q) => $q->whereIn('status', RenewalRequest::OPEN)])
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($w) => $w->where('prospects.name', 'like', "%{$search}%")->orWhere('prospects.contact_person', 'like', "%{$search}%")->orWhere('prospects.phone', 'like', "%{$search}%")))
            ->when($request->boolean('has_pending_renewal'), fn (Builder $q) => $q->whereHas('renewalRequests', fn ($r) => $r->whereIn('status', RenewalRequest::OPEN)))
            ->when($request->query('status'), fn (Builder $q, $status) => match ($status) {
                'none' => $q->whereNull('m.id'),
                'expired' => $q->whereNotNull('m.id')->where(fn ($w) => $w->where('m.status', 'expired')->orWhere('m.expires_at', '<', $now)),
                'expiring_soon' => $q->where('m.status', 'active')->where('m.expires_at', '>=', $now)->where('m.expires_at', '<', $soon),
                'active' => $q->where('m.status', 'active')->where('m.expires_at', '>=', $soon),
                default => $q,
            })
            ->orderByRaw('m.expires_at is null')   // customers without a plan always last
            ->orderBy($sort, $direction)->orderBy('prospects.id');

        $paginator = $query->paginate(min((int) $request->query('per_page', config('crm.pagination.default_per_page')), config('crm.pagination.max_per_page')));

        return ApiResponse::success(
            $paginator->getCollection()->map(fn (Prospect $p) => BillingPresenter::customerSummary($p) + [
                'maintenance' => BillingPresenter::maintenance($p->subscriptions->firstWhere('type', Subscription::MAINTENANCE)),
                'pending_renewals_count' => (int) $p->pending_renewals_count,
            ])->values()->all(),
            'Subscriptions retrieved successfully.',
            200,
            ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()],
        );
    }

    /** One customer: license, maintenance, renewal requests and invoice history. */
    public function show(Prospect $prospect): JsonResponse
    {
        return ApiResponse::success($this->detail($this->customer($prospect)), 'Customer billing retrieved successfully.');
    }

    public function setMaintenance(BillingMaintenanceRequest $request, Prospect $prospect): JsonResponse
    {
        $this->billing->setMaintenance($this->customer($prospect), Carbon::parse($request->validated('starts_at'), config('app.timezone')), Carbon::parse($request->validated('expires_at'), config('app.timezone')));

        return ApiResponse::success($this->detail($prospect), 'Maintenance period saved.');
    }

    public function storeInvoice(BillingInvoiceRequest $request, Prospect $prospect): JsonResponse
    {
        $invoice = $this->billing->createInvoice($this->customer($prospect), $request->validated(), $this->actor($request));

        return ApiResponse::created(BillingPresenter::staffInvoice($invoice), 'Invoice created.');
    }

    /** Staff price a request ("Issue proforma"): status quoted, proforma reference generated, pending cash invoice created. */
    public function quoteRenewal(BillingQuoteRequest $request, RenewalRequest $renewalRequest): JsonResponse
    {
        $this->billing->quoteRenewal($renewalRequest, (int) $request->validated('amount_dzd'), $this->actor($request));

        return ApiResponse::success($this->detail(Prospect::query()->findOrFail($renewalRequest->prospect_id)), 'Proforma issued.');
    }

    /** Confirms the cash of a quoted request: invoice -> paid_cash and the maintenance period is extended to a December 31st. */
    public function markRenewalPaid(BillingMarkPaidRequest $request, RenewalRequest $renewalRequest): JsonResponse
    {
        $this->billing->confirmRenewal($renewalRequest, $this->paidAt($request), $this->actor($request));

        return ApiResponse::success($this->detail(Prospect::query()->findOrFail($renewalRequest->prospect_id)), 'Renewal confirmed: maintenance extended.');
    }

    public function markInvoicePaid(BillingMarkPaidRequest $request, Invoice $invoice): JsonResponse
    {
        $this->billing->markInvoicePaid($invoice, $this->paidAt($request), $this->actor($request));

        return ApiResponse::success(BillingPresenter::staffInvoice($invoice->refresh()), 'Invoice marked as paid.');
    }

    public function uploadReceipt(BillingReceiptRequest $request, Invoice $invoice): JsonResponse
    {
        return ApiResponse::success(BillingPresenter::staffInvoice($this->billing->attachReceipt($invoice, $request->file('receipt'))), 'Receipt uploaded.');
    }

    public function removeReceipt(Invoice $invoice): JsonResponse
    {
        return ApiResponse::success(BillingPresenter::staffInvoice($this->billing->removeReceipt($invoice)), 'Receipt removed.');
    }

    public function showReceipt(Invoice $invoice): StreamedResponse
    {
        $disk = $this->billing->disk();
        abort_unless($invoice->hasPdf() && Storage::disk($disk)->exists($invoice->pdf_path), 404);

        return Storage::disk($disk)->download($invoice->pdf_path, $invoice->pdf_name ?: $invoice->reference.'.pdf', ['X-Content-Type-Options' => 'nosniff']);
    }

    private function customer(Prospect $prospect): Prospect
    {
        abort_unless($prospect->converted_at !== null, 404);

        return $prospect;
    }

    private function paidAt(Request $request): Carbon
    {
        $value = $request->input('paid_at');

        return $value ? Carbon::parse($value, config('app.timezone')) : now();
    }

    /** @return array<string, mixed> */
    private function detail(Prospect $prospect): array
    {
        $prospect = $this->customer($prospect);
        $license = $this->billing->lifetime($prospect);

        return [
            'customer' => BillingPresenter::customerSummary($prospect),
            'license' => BillingPresenter::license($license),
            'maintenance' => BillingPresenter::maintenance($this->billing->maintenance($prospect->id)),
            'renewal_requests' => RenewalRequest::query()->with('invoice')->where('prospect_id', $prospect->id)->orderByDesc('id')->get()
                ->map(fn ($r) => BillingPresenter::renewal($r))->all(),
            'invoices' => Invoice::query()->where('prospect_id', $prospect->id)->orderByDesc('issued_at')->orderByDesc('id')->get()
                ->map(fn ($i) => BillingPresenter::staffInvoice($i))->all(),
        ];
    }
}
