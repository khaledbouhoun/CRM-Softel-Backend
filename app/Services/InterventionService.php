<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Intervention;
use App\Models\InterventionMessage;
use App\Models\InterventionStatus;
use App\Models\InterventionStatusTransition;
use App\Models\InterventionType;
use App\Models\PortalAccount;
use App\Models\Prospect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After-sales interventions. Types, statuses, transitions and priorities are rows
 * (configurable); this service only enforces the rules that are always true:
 * ownership, source/visibility separation and "transitions must be configured".
 */
class InterventionService
{
    /**
     * @param  array{intervention_type_id: int, product_id: int, title: string, description: string}  $data
     */
    public function createFromCustomer(PortalAccount $account, array $data): Intervention
    {
        $prospect = $this->customerOf($account);
        $type = $this->activeType((int) $data['intervention_type_id'], ['customer', 'both']);

        $owns = $prospect->products()->where('products.id', $data['product_id'])->whereNotNull('prospect_products.purchased_at')->exists();
        if (! $owns) {
            throw new BusinessRuleException('You can only report an issue for a product linked to your account.', 'PRODUCT_NOT_OWNED', ['product_id' => ['This product is not linked to your account.']], 403);
        }

        return DB::transaction(function () use ($account, $prospect, $type, $data) {
            $intervention = Intervention::query()->create([
                'reference' => $this->reference(), 'source' => 'customer', 'intervention_type_id' => $type->id,
                'intervention_status_id' => $this->initialStatus()->id, 'prospect_id' => $prospect->id,
                'portal_account_id' => $account->id, 'product_id' => $data['product_id'],
                'title' => $data['title'], 'description' => $data['description'],
            ]);
            $intervention->customers()->attach($prospect->id);

            return $intervention;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromEmployee(array $data, ?string $by): Intervention
    {
        $type = $this->activeType((int) $data['intervention_type_id'], ['employee', 'both']);
        $customerIds = array_values(array_unique(array_map('intval', $data['customer_ids'] ?? [])));

        return DB::transaction(function () use ($data, $type, $customerIds, $by) {
            $intervention = Intervention::query()->create([
                'reference' => $this->reference(), 'source' => 'employee', 'intervention_type_id' => $type->id,
                'intervention_status_id' => $this->initialStatus()->id,
                'intervention_priority_id' => $data['intervention_priority_id'] ?? null,
                'prospect_id' => count($customerIds) === 1 ? $customerIds[0] : null,
                'product_id' => $data['product_id'] ?? null, 'title' => $data['title'], 'description' => $data['description'],
                'assigned_employee' => $data['assigned_employee'] ?? $by, 'scheduled_at' => $data['scheduled_at'] ?? null,
            ]);
            $intervention->customers()->sync($customerIds);

            return $intervention;
        });
    }

    /** Moves along a configured transition. Customers may only use customer_allowed ones. */
    public function transition(Intervention $intervention, int $toStatusId, string $actor, ?string $actorName = null, ?string $customerMessage = null): Intervention
    {
        return DB::transaction(function () use ($intervention, $toStatusId, $actor, $actorName, $customerMessage) {
            $locked = Intervention::query()->lockForUpdate()->findOrFail($intervention->id);
            $target = InterventionStatus::query()->findOrFail($toStatusId);

            $rule = InterventionStatusTransition::query()->where('is_active', true)
                ->where('from_status_id', $locked->intervention_status_id)->where('to_status_id', $target->id)->first();

            if (! $rule || ! $target->is_active || ($actor === 'customer' && ! $rule->customer_allowed)) {
                throw new BusinessRuleException('This status change is not allowed.', 'INVALID_STATUS_TRANSITION', ['status' => ['This status change is not allowed.']], 422);
            }

            $locked->update([
                'intervention_status_id' => $target->id,
                'closed_at' => $target->is_closed ? now() : null,
            ]);

            InterventionMessage::query()->create([
                'intervention_id' => $locked->id, 'author_type' => $actor, 'author_name' => $actorName,
                'visibility' => 'customer', 'body' => $customerMessage ?? '', 'status_id' => $target->id,
            ]);

            return $locked;
        });
    }

    /** @param 'customer'|'internal' $visibility */
    public function addMessage(Intervention $intervention, string $authorType, ?string $authorName, string $visibility, string $body): InterventionMessage
    {
        return DB::transaction(function () use ($intervention, $authorType, $authorName, $visibility, $body) {
            $message = InterventionMessage::query()->create([
                'intervention_id' => $intervention->id, 'author_type' => $authorType, 'author_name' => $authorName,
                'visibility' => $visibility, 'body' => $body,
            ]);

            // A customer answering a request for information moves it on through the configured customer transition.
            if ($authorType === 'customer') {
                $current = InterventionStatus::query()->find($intervention->intervention_status_id);
                if ($current?->awaits_customer) {
                    $rule = InterventionStatusTransition::query()->where('is_active', true)->where('customer_allowed', true)
                        ->where('from_status_id', $current->id)->first();
                    if ($rule) {
                        $this->transition($intervention, $rule->to_status_id, 'customer', $authorName);
                    }
                }
            }

            return $message;
        });
    }

    public function publish(Intervention $intervention, string $customerResolution): Intervention
    {
        if ($intervention->source !== 'employee') {
            throw new BusinessRuleException('Only employee-originated work is published as an update.', 'NOT_PUBLISHABLE', [], 422);
        }

        $intervention->update(['is_published' => true, 'published_at' => now(), 'customer_resolution' => $customerResolution]);

        return $intervention;
    }

    public function unpublish(Intervention $intervention): Intervention
    {
        $intervention->update(['is_published' => false]);

        return $intervention;
    }

    private function customerOf(PortalAccount $account): Prospect
    {
        if (! $account->prospect_id) {
            throw new BusinessRuleException('Your account has no customer record yet.', 'NO_CUSTOMER', [], 403);
        }

        return Prospect::query()->findOrFail($account->prospect_id);
    }

    /** @param list<string> $sources */
    private function activeType(int $id, array $sources): InterventionType
    {
        $type = InterventionType::query()->find($id);

        if (! $type || ! $type->is_active || ! in_array($type->source, $sources, true)) {
            throw new BusinessRuleException('This intervention type is not available.', 'TYPE_UNAVAILABLE', ['intervention_type_id' => ['This type is not available.']], 422);
        }

        return $type;
    }

    private function initialStatus(): InterventionStatus
    {
        return InterventionStatus::query()->where('is_initial', true)->where('is_active', true)->orderBy('sort_order')->first()
            ?? throw new BusinessRuleException('No initial intervention status is configured.', 'NO_INITIAL_STATUS', [], 422);
    }

    private function reference(): string
    {
        do {
            $reference = 'INT-'.strtoupper(Str::random(8));
        } while (Intervention::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
