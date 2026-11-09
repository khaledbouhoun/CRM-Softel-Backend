<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LossReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'requires_note',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_note' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(ProspectStageHistory::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function isInUse(): bool
    {
        return Prospect::withTrashed()->where('loss_reason_id', $this->id)->exists()
            || $this->stageHistories()->exists();
    }
}
