<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invoice;
use App\Models\Prospect;
use App\Models\RenewalRequest;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/** Shared JSON shapes for the billing module (customer portal and staff API). */
final class BillingPresenter
{
    private static function iso(?Carbon $date): ?string
    {
        return $date?->copy()->setTimezone(config('app.timezone'))->toIso8601String();
    }

    /** Maintenance plan. status: active | expiring_soon | expired | none (no plan set up for this customer yet). */
    public static function maintenance(?Subscription $s): array
    {
        if (! $s) {
            return ['status' => 'none', 'is_active' => false, 'days_remaining' => null, 'starts_at' => null, 'expires_at' => null];
        }

        return [
            'status' => $s->state(),
            'is_active' => $s->isActive(),
            'days_remaining' => $s->daysRemaining(),
            'starts_at' => self::iso($s->starts_at),
            'expires_at' => self::iso($s->expires_at),
        ];
    }

    public static function license(?Subscription $s): array
    {
        return ['lifetime' => true, 'since' => self::iso($s?->starts_at)];
    }

    /** What the customer sees: type is "license" or "maintenance". */
    public static function portalInvoice(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->reference,
            'type' => $i->type === 'maintenance_renewal' ? 'maintenance' : 'license',
            'date' => self::iso($i->issued_at),
            'amount' => $i->amount_dzd,
            'status' => $i->status,
            'paid_at' => self::iso($i->paid_at),
            'has_pdf' => $i->hasPdf(),
        ];
    }

    public static function renewal(RenewalRequest $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'years' => $r->years,
            'workstations_count' => $r->workstations_count,
            'dossiers_count' => $r->dossiers_count,
            'notes' => $r->notes,
            'amount_dzd' => $r->amount_dzd,
            'status' => $r->status,
            'requested_at' => self::iso($r->created_at),
            'quoted_at' => self::iso($r->quoted_at),
            'quoted_by' => $r->quoted_by,
            'confirmed_at' => self::iso($r->confirmed_at),
            'confirmed_by' => $r->confirmed_by,
            'invoice_id' => $r->relationLoaded('invoice') ? $r->invoice?->id : null,
        ];
    }

    /** Staff view of an invoice. */
    public static function staffInvoice(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'prospect_id' => $i->prospect_id,
            'reference' => $i->reference,
            'type' => $i->type,
            'amount_dzd' => $i->amount_dzd,
            'status' => $i->status,
            'issued_at' => self::iso($i->issued_at),
            'paid_at' => self::iso($i->paid_at),
            'renewal_request_id' => $i->renewal_request_id,
            'has_pdf' => $i->hasPdf(),
            'pdf_name' => $i->pdf_name,
            'receipt_url' => $i->hasPdf() ? route('api.v1.billing.invoices.receipt.show', ['invoice' => $i->id], false) : null,
            'created_by' => $i->created_by,
        ];
    }

    public static function customerSummary(Prospect $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'contact_person' => $p->contact_person, 'phone' => $p->phone, 'email' => $p->email,
            'city' => $p->city, 'converted_at' => self::iso($p->converted_at),
        ];
    }
}
