<?php

namespace App\Repositories;

use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\SalesStage;
use App\Repositories\Concerns\PaginatesFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProspectRepository
{
    use PaginatesFilters;

    /**
     * Filters: search, sales_stage_id, stage_type, product_id, loss_reason_id,
     * converted (active_only default | converted_only | all), city, created_from/created_to, updated_from/updated_to, sort, direction,
     * per_page.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->withListData(Prospect::query());

        if (filled($filters['search'] ?? null)) {
            $this->applySearch($query, trim($filters['search']));
        }

        // Catalog values are stored either as the option name ("Facebook Ads") or
        // as a legacy snake_case code ("facebook_ads"): match both spellings.
        foreach (['source', 'business_activity', 'previous_software'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $value = trim((string) $filters[$column]);
                $query->whereIn($column, array_values(array_unique([
                    $value,
                    str_replace(' ', '_', mb_strtolower($value)),
                    str_replace('_', ' ', $value),
                ])));
            }
        }

        // Default: only active prospects. Converted leads become customers and leave the pipeline.
        $converted = $filters['converted'] ?? 'active_only';
        if ($converted === 'active_only') {
            $query->whereNull('prospects.converted_at');
        } elseif ($converted === 'converted_only') {
            $query->whereNotNull('prospects.converted_at');
        }

        if (isset($filters['sales_stage_id'])) {
            $query->where('sales_stage_id', $filters['sales_stage_id']);
        }

        if (isset($filters['stage_type'])) {
            $query->whereHas('salesStage', fn (Builder $q) => $q->where('stage_type', $filters['stage_type']));
        }

        if (isset($filters['product_id'])) {
            $query->forProduct((int) $filters['product_id'], $filters['product_relation'] ?? 'linked');
        }

        if (isset($filters['loss_reason_id'])) {
            $query->where('loss_reason_id', $filters['loss_reason_id']);
        }

        if (filled($filters['city'] ?? null)) {
            $query->where('city', $filters['city']);
        }

        $this->applyDayRange($query, 'prospects.created_at', $filters['created_from'] ?? null, $filters['created_to'] ?? null);
        $this->applyDayRange($query, 'prospects.updated_at', $filters['updated_from'] ?? null, $filters['updated_to'] ?? null);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $this->direction($filters, in_array($sort, ['name', 'city', 'phone', 'stage'], true) ? 'asc' : 'desc');
        if ($sort === 'stage') {
            // Pipeline order (the stage position), not alphabetical.
            $query->orderBy(
                SalesStage::query()->select('sort_order')->whereColumn('sales_stages.id', 'prospects.sales_stage_id'),
                $direction,
            );
        } else {
            $query->orderBy($sort, $direction);
        }
        $query->orderBy('id', 'desc');

        return $query->paginate($this->perPage($filters))->withQueryString();
    }

    /**
     * One search used everywhere prospects are looked up (list, pipeline,
     * pickers): company name (name), contact person (full name), phone,
     * prospect id, plus e-mail and city. It never looks at the stage, so won,
     * lost and deferred prospects are found like any other.
     *
     * A phone-like term also matches digits only, so "0555 12" finds
     * "+213 (555) 12-34-56" and vice versa.
     */
    public function applySearch(Builder $query, string $search): void
    {
        $term = $this->escapeLike($search);

        $query->where(function (Builder $q) use ($search, $term) {
            $q->where('prospects.name', 'like', $term)
                ->orWhere('prospects.contact_person', 'like', $term)
                ->orWhere('prospects.phone', 'like', $term)
                ->orWhere('prospects.email', 'like', $term)
                ->orWhere('prospects.city', 'like', $term);

            if (ctype_digit($search) && strlen($search) <= 18) {
                $q->orWhere('prospects.id', (int) $search);
            }

            $digits = preg_replace('/\D+/', '', $search);
            if (preg_match('/^[+\d\s().\/-]+$/', $search) && strlen($digits) >= 3) {
                $normalised = 'prospects.phone';
                foreach ([' ', '-', '.', '/', '(', ')', '+'] as $char) {
                    $normalised = "REPLACE({$normalised}, '{$char}', '')";
                }
                $q->orWhereRaw("{$normalised} like ?", ['%'.$digits.'%']);
            }
        });
    }

    public function findForDisplay(int $id): Prospect
    {
        return $this->withListData(Prospect::query())->findOrFail($id);
    }

    public function stageHistory(Prospect $prospect, array $filters): LengthAwarePaginator
    {
        return ProspectStageHistory::query()
            ->where('prospect_id', $prospect->id)
            ->with(['fromStage', 'toStage', 'lossReason'])
            ->orderBy('changed_at', $this->direction($filters))
            ->orderBy('id', $this->direction($filters))
            ->paginate($this->perPage($filters));
    }

    /** Eager loads + aggregates used by list and detail responses (no N+1). */
    private function withListData(Builder $query): Builder
    {
        return $query
            ->with(Prospect::defaultRelations())
            ->withCount(['activities', 'pendingFollowUps'])
            ->withMax('activities', 'activity_at')
            ->withMin('pendingFollowUps', 'scheduled_at');
    }
}
