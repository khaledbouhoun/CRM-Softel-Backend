<?php

namespace App\Models;

use App\Enums\ProductRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'pack_id',
        'slug',
        'image_path',
        'gallery',
        'full_description',
        'features',
        'specifications',
        'is_active_on_web',
        'demo_url',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_active_on_web' => 'boolean',
            'gallery' => 'array',
            'features' => 'array',
            'specifications' => 'array',
        ];
    }

    public function prospects(): BelongsToMany
    {
        return $this->belongsToMany(Prospect::class, 'prospect_products')
            ->using(ProspectProduct::class)
            ->withTimestamps();
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Products linked to a prospect/customer (interest and/or ownership, see ProductRelation). */
    public function scopeForProspect(Builder $query, int $prospectId, ProductRelation|string $relation = ProductRelation::Linked): Builder
    {
        $relation = ProductRelation::from($relation instanceof ProductRelation ? $relation->value : $relation);

        return $query->whereHas('prospects', function (Builder $q) use ($prospectId, $relation) {
            $q->where('prospects.id', $prospectId);

            match ($relation) {
                ProductRelation::Owned => $q->whereNotNull('prospect_products.purchased_at'),
                ProductRelation::Interested => $q->whereNull('prospect_products.purchased_at'),
                ProductRelation::Linked => null,
            };
        });
    }

    public function scopeOnWeb(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_active_on_web', true);
    }

    /** Products a demo can be generated for: a fixed demo link is configured. */
    public function scopeWithDemo(Builder $query): Builder
    {
        return $query->whereNotNull('demo_url')->where('demo_url', '!=', '');
    }

    /** Linked to any prospect, soft-deleted prospects included. */
    public function isInUse(): bool
    {
        return ProspectProduct::query()->where('product_id', $this->id)->exists();
    }
}
