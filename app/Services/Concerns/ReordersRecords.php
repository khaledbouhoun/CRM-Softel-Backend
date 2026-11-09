<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait ReordersRecords
{
    /**
     * Rewrites sort_order as 1..n following $ids. $ids must contain every
     * record exactly once so the resulting order is complete and deterministic.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int>  $ids
     */
    protected function reorderAll(string $modelClass, array $ids): void
    {
        $ids = array_map('intval', $ids);
        $existing = $modelClass::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

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
                'ids' => ['The ids list must contain every record exactly once. '.implode(' ', $messages)],
            ]);
        }

        DB::transaction(function () use ($modelClass, $ids) {
            foreach ($ids as $position => $id) {
                $modelClass::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }

    /** @param class-string<Model> $modelClass */
    protected function nextSortOrder(string $modelClass): int
    {
        return ((int) $modelClass::query()->max('sort_order')) + 1;
    }
}
