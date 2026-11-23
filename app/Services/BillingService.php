<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PortalAccount;
use App\Models\Prospect;
use App\Models\RenewalRequest;
use App\Models\Subscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cash-only billing rules:
 *  - a lifetime license never expires; only the maintenance & support plan has an expiry date;
 *  - maintenance always ends on a December 31st (free first period: before April 1st -> this year, else next year);
 *  - a customer asks for a renewal quote (years, workstations, dossiers); staff price it (proforma), then mark it paid
 *    once the cash is received, and only then is the maintenance period extended by whole calendar years.
 */
class BillingService
{
    public function disk(): ?string
    {
        return config('crm.uploads.disk');
    }

    public function maintenance(int $prospectId): ?Subscription
    {
        return Subscription::query()->where('prospect_id', $prospectId)->where('type', Subscription::MAINTENANCE)->first();
    }

    public function lifetime(Prospect $prospect): Subscription
    {
        return Subscription::query()->firstOrCreate(
            ['prospect_id' => $prospect->id, 'type' => Subscription::LIFETIME],
            ['status' => 'active', 'starts_at' => $prospect->converted_at ?? now(), 'expires_at' => null],
        );
    }

    /**
     * First free maintenance period of a new license: it always ends on December 31st.
     * Bought before April 1st -> the same year; on or after April 1st -> the following year.
     */
    public function calculateFirstFreeExpiry(Carbon $purchaseDate): Carbon
    {
        $cutoff = Carbon::create($purchaseDate->year, 4, 1, 0, 0, 0, $purchaseDate->getTimezone());

        return $purchaseDate->lt($cutoff)
            ? $purchaseDate->copy()->endOfYear()
            : $purchaseDate->copy()->addYear()->endOfYear();
    }

    /** Gives a freshly converted customer the free maintenance that comes with the license (never overwrites an existing plan). */
    public function startFreeMaintenance(Prospect $prospect, Carbon $purchaseDate): Subscription
    {
        return Subscription::query()->firstOrCreate(
            ['prospect_id' => $prospect->id, 'type' => Subscription::MAINTENANCE],
            ['status' => 'active', 'starts_at' => $purchaseDate, 'expires_at' => $this->calculateFirstFreeExpiry($purchaseDate)],
        );
    }

    /** The maintenance end date after paying for $years: always a December 31st. */
    public function extendedExpiry(?Subscription $current, Carbon $paidAt, int $years): Carbon
    {
        $base = $current && $current->isActive() && $current->expires_at
            ? $current->expires_at->copy()
            : Carbon::create($paidAt->year - 1, 12, 31, 0, 0, 0, $paidAt->getTimezone());   // lapsed: the renewal covers the current year onwards

        return $base->addYears($years)->endOfYear();
    }

    /**
     * Customer-facing quote request: years, workstations (PCs) and dossiers (databases). Staff price it later.
     * One open request per customer: asking again updates it and sends it back to "pending_quote".
     */
    public function requestRenewal(PortalAccount $account, int $years, int $workstations, int $dossiers, ?string $notes): RenewalRequest
    {
        return DB::transaction(function () use ($account, $years, $workstations, $dossiers, $notes) {
            $details = ['years' => $years, 'workstations_count' => $workstations, 'dossiers_count' => $dossiers, 'notes' => $notes, 'portal_account_id' => $account->id];

            $request = RenewalRequest::query()->where('prospect_id', $account->prospect_id)->whereIn('status', RenewalRequest::OPEN)->lockForUpdate()->first();

            if ($request) {
                $request->update($details + ['status' => RenewalRequest::PENDING_QUOTE, 'amount_dzd' => null, 'quoted_at' => null, 'quoted_by' => null]);
                $request->invoice?->delete();   // the previous proforma no longer matches the request

                return $request->load('invoice');
            }

            return RenewalRequest::query()->create($details + ['prospect_id' => $account->prospect_id, 'status' => RenewalRequest::PENDING_QUOTE])->load('invoice');
        });
    }

