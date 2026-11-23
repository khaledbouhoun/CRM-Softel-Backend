<?php

namespace App\Services;

use App\Casts\LocalDateTime;
use App\Enums\StageType;
use App\Models\Appointment;
use App\Models\Intervention;
use App\Models\LossReason;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\SalesStage;
use App\Models\Subscription;
use App\Models\Trial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business-intelligence figures for the Analytics hub. Everything is computed
 * from the database on each call. Labels that come from user-editable data
 * (stage names, loss reasons, sources, products, cities) are returned as stored;
 * fixed categories use stable codes that the client translates.
 * Soft-deleted prospects are excluded.
 */
class AnalyticsService
{
    private const EXPIRING_WINDOW_DAYS = 60;

    private const TOP_LIMIT = 8;

    /** @return array<string, mixed> */
    public function report(string $range = 'month', ?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->period($range, $from, $to);

        return [
            'range' => $from || $to ? 'custom' : $range,
            'from' => $start?->toDateString(),
            'to' => $end?->toDateString(),
            'sales' => $this->sales($start, $end),
            'revenue_maintenance' => $this->revenueMaintenance($start, $end),
            'support' => $this->support($start, $end),
            'team' => $this->team($start, $end),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} null bounds are open-ended. */
    private function period(string $range, ?string $from, ?string $to): array
    {
        if ($from || $to) {
            return [
                $from ? LocalDateTime::toLocal($from)->startOfDay() : null,
                $to ? LocalDateTime::toLocal($to)->endOfDay() : null,
            ];
        }

        $now = now();

        return match ($range) {
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /** Applies the period to a column (no-op when the bound is open). */
    private function within(Builder $query, string $column, ?Carbon $start, ?Carbon $end): Builder
    {
        if ($start) {
            $query->where($column, '>=', $start->format('Y-m-d H:i:s'));
        }
        if ($end) {
            $query->where($column, '<=', $end->format('Y-m-d H:i:s'));
        }

        return $query;
    }

    private function sales(?Carbon $start, ?Carbon $end): array
    {
        $cohort = fn () => $this->within(Prospect::query(), 'prospects.created_at', $start, $end);

        return [
            'funnel' => $this->funnel($cohort()),
            'loss_reasons' => $this->lossReasons($start, $end),
            'sources' => $this->groupedProspects($cohort(), 'source', 'source'),
            'by_city' => $this->groupedProspects($cohort(), 'city', 'city'),
            'avg_sales_cycle_days' => $this->avgSalesCycle($start, $end),
        ];
    }

    /**
     * Prospects created in the period, by the furthest non-lost stage they ever
     * reached (stage history plus their current stage). Each step counts the
     * prospects that reached it or any later one.
     */
    private function funnel(Builder $cohort): array
    {
        $stages = SalesStage::query()->ordered()
            ->where('stage_type', '!=', StageType::Lost->value)
            ->get()
            ->filter(fn (SalesStage $s) => $s->is_active)
            ->values();

        if ($stages->isEmpty()) {
            return [];
        }

        $sortOf = $stages->pluck('sort_order', 'id');
        $ids = (clone $cohort)->pluck('prospects.id');
        $current = Prospect::query()->whereIn('id', $ids)->pluck('sales_stage_id', 'id');

        $reached = ProspectStageHistory::query()
            ->whereIn('prospect_id', $ids)
            ->whereIn('to_stage_id', $sortOf->keys())
            ->get(['prospect_id', 'to_stage_id'])
            ->groupBy('prospect_id')
            ->map(fn (Collection $rows) => (int) $rows->map(fn ($r) => $sortOf[$r->to_stage_id])->max());

        $furthest = $ids->mapWithKeys(function ($id) use ($reached, $current, $sortOf) {
            $stageId = $current[$id] ?? null;
            $own = $stageId !== null && $sortOf->has($stageId) ? (int) $sortOf[$stageId] : null;
            $values = array_filter([$reached[$id] ?? null, $own], fn ($v) => $v !== null);

            return [$id => $values ? max($values) : null];
        });

        $base = null;
        $rows = [];
        foreach ($stages as $stage) {
            $count = $furthest->filter(fn ($v) => $v !== null && $v >= $stage->sort_order)->count();
            $base ??= $count;
            $rows[] = [
                'stage_id' => $stage->id,
                'stage' => $stage->name,
                'stage_type' => $stage->stage_type->value,
                'count' => $count,
                'rate' => $base > 0 ? round($count / $base * 100, 1) : 0.0,
            ];
        }

        return $rows;
    }

    /** Prospects moved into a lost stage during the period, grouped by loss reason. */
    private function lossReasons(?Carbon $start, ?Carbon $end): array
    {
        $history = ProspectStageHistory::query()
            ->join('sales_stages', 'sales_stages.id', '=', 'prospect_stage_histories.to_stage_id')
            ->where('sales_stages.stage_type', StageType::Lost->value)
            ->whereHas('prospect', fn (Builder $q) => $q->whereNull('prospects.deleted_at'));
        $this->within($history, 'prospect_stage_histories.changed_at', $start, $end);

        $counts = $history
            ->selectRaw('prospect_stage_histories.loss_reason_id as reason_id, COUNT(DISTINCT prospect_stage_histories.prospect_id) as aggregate')
            ->groupBy('prospect_stage_histories.loss_reason_id')
            ->pluck('aggregate', 'reason_id');

        $total = (int) $counts->sum();
        $names = LossReason::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts
            ->map(fn ($count, $id) => [
                'reason_id' => $id ? (int) $id : null,
                'reason' => $id ? ($names[$id] ?? null) : null, // null = no reason recorded (client labels it)
                'count' => (int) $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('count')->values()->all();
    }

    /** Top values of a prospect column; blank values are grouped under a null label (client labels it). */
    private function groupedProspects(Builder $query, string $column, string $key): array
    {
        return $query
            ->selectRaw("NULLIF(TRIM(prospects.{$column}), '') as label, COUNT(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->orderBy('label')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [$key => $row->label, 'count' => (int) $row->aggregate])
            ->all();
    }

    /** Average days between creation and conversion, for prospects converted in the period. */
    private function avgSalesCycle(?Carbon $start, ?Carbon $end): ?float
    {
        $rows = $this->within(Prospect::query()->whereNotNull('converted_at'), 'converted_at', $start, $end)
            ->get(['created_at', 'converted_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $days = $rows->map(fn (Prospect $p) => max(0, $p->created_at->diffInSeconds($p->converted_at, false)) / 86400);

        return round($days->avg(), 1);
    }

    private function revenueMaintenance(?Carbon $start, ?Carbon $end): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $maintenance = fn () => Subscription::query()
            ->where('type', Subscription::MAINTENANCE)
            ->whereHas('prospect', fn (Builder $q) => $q->whereNull('prospects.deleted_at'));

        $active = $maintenance()->where('status', 'active')->where('expires_at', '>=', $now)->count();
        $expired = $maintenance()->where('expires_at', '<', $now)->count();
        $expiring = $maintenance()->where('status', 'active')
            ->whereBetween('expires_at', [$now, now()->addDays(self::EXPIRING_WINDOW_DAYS)->format('Y-m-d H:i:s')])
            ->count();

        // Products bought by customers (purchase date within the period).
        $purchases = DB::table('prospect_products')
            ->join('products', 'products.id', '=', 'prospect_products.product_id')
            ->join('prospects', 'prospects.id', '=', 'prospect_products.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->whereNotNull('prospect_products.purchased_at');
        if ($start) {
            $purchases->where('prospect_products.purchased_at', '>=', $start->format('Y-m-d H:i:s'));
        }
        if ($end) {
            $purchases->where('prospect_products.purchased_at', '<=', $end->format('Y-m-d H:i:s'));
        }

        $distribution = (clone $purchases)
            ->selectRaw('products.name as name, COUNT(*) as aggregate')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('aggregate')
            ->orderBy('products.name')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->aggregate])
            ->all();

        $totalPurchases = (clone $purchases)->count();
        $buyers = (clone $purchases)->distinct()->count('prospect_products.prospect_id');
        $withPlan = $active + $expired;

        return [
            'total_customers' => Prospect::query()->whereNotNull('converted_at')->count(),
            'active_maintenance_count' => $active,
            'expired_maintenance_count' => $expired,
            'retention_rate' => $withPlan > 0 ? round($active / $withPlan * 100, 1) : null,
            'expiring_next_60_days' => $expiring,
            'products_distribution' => $distribution,
            'avg_pcs_per_customer' => $buyers > 0 ? round($totalPurchases / $buyers, 1) : null,
        ];
    }

    private function support(?Carbon $start, ?Carbon $end): array
    {
        $tickets = fn () => $this->within(Intervention::query(), 'interventions.created_at', $start, $end);

        $total = $tickets()->count();
        $resolvedRows = $tickets()
            ->whereHas('status', fn (Builder $q) => $q->where('is_closed', true))
            ->whereNotNull('closed_at')
            ->get(['created_at', 'closed_at']);
        $resolved = $tickets()->whereHas('status', fn (Builder $q) => $q->where('is_closed', true))->count();

        $avgHours = $resolvedRows->isEmpty() ? null : round(
            $resolvedRows->avg(fn (Intervention $i) => max(0, $i->created_at->diffInSeconds($i->closed_at, false)) / 3600),
            1,
        );

        $byType = $tickets()
            ->join('intervention_types', 'intervention_types.id', '=', 'interventions.intervention_type_id')
            ->selectRaw('intervention_types.code as code, intervention_types.name as type, COUNT(*) as aggregate')
            ->groupBy('intervention_types.id', 'intervention_types.code', 'intervention_types.name')
            ->orderByDesc('aggregate')
            ->orderBy('intervention_types.name')
            ->get()
            ->map(fn ($row) => ['code' => $row->code, 'type' => $row->type, 'count' => (int) $row->aggregate])
            ->all();

        return [
            'total_tickets' => $total,
            'resolved_tickets' => $resolved,
            'resolution_rate' => $total > 0 ? round($resolved / $total * 100, 1) : null,
            'avg_resolution_hours' => $avgHours,
            'by_type' => $byType,
            'demo_conversion' => $this->demoConversion($start, $end),
        ];
    }

    /** Trials started in the period: converted = the prospect became a customer on/after the trial start. */
    private function demoConversion(?Carbon $start, ?Carbon $end): array
    {
        $trials = $this->within(Trial::query(), 'trials.starts_at', $start, $end)
            ->whereHas('prospect', fn (Builder $q) => $q->whereNull('prospects.deleted_at'))
            ->with('prospect:id,converted_at')
            ->get(['id', 'prospect_id', 'starts_at']);

        $converted = $trials->filter(fn (Trial $t) => $t->prospect?->converted_at && $t->prospect->converted_at->gte($t->starts_at))->count();
        $total = $trials->count();

        return [
            ['status' => 'converted', 'count' => $converted, 'rate' => $total > 0 ? round($converted / $total * 100, 1) : 0.0],
            ['status' => 'not_converted', 'count' => $total - $converted, 'rate' => $total > 0 ? round(($total - $converted) / $total * 100, 1) : 0.0],
        ];
    }

    /**
     * Per employee (names as recorded on the records): activities = appointments
     * assigned + trials created; interventions = tickets they closed in the period;
     * deals_won = prospects they converted into customers.
     */
    private function team(?Carbon $start, ?Carbon $end): array
    {
        $board = [];
        $aliases = \App\Models\EmployeeSession::query()->get(['username', 'custom_name', 'employee_id'])
            ->flatMap(function ($s) {
                $display = $s->custom_name ?: $s->username;
                return [
                    $s->username => $display,
                    $s->employee_id => $display,
                    $display => $display,
                ];
            })->all();
        $aliases['HP'] = 'Khaled';
        $aliases['Khaled B.'] = 'Khaled';
        $aliases['Amine M.'] = 'Amina Mansouri';

        $add = function (?string $name, string $field, int $count) use (&$board, $aliases) {
            $name = trim((string) $name);
            if ($name === '' || $count === 0) {
                return;
            }
            $name = $aliases[$name] ?? $name;
            $board[$name] ??= ['employee' => $name, 'activities' => 0, 'interventions' => 0, 'deals_won' => 0];
            $board[$name][$field] += $count;
        };

        $this->within(Appointment::query()->live()->whereNotNull('assigned_employee'), 'starts_at', $start, $end)
            ->selectRaw('assigned_employee as name, COUNT(*) as aggregate')->groupBy('assigned_employee')->get()
            ->each(fn ($r) => $add($r->name, 'activities', (int) $r->aggregate));

        $this->within(Trial::query()->whereNotNull('created_by'), 'starts_at', $start, $end)
            ->selectRaw('created_by as name, COUNT(*) as aggregate')->groupBy('created_by')->get()
            ->each(fn ($r) => $add($r->name, 'activities', (int) $r->aggregate));

        $this->within(
            Intervention::query()->whereNotNull('assigned_employee')
                ->whereHas('status', fn (Builder $q) => $q->where('is_closed', true)),
            'closed_at', $start, $end,
        )->selectRaw('assigned_employee as name, COUNT(*) as aggregate')->groupBy('assigned_employee')->get()
            ->each(fn ($r) => $add($r->name, 'interventions', (int) $r->aggregate));

        $this->within(Prospect::query()->whereNotNull('converted_by'), 'converted_at', $start, $end)
            ->selectRaw('converted_by as name, COUNT(*) as aggregate')->groupBy('converted_by')->get()
            ->each(fn ($r) => $add($r->name, 'deals_won', (int) $r->aggregate));

        $leaderboard = collect($board)
            ->sortByDesc(fn (array $e) => $e['activities'] + $e['interventions'] + $e['deals_won'] * 3)
            ->values()->all();

        return ['leaderboard' => $leaderboard];
    }
}
