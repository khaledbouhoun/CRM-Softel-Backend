<?php

namespace App\Services;

use App\Enums\StageType;
use App\Exceptions\BusinessRuleException;
use App\Models\SalesStage;
use App\Services\Concerns\ReordersRecords;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline configuration rules:
 *  - stage_type cannot change once the stage is used (prospects or history),
 *    otherwise existing data would silently change meaning;
 *  - used stages are never physically deleted (deactivate them instead);
 *  - at least one active "open" stage must always remain, because new
 *    prospects default to the first one.
 */
class SalesStageService
{
    use ReordersRecords;

    private const FIELDS = ['name', 'description', 'color', 'sort_order', 'stage_type', 'is_active'];

    public function create(array $data): SalesStage
    {
        $stage = new SalesStage(Arr::only($data, self::FIELDS));
        $stage->sort_order ??= $this->nextSortOrder(SalesStage::class);
        $stage->is_active ??= true;
        $stage->save();

        return $stage->refresh();
    }

    public function update(SalesStage $stage, array $data): SalesStage
    {
        return DB::transaction(function () use ($stage, $data) {
            $stage->fill(Arr::only($data, self::FIELDS));

            if ($stage->isDirty('stage_type') && $stage->isInUse()) {
                throw new BusinessRuleException(
                    "The type of the stage \"{$stage->getOriginal('name')}\" cannot be changed because it is already used by prospects or stage history. Create a new stage instead.",
                    'STAGE_TYPE_LOCKED',
                    ['stage_type' => ['The stage type cannot be changed once the stage is in use.']],
                );
            }

            if ($this->wouldRemoveLastOpenStage($stage, $stage->is_active && $stage->stage_type === StageType::Open)) {
                throw $this->lastOpenStageException();
            }

            $stage->save();

            return $stage;
        });
    }

    public function reorder(array $ids): void
    {
        $this->reorderAll(SalesStage::class, $ids);
    }

    public function delete(SalesStage $stage): void
    {
        DB::transaction(function () use ($stage) {
            if ($stage->isInUse()) {
                throw BusinessRuleException::resourceInUse(
                    "The stage \"{$stage->name}\" is used by prospects or stage history and cannot be deleted. Deactivate it instead (PUT with is_active=false).",
                );
            }

            if ($this->wouldRemoveLastOpenStage($stage, false)) {
                throw $this->lastOpenStageException();
            }

            $stage->delete();
        });
    }

    /**
     * @param  bool  $staysActiveOpen  whether $stage will still be an active open stage afterwards
     */
    private function wouldRemoveLastOpenStage(SalesStage $stage, bool $staysActiveOpen): bool
    {
        $wasActiveOpen = $stage->getOriginal('is_active') && $stage->getOriginal('stage_type') === StageType::Open;

        if (! $wasActiveOpen || $staysActiveOpen) {
            return false;
        }

        return ! SalesStage::query()
            ->active()
            ->where('stage_type', StageType::Open->value)
            ->whereKeyNot($stage->id)
            ->exists();
    }

    private function lastOpenStageException(): BusinessRuleException
    {
        return new BusinessRuleException(
            'At least one active open stage must remain in the pipeline.',
            'LAST_OPEN_STAGE',
        );
    }
}
