<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\Trial;
use Illuminate\Support\Carbon;

class TrialService
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    public function activate(Prospect $prospect, Product $product, ?Carbon $start, ?Carbon $end, ?string $by, ?string $notes = null): Trial
    {
        if (blank($product->demo_url)) {
            throw new BusinessRuleException('This product has no demo link configured.', 'DEMO_URL_MISSING', ['product_id' => ['This product has no demo link configured.']], 422);
        }

        if (! $prospect->products()->where('products.id', $product->id)->exists()) {
            throw new BusinessRuleException('The prospect is not interested in this product.', 'PRODUCT_NOT_OF_INTEREST', ['product_id' => ['The prospect is not interested in this product.']], 422);
        }

        $start ??= now();
        $end ??= $start->copy()->addDays((int) $this->scheduling->setting('trial_default_days'));

        if ($end->lte($start)) {
            throw new BusinessRuleException('The trial must end after it starts.', 'INVALID_TIME_RANGE', ['ends_at' => ['The trial must end after it starts.']]);
        }

        $overlap = Trial::query()->where('prospect_id', $prospect->id)->where('product_id', $product->id)
            ->whereNull('revoked_at')->where('ends_at', '>', $start->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $end->format('Y-m-d H:i:s'))->exists();

        if ($overlap) {
            throw new BusinessRuleException('This customer already has a trial for this product in that period.', 'TRIAL_OVERLAP', [], 409);
        }

        $trial = Trial::query()->create([
            'prospect_id' => $prospect->id, 'product_id' => $product->id, 'url' => $product->demo_url,
            'starts_at' => $start, 'ends_at' => $end, 'created_by' => $by, 'internal_notes' => $notes,
        ]);

        PortalRequest::query()->where('prospect_id', $prospect->id)->where('product_id', $product->id)
            ->where('type', 'trial')->whereIn('status', ['pending', 'scheduled'])->update(['status' => 'handled']);

        return $trial;
    }

    public function revoke(Trial $trial): Trial
    {
        if ($trial->revoked_at === null) {
            $trial->update(['revoked_at' => now()]);
        }

        return $trial;
    }
}
