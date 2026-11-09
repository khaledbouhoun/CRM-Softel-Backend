<?php

namespace App\Repositories;

use App\Models\FollowUpTask;
use App\Models\Prospect;
use App\Repositories\Concerns\PaginatesFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class FollowUpRepository
{
    use PaginatesFilters;

    /**
     * Filters: status, scope (overdue|today|upcoming, implies pending), days
     * (limits "upcoming"), prospect_id, date / date_from / date_to on
     * scheduled_at, search, sort, direction, per_page.
     *
     * Default order: scheduled_at ascending (most urgent first); completed
     * lists usually want sort=completed_at&direction=desc.
     */
    public function paginate(array $filters, ?Prospect $prospect = null): LengthAwarePaginator
    {
        $query = FollowUpTask::query()->with(['prospect', 'activity']);

        if ($prospect) {
            $query->where('prospect_id', $prospect->id);
        } else {
            $query->forActiveProspects();

            if (isset($filters['prospect_id'])) {
                $query->where('prospect_id', $filters['prospect_id']);
            }
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        match ($filters['scope'] ?? null) {
            'overdue' => $query->overdue(),
            'today' => $query->dueToday(),
            'upcoming' => $query->upcoming(isset($filters['days']) ? (int) $filters['days'] : null),
            default => null,
        };

        if (isset($filters['date'])) {
            $this->applyDayRange($query, 'scheduled_at', $filters['date'], $filters['date']);
        }
        $this->applyDayRange($query, 'scheduled_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        if (filled($filters['search'] ?? null)) {
            $term = $this->escapeLike(trim($filters['search']));
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        $sort = $filters['sort'] ?? 'scheduled_at';
        $direction = $this->direction($filters, $sort === 'scheduled_at' ? 'asc' : 'desc');

        return $query->orderBy($sort, $direction)->orderBy('id', $direction)
            ->paginate($this->perPage($filters))->withQueryString();
    }
}
