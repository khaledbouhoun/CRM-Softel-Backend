<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function prospects(): BelongsToMany
    {
        return $this->belongsToMany(Prospect::class, 'prospect_products')
            ->using(ProspectProduct::class)
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Linked to any prospect, soft-deleted prospects included. */
    public function isInUse(): bool
    {
        return ProspectProduct::query()->where('product_id', $this->id)->exists();
    }
}
