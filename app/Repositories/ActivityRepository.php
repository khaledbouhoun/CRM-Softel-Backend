<?php

namespace App\Repositories;

use App\Models\Activity;
use App\Models\Prospect;
use App\Repositories\Concerns\PaginatesFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ActivityRepository
{
    use PaginatesFilters;

    /**
     * Newest first. Activities of soft-deleted prospects are excluded from the
     * global list (they stay stored and visible if the prospect is restored);
     * unlinked activities are included.
     *
     * Filters: prospect_id, type, linked (1/0), origin (direct|follow_up),
     * date_from/date_to, search (title, description, outcome), direction,
     * per_page.
     */
    public function paginate(array $filters, ?Prospect $prospect = null): LengthAwarePaginator
    {
        $query = Activity::query()->with(['prospect.salesStage', 'followUp']);

        if ($prospect) {
            $query->where('prospect_id', $prospect->id);
        } else {
            $query->where(fn (Builder $q) => $q
                ->whereNull('activities.prospect_id')
                ->orWhereHas('prospect', fn (Builder $p) => $p->whereNull('prospects.deleted_at')));

            if (isset($filters['prospect_id'])) {
                $query->where('prospect_id', $filters['prospect_id']);
            }

            if (isset($filters['linked'])) {
                filter_var($filters['linked'], FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('activities.prospect_id')
                    : $query->whereNull('activities.prospect_id');
            }
        }

        if (isset($filters['origin'])) {
            $filters['origin'] === 'follow_up'
                ? $query->whereNotNull('follow_up_task_id')
                : $query->whereNull('follow_up_task_id');
        }

        if (isset($filters['type'])) {
            // "meeting" is the legacy code of "appointment": list both together.
            $query->whereIn('type', match ($filters['type']) {
                'appointment', 'meeting' => ['appointment', 'meeting'],
                default => [$filters['type']],
            });
        }

        if (filled($filters['search'] ?? null)) {
            $term = $this->escapeLike(trim($filters['search']));
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhere('outcome', 'like', $term));
        }

        $this->applyDayRange($query, 'activity_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        $direction = $this->direction($filters);

        return $query->orderBy('activity_at', $direction)->orderBy('id', $direction)
            ->paginate($this->perPage($filters))->withQueryString();
    }
}
