<?php

namespace App\Services;

use App\Enums\StageType;
use App\Exceptions\BusinessRuleException;
use App\Models\Prospect;
use App\Models\SalesStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit prospect -> customer transition. The prospect row, its activities,
 * follow-ups, appointments and stage history are kept; only converted_* and the
 * purchased_at flag on the product links are added. Portal accounts linked to the
 * prospect keep working because they reference the same prospect id.
 */
class ProspectConversionService
{
    public function __construct(private readonly StageTransitionService $transitions) {}

    /**
     * @param  list<int>  $purchasedProductIds  products bought (must be linked or active)
     */
    public function convert(Prospect $prospect, array $purchasedProductIds, ?string $note, ?string $by, ?int $wonStageId = null): Prospect
    {
        return DB::transaction(function () use ($prospect, $purchasedProductIds, $note, $by, $wonStageId) {
            $locked = Prospect::query()->lockForUpdate()->findOrFail($prospect->id);

            if ($locked->converted_at !== null) {
                throw new BusinessRuleException('This prospect is already a customer.', 'ALREADY_CUSTOMER', [], 409);
            }

            $purchasedProductIds = array_values(array_unique(array_map('intval', $purchasedProductIds)));
            $linked = $locked->products()->pluck('products.id')->all();

            foreach ($purchasedProductIds as $id) {
                if (! in_array($id, $linked, true)) {
                    $locked->products()->attach($id);
                }
            }
            if ($purchasedProductIds !== []) {
                $locked->products()->newPivotStatement()->where('prospect_id', $locked->id)
                    ->whereIn('product_id', $purchasedProductIds)->update(['purchased_at' => now()]);
            }

            $this->moveToWonStage($locked, $wonStageId);

            $locked->forceFill(['converted_at' => now(), 'converted_by' => $by, 'conversion_note' => $note])->save();

            return $locked->load(Prospect::defaultRelations());
        });
    }

    private function moveToWonStage(Prospect $prospect, ?int $stageId): void
    {
        $prospect->loadMissing('salesStage');

        if ($prospect->salesStage->stage_type === StageType::Won) {
            return;
        }

        $stage = $stageId
            ? SalesStage::query()->find($stageId)
            : SalesStage::query()->active()->where('stage_type', StageType::Won->value)->ordered()->first();

        if ($stage === null) {
            if ($stageId) {
                throw ValidationException::withMessages(['won_stage_id' => ['The selected stage does not exist.']]);
            }

            return; // no won stage configured: keep the pipeline untouched
        }

        if (! $stage->isWon()) {
            throw ValidationException::withMessages(['won_stage_id' => ['The selected stage is not a won stage.']]);
        }

        $this->transitions->change($prospect, ['sales_stage_id' => $stage->id]);
    }
}
