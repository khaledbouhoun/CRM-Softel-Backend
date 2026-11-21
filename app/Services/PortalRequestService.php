<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A signed-in visitor asks for a demo, quotation or trial. */
class PortalRequestService
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly AppointmentService $appointments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated form data (+ optional slot_start)
     */
    public function submit(PortalAccount $account, array $data): PortalRequest
    {
        $product = Product::query()->find($data['product_id']);

        if (! $product || ! $product->is_active) {
            throw new BusinessRuleException('This product is not available.', 'PRODUCT_UNAVAILABLE', ['product_id' => ['This product is not available.']], 422);
        }

        if ($data['type'] === 'demo' && empty($data['slot_start']) && empty($data['appointment_id'])) {
            throw new BusinessRuleException('Please choose a demo time.', 'SLOT_REQUIRED', ['slot_start' => ['Please choose a demo time.']], 422);
        }

        return DB::transaction(function () use ($account, $data, $product) {
            $duplicate = PortalRequest::query()->where('portal_account_id', $account->id)
                ->where('product_id', $product->id)->where('type', $data['type'])
                ->whereIn('status', ['pending', 'scheduled'])->first();

            if ($duplicate) {
                throw new BusinessRuleException('You already have an open request of this type for this product.', 'DUPLICATE_REQUEST', ['request_id' => [(string) $duplicate->id]], 409);
            }

            $prospect = $this->prospectFor($account, $data, $product);

            $request = PortalRequest::query()->create([
                'portal_account_id' => $account->id, 'prospect_id' => $prospect->id, 'product_id' => $product->id,
                'type' => $data['type'], 'status' => 'pending', 'company' => $data['company'],
                'contact_name' => $data['contact_name'], 'job_title' => $data['job_title'] ?? null,
                'phone' => $data['phone'], 'email' => $data['email'], 'wilaya' => $data['wilaya'], 'city' => $data['city'],
                'business_activity' => $data['business_activity'] ?? null, 'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? 'website',
            ]);

            app(PortalProfileService::class)->remember($account, $data);

            if (! empty($data['appointment_id'])) {
                $request->update(['appointment_id' => (int) $data['appointment_id']]);
            } elseif (! empty($data['slot_start'])) {
                $appointment = $this->appointments->hold($account, $prospect, $product, Carbon::parse($data['slot_start'], config('app.timezone')), $data['notes'] ?? null);
                $request->update(['appointment_id' => $appointment->id]);
            }

            return $request->load(['product', 'appointment.type']);
        });
    }

    /**
     * Same account => same prospect. An unverified e-mail never links an account to an
     * existing prospect (that would expose someone else's record): a possible duplicate
     * is only flagged in the notes for staff review.
     *
     * @param  array<string, mixed>  $data
     */
    private function prospectFor(PortalAccount $account, array $data, Product $product): Prospect
    {
        if ($account->prospect_id) {
            $prospect = Prospect::query()->findOrFail($account->prospect_id);

            if (! $prospect->products()->where('products.id', $product->id)->exists()) {
                $prospect->products()->attach($product->id);
            }

            return $prospect;
        }

        $similar = Prospect::query()->where(function ($q) use ($data) {
            $q->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->orWhere('phone', $data['phone']);
        })->first();

        $notes = trim(sprintf(
            '[Website %s request] %s%s',
            $data['type'],
            $data['notes'] ?? '',
            $similar ? "\nPossible duplicate of prospect #{$similar->id}: please review before merging." : '',
        ));

        $prospect = $this->prospects->create([
            'name' => $data['company'], 'contact_person' => $data['contact_name'], 'phone' => $data['phone'],
            'email' => $data['email'], 'address' => $data['wilaya'], 'city' => $data['city'],
            'business_activity' => $data['business_activity'] ?? null, 'profession' => $data['job_title'] ?? null,
            'source' => 'website', 'notes' => $notes, 'product_ids' => [$product->id], 'language' => in_array($account->locale, ['ar', 'fr', 'en'], true) ? $account->locale : 'fr',
        ]);

        $account->update(['prospect_id' => $prospect->id]);

        return $prospect;
    }

    public function cancel(PortalRequest $request): PortalRequest
    {
        return DB::transaction(function () use ($request) {
            if (! in_array($request->status, ['pending', 'scheduled'], true)) {
                throw new BusinessRuleException('This request can no longer be cancelled.', 'INVALID_REQUEST_STATE', [], 422);
            }

            if ($request->appointment_id) {
                $appointment = Appointment::query()->findOrFail($request->appointment_id);
                if (in_array($appointment->status, Appointment::BLOCKING, true)) {
                    $this->appointments->cancel($appointment, null, 'customer');
                }
            }

            $request->update(['status' => 'cancelled']);

            return $request;
        });
    }
}
