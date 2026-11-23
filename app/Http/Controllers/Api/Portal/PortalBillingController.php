<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PortalRenewRequest;
use App\Http\Resources\Api\V1\BillingPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Models\PortalAccount;
use App\Models\RenewalRequest;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Subscription, renewal slips and invoices of the signed-in customer. Cash only. */
class PortalBillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    /** Billing belongs to the customer company, so the account must be linked to a converted customer. */
    private function prospectId(Request $request): ?int
    {
        /** @var PortalAccount $account */
        $account = $request->attributes->get('portal_account');
        $account->loadMissing('prospect');

        return $account->prospect?->converted_at ? (int) $account->prospect_id : null;
    }

    private function notCustomer(): JsonResponse
    {
        return ApiResponse::error('Billing is available to customers only.', 403, 'CUSTOMER_REQUIRED');
    }

    public function index(Request $request): JsonResponse
    {
        if (! ($prospectId = $this->prospectId($request))) {
            return $this->notCustomer();
        }

        $account = $request->attributes->get('portal_account');
        $license = $this->billing->lifetime($account->prospect);
        $pending = RenewalRequest::query()->with('invoice')->where('prospect_id', $prospectId)->whereIn('status', RenewalRequest::OPEN)->latest('id')->first();
        $invoices = Invoice::query()->where('prospect_id', $prospectId)->orderByDesc('issued_at')->orderByDesc('id')->limit(200)->get();

        return ApiResponse::success([
            'license' => BillingPresenter::license($license),
            'subscription' => BillingPresenter::maintenance($this->billing->maintenance($prospectId)),
            'currency' => 'DZD',
            'pending_renewal' => $pending ? BillingPresenter::renewal($pending) : null,
            'invoices' => $invoices->map(fn ($i) => BillingPresenter::portalInvoice($i))->all(),
        ], 'Billing retrieved successfully.');
    }

    public function renew(PortalRenewRequest $request): JsonResponse
    {
        if (! $this->prospectId($request)) {
            return $this->notCustomer();
        }

        $renewal = $this->billing->requestRenewal(
            $request->attributes->get('portal_account'),
            (int) $request->validated('years'),
            (int) $request->validated('workstations_count'),
            (int) $request->validated('dossiers_count'),
            $request->validated('notes'),
        );

        return ApiResponse::created(BillingPresenter::renewal($renewal), 'Quote request sent. Softel will price it; you can then pay in cash.');
    }

    /** Streams the invoice PDF; 404 until staff have uploaded one. */
    public function invoice(Request $request, int $id): StreamedResponse
    {
        abort_unless($prospectId = $this->prospectId($request), 403);

        $invoice = Invoice::query()->where('prospect_id', $prospectId)->findOrFail($id);
        $disk = $this->billing->disk();
        abort_unless($invoice->hasPdf() && Storage::disk($disk)->exists($invoice->pdf_path), 404);

        return Storage::disk($disk)->download($invoice->pdf_path, $invoice->pdf_name ?: $invoice->reference.'.pdf', ['X-Content-Type-Options' => 'nosniff']);
    }
}
