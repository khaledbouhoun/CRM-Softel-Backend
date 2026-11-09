<?php

namespace App\Services;

use App\Enums\StageType;
use App\Exceptions\BusinessRuleException;
use App\Models\LossReason;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\SalesStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every rule about which stage a prospect may be in.
 *
 * Loss-information policy: when a prospect moves to a non-lost stage its
 * current loss_reason_id / loss_note are cleared. The values remain available
 * in the stage history row that recorded the move into the lost stage.
 */
class StageTransitionService
{
    /**
     * Move a prospect to another stage and record the transition.
     * Runs in one transaction with the prospect row locked, so two employees
     * changing the same prospect at once cannot interleave.
     *
     * @param  array{sales_stage_id:int, loss_reason_id?:int|null, loss_note?:string|null}  $data
     */
    public function change(Prospect $prospect, array $data): ProspectStageHistory
    {
        return DB::transaction(function () use ($prospect, $data) {
            $locked = Prospect::query()->with('salesStage')->lockForUpdate()->findOrFail($prospect->id);

            $target = $this->resolveTargetStage((int) $data['sales_stage_id']);
            $this->assertTransitionAllowed($locked, $target);
            [$reason, $note] = $this->resolveLossInformation($target, $data);

            $fromStageId = $locked->sales_stage_id;
            $this->applyStage($locked, $target, $reason, $note);
            $locked->save();

            $history = $this->recordHistory($locked, $fromStageId, $target, $reason, $note);

            // Reflect the change on the instance the caller holds.
            $prospect->setRawAttributes($locked->getAttributes(), true);

            return $history;
        });
    }

    /**
     * The stage a prospect may enter: must exist and be active. When no id is
     * given, the first active "open" stage in pipeline order is used.
     */
    public function resolveTargetStage(?int $stageId): SalesStage
    {
        if ($stageId === null) {
            $stage = SalesStage::query()->active()->where('stage_type', StageType::Open->value)->ordered()->first();

            if (! $stage) {
                throw ValidationException::withMessages([
                    'sales_stage_id' => ['No active open sales stage is configured. Please choose a stage.'],
                ]);
            }

            return $stage;
        }

        $stage = SalesStage::query()->find($stageId);

        if (! $stage) {
            throw ValidationException::withMessages(['sales_stage_id' => ['The selected sales stage does not exist.']]);
        }

        if (! $stage->is_active) {
            throw ValidationException::withMessages([
                'sales_stage_id' => ["The sales stage \"{$stage->name}\" is inactive and cannot be selected."],
            ]);
        }

        return $stage;
    }

    /**
     * Validates loss reason / note against the target stage.
     *
     * @return array{0: ?LossReason, 1: ?string}
     */
    public function resolveLossInformation(SalesStage $target, array $data): array
    {
        $reasonId = $data['loss_reason_id'] ?? null;
        $note = isset($data['loss_note']) ? trim((string) $data['loss_note']) : null;
        $note = $note === '' ? null : $note;

        if (! $target->isLost()) {
            $errors = [];
            if ($reasonId !== null) {
                $errors['loss_reason_id'] = ['A loss reason can only be given when moving to a lost stage.'];
            }
            if ($note !== null) {
                $errors['loss_note'] = ['A loss note can only be given when moving to a lost stage.'];
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            return [null, null];
        }

        if ($reasonId === null) {
            throw ValidationException::withMessages([
                'loss_reason_id' => ['A loss reason is required when moving a prospect to a lost stage.'],
            ]);
        }

        $reason = LossReason::query()->find($reasonId);

        if (! $reason) {
            throw ValidationException::withMessages(['loss_reason_id' => ['The selected loss reason does not exist.']]);
        }

        if (! $reason->is_active) {
            throw ValidationException::withMessages([
                'loss_reason_id' => ["The loss reason \"{$reason->name}\" is inactive and cannot be selected."],
            ]);
        }

        if ($reason->requires_note && $note === null) {
            throw ValidationException::withMessages([
                'loss_note' => ["A note is required for the loss reason \"{$reason->name}\"."],
            ]);
        }

        return [$reason, $note];
    }

    public function assertTransitionAllowed(Prospect $prospect, SalesStage $target): void
    {
        if ((int) $prospect->sales_stage_id === $target->id) {
            throw BusinessRuleException::invalidStageTransition(
                "The prospect is already in the stage \"{$target->name}\"."
            );
        }

        $current = $prospect->salesStage;

        if ($current && $current->stage_type->isClosed() && ! config('crm.stage_transitions.allow_reopening_closed')) {
            throw BusinessRuleException::invalidStageTransition(
                "The prospect is in the closed stage \"{$current->name}\" and can no longer be moved."
            );
        }
    }

    public function applyStage(Prospect $prospect, SalesStage $target, ?LossReason $reason, ?string $note): void
    {
        $prospect->sales_stage_id = $target->id;
        $prospect->loss_reason_id = $reason?->id;
        $prospect->loss_note = $reason ? $note : null;
    }

    public function recordHistory(
        Prospect $prospect,
        ?int $fromStageId,
        SalesStage $target,
        ?LossReason $reason,
        ?string $note,
    ): ProspectStageHistory {
        $history = new ProspectStageHistory([
            'from_stage_id' => $fromStageId,
            'to_stage_id' => $target->id,
            'loss_reason_id' => $reason?->id,
            'loss_note' => $reason ? $note : null,
            'changed_at' => now(),
        ]);
        $history->prospect()->associate($prospect);
        $history->save();

        return $history->load(['fromStage', 'toStage', 'lossReason']);
    }
}
