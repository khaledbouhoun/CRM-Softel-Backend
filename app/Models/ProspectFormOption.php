<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProspectFormOption extends Model
{
    use HasFactory;

    public const TYPES = [
        'source',
        'previous_software',
        'business_activity',
        'profession',
    ];

    protected $fillable = [
        'type',
        'name',
        'requires_details',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_details' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Checks if any prospect (including soft-deleted) references this option.
     */
    public function isInUse(): bool
    {
        $column = match ($this->type) {
            'source' => 'source',
            'previous_software' => 'previous_software',
            'business_activity' => 'business_activity',
            'profession' => 'profession',
            default => null,
        };

        if ($column === null) {
            return false;
        }

        $val = $this->name;
        $lowerVal = strtolower($val);

        return Prospect::withTrashed()
            ->where(function (Builder $q) use ($column, $val, $lowerVal) {
                $q->where($column, $val)
                  ->orWhere($column, $lowerVal);
            })
            ->exists();
    }
}