    /** Staff price a request: it becomes "quoted", gets its proforma reference and a pending cash invoice. */
    public function quoteRenewal(RenewalRequest $request, int $amountDzd, ?string $by): RenewalRequest
    {
        return DB::transaction(function () use ($request, $amountDzd, $by) {
            $request = RenewalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->status, RenewalRequest::OPEN, true)) {
                throw ValidationException::withMessages(['renewal_request' => 'This renewal request is already closed.']);
            }

            if ($request->reference === null) {
                $request = $this->withUniqueReference('PF', 'renewal_requests', function (string $ref) use ($request) {
                    $request->update(['reference' => $ref]);

                    return $request;
                });
            }

            $request->update(['status' => RenewalRequest::QUOTED, 'amount_dzd' => $amountDzd, 'quoted_at' => now(), 'quoted_by' => $by]);

            $invoice = $request->invoice()->first();
            if ($invoice) {
                $invoice->update(['amount_dzd' => $amountDzd]);
            } else {
                $this->withUniqueReference('SOF', 'invoices', fn (string $ref) => Invoice::query()->create([
                    'prospect_id' => $request->prospect_id, 'renewal_request_id' => $request->id, 'reference' => $ref,
                    'type' => 'maintenance_renewal', 'amount_dzd' => $amountDzd, 'status' => Invoice::PENDING_CASH, 'issued_at' => now(), 'created_by' => $by,
                ]));
            }

            return $request->refresh()->load('invoice');
        });
    }

    /** Staff confirm the cash payment of a quoted request: invoice paid_cash and maintenance extended to a December 31st. */
    public function confirmRenewal(RenewalRequest $request, Carbon $paidAt, ?string $by): RenewalRequest
    {
        return DB::transaction(function () use ($request, $paidAt, $by) {
            $request = RenewalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status === RenewalRequest::PAID) {
                throw ValidationException::withMessages(['renewal_request' => 'This renewal request has already been confirmed.']);
            }
            if ($request->status !== RenewalRequest::QUOTED) {
                throw ValidationException::withMessages(['renewal_request' => 'Quote a price before marking this request as paid.']);
            }

            $request->update(['status' => RenewalRequest::PAID, 'confirmed_at' => $paidAt, 'confirmed_by' => $by]);
            $request->invoice?->update(['status' => Invoice::PAID_CASH, 'paid_at' => $paidAt]);

            $sub = $this->maintenance($request->prospect_id);
            $active = $sub && $sub->isActive();

            Subscription::query()->updateOrCreate(
                ['prospect_id' => $request->prospect_id, 'type' => Subscription::MAINTENANCE],
                ['status' => 'active', 'starts_at' => $active ? $sub->starts_at : $paidAt, 'expires_at' => $this->extendedExpiry($sub, $paidAt, $request->years)],
            );

            return $request->refresh()->load('invoice');
        });
    }

    /** Staff set the maintenance period directly (first setup, corrections). */
    public function setMaintenance(Prospect $prospect, Carbon $startsAt, Carbon $expiresAt): Subscription
    {
        return Subscription::query()->updateOrCreate(
            ['prospect_id' => $prospect->id, 'type' => Subscription::MAINTENANCE],
            ['starts_at' => $startsAt, 'expires_at' => $expiresAt, 'status' => $expiresAt->gte(now()) ? 'active' : 'expired'],
        );
    }

    /** @param array{type: string, amount_dzd: int, status?: string, paid_at?: ?string} $data */
    public function createInvoice(Prospect $prospect, array $data, ?string $by): Invoice
    {
        $paid = ($data['status'] ?? Invoice::PENDING_CASH) === Invoice::PAID_CASH;

        return $this->withUniqueReference('SOF', 'invoices', fn (string $ref) => Invoice::query()->create([
            'prospect_id' => $prospect->id, 'reference' => $ref, 'type' => $data['type'], 'amount_dzd' => (int) $data['amount_dzd'],
            'status' => $paid ? Invoice::PAID_CASH : Invoice::PENDING_CASH, 'issued_at' => now(),
            'paid_at' => $paid ? ($data['paid_at'] ?? now()) : null, 'created_by' => $by,
        ]));
    }

    /** A renewal invoice is confirmed through its request (it must also extend the subscription). */
    public function markInvoicePaid(Invoice $invoice, Carbon $paidAt, ?string $by): Invoice
    {
        if ($invoice->status === Invoice::PAID_CASH) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already paid.']);
        }

        if ($invoice->renewalRequest && in_array($invoice->renewalRequest->status, RenewalRequest::OPEN, true)) {
            $this->confirmRenewal($invoice->renewalRequest, $paidAt, $by);
        } else {
            $invoice->update(['status' => Invoice::PAID_CASH, 'paid_at' => $paidAt]);
        }

        return $invoice->refresh();
    }

    public function attachReceipt(Invoice $invoice, UploadedFile $file): Invoice
    {
        $old = $invoice->pdf_path;
        $path = $file->store('invoices/'.$invoice->prospect_id, ['disk' => $this->disk()]);
        $invoice->update(['pdf_path' => $path, 'pdf_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'pdf_mime' => (string) $file->getMimeType()]);

        if ($old) {
            Storage::disk($this->disk())->delete($old);
        }

        return $invoice;
    }

    public function removeReceipt(Invoice $invoice): Invoice
    {
        if ($invoice->pdf_path) {
            Storage::disk($this->disk())->delete($invoice->pdf_path);
        }
        $invoice->update(['pdf_path' => null, 'pdf_name' => null, 'pdf_mime' => null]);

        return $invoice;
    }

    /** Flags maintenance plans whose date has passed (state is also computed live, so this is just bookkeeping). */
    public function syncExpired(): int
    {
        return Subscription::query()->where('type', Subscription::MAINTENANCE)->where('status', 'active')
            ->where('expires_at', '<', now()->format('Y-m-d H:i:s'))->update(['status' => 'expired']);
    }

    /**
     * Sequential per-year reference ("SOF-2026-0042"); a unique-index clash with a concurrent request just takes the next number.
     *
     * @template T
     *
     * @param  callable(string): T  $create
     * @return T
     */
    private function withUniqueReference(string $prefix, string $table, callable $create): mixed
    {
        $year = now()->format('Y');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = DB::table($table)->where('reference', 'like', "{$prefix}-{$year}-%")->orderByDesc('id')->value('reference');
            $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;
            $reference = sprintf('%s-%s-%04d', $prefix, $year, $next + $attempt);

            try {
                return $create($reference);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('Could not allocate a unique reference.');
    }
}
