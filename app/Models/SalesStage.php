<?php

namespace App\Models;

use App\Enums\StageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'color',
        'sort_order',
        'stage_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage_type' => StageType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Deterministic pipeline order: sort_order, then id as tie-breaker. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function isLost(): bool
    {
        return $this->stage_type === StageType::Lost;
    }

    public function isWon(): bool
    {
        return $this->stage_type === StageType::Won;
    }

    /**
     * A stage is "in use" when any prospect (including soft-deleted ones) sits
     * in it, or when any stage history row references it.
     */
    public function isInUse(): bool
    {
        return Prospect::withTrashed()->where('sales_stage_id', $this->id)->exists()
            || ProspectStageHistory::query()
                ->where('to_stage_id', $this->id)
                ->orWhere('from_stage_id', $this->id)
                ->exists();
    }
}
