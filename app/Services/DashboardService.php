<?php

namespace App\Services;

use App\Enums\StageType;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Models\Activity;
use App\Models\FollowUpTask;
use App\Models\LossReason;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\SalesStage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every figure is computed from the database on each call (no caching).
 * Soft-deleted prospects and their follow-ups/activities are excluded.
 */
class DashboardService
{
    public function summary(int $recentLimit, int $upcomingDays): array
    {
        // One grouped query: counts per stage (non-deleted prospects only).
        $stages = SalesStage::query()->ordered()->withCount('prospects')->get();

        $byType = array_fill_keys(StageType::values(), 0);
        foreach ($stages as $stage) {
            $byType[$stage->stage_type->value] += $stage->prospects_count;
        }

        $followUps = FollowUpTask::query()->forActiveProspects();

        return [
            'prospects' => [
                'total' => array_sum($byType),
                'open' => $byType[StageType::Open->value],
                'won' => $byType[StageType::Won->value],
                'lost' => $byType[StageType::Lost->value],
            ],
            'prospects_by_stage' => $stages
                // Inactive stages are listed only while prospects still sit in them.
                ->filter(fn (SalesStage $s) => $s->is_active || $s->prospects_count > 0)
                ->map(fn (SalesStage $s) => [
                    'sales_stage_id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'stage_type' => $s->stage_type->value,
                    'sort_order' => $s->sort_order,
                    'is_active' => $s->is_active,
                    'count' => $s->prospects_count,
                ])->values()->all(),
            'lost_by_reason' => $this->lostByReason(),
            'this_month' => $this->closedThisMonth(),
            'follow_ups' => [
                'today' => (clone $followUps)->dueToday()->count(),
                'overdue' => (clone $followUps)->overdue()->count(),
                'upcoming' => (clone $followUps)->upcoming($upcomingDays)->count(),
                'upcoming_window_days' => $upcomingDays,
            ],
            'recent_activities' => Activity::query()
                ->with(['prospect.salesStage', 'followUp'])
                ->where(fn (Builder $q) => $q
                    ->whereNull('activities.prospect_id')
                    ->orWhereHas('prospect', fn (Builder $p) => $p->whereNull('prospects.deleted_at')))
                ->orderByDesc('activity_at')
                ->orderByDesc('id')
                ->limit($recentLimit)
                ->get()
                ->map(fn (Activity $a) => (new ActivityResource($a))->resolve())
                ->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Current lost prospects grouped by their loss reason (inactive reasons included). */
    private function lostByReason(): array
    {
        $counts = Prospect::query()
            ->whereNotNull('loss_reason_id')
            ->whereHas('salesStage', fn (Builder $q) => $q->where('stage_type', StageType::Lost->value))
            ->selectRaw('loss_reason_id, COUNT(*) as aggregate')
            ->groupBy('loss_reason_id')
            ->pluck('aggregate', 'loss_reason_id');

        return LossReason::query()->ordered()->whereIn('id', $counts->keys())->get()
            ->map(fn (LossReason $r) => [
                'loss_reason_id' => $r->id,
                'name' => $r->name,
                'is_active' => $r->is_active,
                'count' => (int) $counts[$r->id],
            ])->all();
    }

    /** Distinct prospects moved into a won / lost stage since the 1st of the month. */
    private function closedThisMonth(): array
    {
        $counts = ProspectStageHistory::query()
            ->join('sales_stages', 'sales_stages.id', '=', 'prospect_stage_histories.to_stage_id')
            ->whereIn('sales_stages.stage_type', [StageType::Won->value, StageType::Lost->value])
            ->where('prospect_stage_histories.changed_at', '>=', now()->startOfMonth())
            ->whereHas('prospect', fn (Builder $q) => $q->whereNull('prospects.deleted_at'))
            ->selectRaw('sales_stages.stage_type, COUNT(DISTINCT prospect_stage_histories.prospect_id) as aggregate')
            ->groupBy('sales_stages.stage_type')
            ->pluck('aggregate', 'stage_type');

        return [
            'month' => now()->format('Y-m'),
            'won' => (int) ($counts[StageType::Won->value] ?? 0),
            'lost' => (int) ($counts[StageType::Lost->value] ?? 0),
            'new_prospects' => Prospect::query()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }
}
