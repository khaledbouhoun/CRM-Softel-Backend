<?php

namespace App\Repositories\Concerns;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait PaginatesFilters
{
    protected function perPage(array $filters): int
    {
        return (int) ($filters['per_page'] ?? config('crm.pagination.default_per_page'));
    }

    protected function direction(array $filters, string $default = 'desc'): string
    {
        return ($filters['direction'] ?? $default) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Inclusive whole-day range on a datetime column; Y-m-d bounds are read in
     * the application timezone.
     */
    protected function applyDayRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $this->day($from)->startOfDay());
        }

        if ($to !== null) {
            $query->where($column, '<=', $this->day($to)->endOfDay());
        }
    }

    private function day(string $date): Carbon
    {
        return LocalDateTime::toLocal(Carbon::createFromFormat('Y-m-d', $date, config('app.timezone')));
    }

    protected function escapeLike(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
