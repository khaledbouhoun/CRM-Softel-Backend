<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\LossReason;
use App\Services\Concerns\ReordersRecords;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Deactivating a loss reason only hides it from new selections; prospects and
 * stage history keep referencing it and the API keeps returning it there.
 */
class LossReasonService
{
    use ReordersRecords;

    private const FIELDS = ['name', 'description', 'requires_note', 'sort_order', 'is_active'];

    public function create(array $data): LossReason
    {
        $reason = new LossReason(Arr::only($data, self::FIELDS));
        $reason->sort_order ??= $this->nextSortOrder(LossReason::class);
        $reason->requires_note ??= false;
        $reason->is_active ??= true;
        $reason->save();

        return $reason->refresh();
    }

    public function update(LossReason $reason, array $data): LossReason
    {
        $reason->fill(Arr::only($data, self::FIELDS));
        $reason->save();

        return $reason;
    }

    public function reorder(array $ids): void
    {
        $this->reorderAll(LossReason::class, $ids);
    }

    public function delete(LossReason $reason): void
    {
        DB::transaction(function () use ($reason) {
            if ($reason->isInUse()) {
                throw BusinessRuleException::resourceInUse(
                    "The loss reason \"{$reason->name}\" is referenced by prospects or stage history and cannot be deleted. Deactivate it instead (PUT with is_active=false).",
                );
            }

            $reason->delete();
        });
    }
}
