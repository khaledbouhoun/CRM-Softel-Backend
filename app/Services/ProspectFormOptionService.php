<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProspectFormOption;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProspectFormOptionService
{
    private const FIELDS = ['type', 'name', 'requires_details', 'sort_order', 'is_active'];

    public function create(array $data): ProspectFormOption
    {
        $option = new ProspectFormOption(Arr::only($data, self::FIELDS));
        $option->sort_order ??= $this->nextSortOrderForType($option->type);
        $option->requires_details ??= false;
        $option->is_active ??= true;
        $option->save();

        return $option->refresh();
    }

    public function update(ProspectFormOption $option, array $data): ProspectFormOption
    {
        $option->fill(Arr::only($data, self::FIELDS));
        $option->save();

        return $option;
    }

    public function reorder(string $type, array $ids): void
    {
        $ids = array_map('intval', $ids);
        $existing = ProspectFormOption::query()
            ->where('type', $type)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_diff($existing, $ids);
        $unknown = array_diff($ids, $existing);

        if ($missing || $unknown) {
            $messages = [];
            if ($unknown) {
                $messages[] = 'Unknown ids: '.implode(', ', $unknown).'.';
            }
            if ($missing) {
                $messages[] = 'Missing ids: '.implode(', ', $missing).'.';
            }

            throw ValidationException::withMessages([
                'ids' => ['The ids list must contain every option of type '.$type.' exactly once. '.implode(' ', $messages)],
            ]);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ProspectFormOption::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function delete(ProspectFormOption $option): void
    {
        DB::transaction(function () use ($option) {
            if ($option->isInUse()) {
                throw BusinessRuleException::resourceInUse(
                    "The {$option->type} \"{$option->name}\" is referenced by prospects and cannot be deleted. Deactivate it instead (PUT with is_active=false)."
                );
            }

            $option->delete();
        });
    }

    private function nextSortOrderForType(string $type): int
    {
        return ((int) ProspectFormOption::query()->where('type', $type)->max('sort_order')) + 1;
    }
}
