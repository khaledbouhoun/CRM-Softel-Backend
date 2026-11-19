<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Intervention;
use App\Models\InterventionAttachment;
use App\Models\InterventionMessage;
use App\Models\InterventionStatus;
use App\Models\InterventionStatusTransition;
use App\Models\InterventionType;
use App\Models\PortalAccount;
use App\Models\Prospect;
use Illuminate\Http\UploadedFile;
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
    public function transition(Intervention $intervention, int $toStatusId, string $actor, ?string $actorName = null, ?string $customerMessage = null, ?int $accountId = null): Intervention
    {
        return DB::transaction(function () use ($intervention, $toStatusId, $actor, $actorName, $customerMessage, $accountId) {
            $locked = Intervention::query()->lockForUpdate()->findOrFail($intervention->id);
            $target = InterventionStatus::query()->findOrFail($toStatusId);

            if ($actor === 'customer' && InterventionStatus::query()->whereKey($locked->intervention_status_id)->value('is_closed')) {
                throw new BusinessRuleException('This intervention is closed. Only an employee can reopen it.', 'INTERVENTION_CLOSED', [], 422);
            }

            $rule = InterventionStatusTransition::query()->where('is_active', true)
                ->where('from_status_id', $locked->intervention_status_id)->where('to_status_id', $target->id)->first();

            if (! $rule || ! $target->is_active || ($actor === 'customer' && ! $rule->customer_allowed)) {
                throw new BusinessRuleException('This status change is not allowed.', 'INVALID_STATUS_TRANSITION', ['status' => ['This status change is not allowed.']], 422);
            }

            $fromStatusId = $locked->intervention_status_id;

            $locked->update([
                'intervention_status_id' => $target->id,
                'closed_at' => $target->is_closed ? now() : null,
            ]);

            $this->recordStatusEvent($locked, InterventionMessage::KIND_STATUS_CHANGE, $actor, $actorName, $fromStatusId, $target->id, $customerMessage, $actor === 'customer' ? $accountId : null);

            return $locked;
        });
    }

    /**
     * Replies are refused once the intervention reached a closing status, unless they are internal notes.
     */
    public function assertAcceptsReplies(Intervention $intervention, string $authorType, string $visibility = 'customer'): void
    {
        $intervention->loadMissing('status');

        if ($intervention->isClosed() && ($authorType === 'customer' || $visibility === 'customer')) {
            throw new BusinessRuleException('This intervention is closed. New replies are disabled.', 'INTERVENTION_CLOSED', [], 422);
        }
    }

    /**
     * @param  'customer'|'internal'  $visibility
     * @param  list<UploadedFile>  $files  stored privately and linked to the reply
     */
    public function addMessage(Intervention $intervention, string $authorType, ?string $authorName, string $visibility, string $body, array $files = [], ?int $accountId = null): InterventionMessage
    {
        $this->assertAcceptsReplies($intervention, $authorType, $visibility);

        return DB::transaction(function () use ($intervention, $authorType, $authorName, $visibility, $body, $files, $accountId) {
            $message = InterventionMessage::query()->create([
                'intervention_id' => $intervention->id, 'author_type' => $authorType, 'author_name' => $authorName,
                'portal_account_id' => $authorType === 'customer' ? $accountId : null,
                'visibility' => $visibility, 'body' => $body,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($intervention, $file, $visibility, $authorType, $authorName, $message->id);
            }

            // A customer answering a request for information moves it on through the configured customer transition.
            if ($authorType === 'customer') {
                $current = InterventionStatus::query()->find($intervention->intervention_status_id);
                if ($current?->awaits_customer) {
                    $rule = InterventionStatusTransition::query()->where('is_active', true)->where('customer_allowed', true)
                        ->where('from_status_id', $current->id)->first();
                    if ($rule) {
                        $this->transition($intervention, $rule->to_status_id, 'customer', $authorName, null, $accountId);
                    }
                }
            }

            return $message;
        });
    }

    /** Stores one uploaded file on the configured private disk and records it. */
    public function storeAttachment(Intervention $intervention, UploadedFile $file, string $visibility, string $uploaderType, ?string $uploaderName, ?int $messageId = null): InterventionAttachment
    {
        $path = $file->store('intervention-attachments/'.$intervention->id, ['disk' => $this->disk()]);

        return InterventionAttachment::query()->create([
            'intervention_id' => $intervention->id, 'message_id' => $messageId, 'visibility' => $visibility,
            'uploaded_by_type' => $uploaderType, 'uploaded_by_name' => $uploaderName, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize(),
        ]);
    }

    public function disk(): ?string
    {
        return config('crm.uploads.disk');
    }

    /**
     * Employees reopen a closed intervention. It goes back to the status it had before it was closed
     * (or the initial status), or to $statusId when given; it must be an open, active status.
     */
    public function reopen(Intervention $intervention, ?string $actorName, ?int $statusId = null, ?string $note = null): Intervention
    {
        return DB::transaction(function () use ($intervention, $actorName, $statusId, $note) {
            $locked = Intervention::query()->with('status')->lockForUpdate()->findOrFail($intervention->id);

            if (! $locked->isClosed()) {
                throw new BusinessRuleException('Only a closed intervention can be reopened.', 'INTERVENTION_NOT_CLOSED', [], 422);
            }

            $target = $statusId ? InterventionStatus::query()->find($statusId) : $this->statusBeforeClosing($locked);

            if (! $target || ! $target->is_active || $target->is_closed) {
                throw new BusinessRuleException('Choose an active status that keeps the intervention open.', 'INVALID_REOPEN_STATUS', ['status_id' => ['This status cannot be used to reopen.']], 422);
            }

            $fromStatusId = $locked->intervention_status_id;
            $locked->update(['intervention_status_id' => $target->id, 'closed_at' => null]);
            $this->recordStatusEvent($locked, InterventionMessage::KIND_REOPENED, 'employee', $actorName, $fromStatusId, $target->id, $note, null);

            return $locked;
        });
    }

    private function statusBeforeClosing(Intervention $intervention): InterventionStatus
    {
        $previous = InterventionMessage::query()->where('intervention_id', $intervention->id)
            ->where('kind', InterventionMessage::KIND_STATUS_CHANGE)->where('status_id', $intervention->intervention_status_id)
            ->latest('id')->value('from_status_id');

        $status = $previous ? InterventionStatus::query()->find($previous) : null;

        return $status && $status->is_active && ! $status->is_closed ? $status : $this->initialStatus();
    }

    /** One inline timeline entry: who changed the status, from what, to what (and when = created_at). */
    private function recordStatusEvent(Intervention $intervention, string $kind, string $actorType, ?string $actorName, ?int $fromStatusId, int $toStatusId, ?string $note, ?int $accountId): InterventionMessage
    {
        return InterventionMessage::query()->create([
            'intervention_id' => $intervention->id, 'kind' => $kind, 'author_type' => $actorType, 'author_name' => $actorName,
            'portal_account_id' => $accountId, 'visibility' => 'customer', 'body' => $note ?? '',
            'from_status_id' => $fromStatusId, 'status_id' => $toStatusId,
        ]);
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
