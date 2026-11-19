<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A version of a product that customers can download, with its changelog and audience. */
class ProductRelease extends Model
{
    public const AUDIENCES = ['all', 'owned', 'specific'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['release_date' => 'date', 'is_published' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Only used when audience = specific. */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Prospect::class, 'product_release_customers');
    }

    public function hasInstaller(): bool
    {
        return filled($this->installer_path);
    }

    /** Published, already released, and the customer is in the audience. */
    public function scopeVisibleToCustomer(Builder $query, int $prospectId): Builder
    {
        return $query->where('is_published', true)
            ->whereDate('release_date', '<=', now()->toDateString())
            ->where(function (Builder $q) use ($prospectId) {
                $q->where(fn (Builder $a) => $a->where('audience', 'all')
                    ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('prospects')->where('prospects.id', $prospectId)->whereNotNull('prospects.converted_at')->whereNull('prospects.deleted_at')))
                    ->orWhere(fn (Builder $o) => $o->where('audience', 'owned')->whereHas('product.prospects',
                        fn (Builder $p) => $p->where('prospects.id', $prospectId)->whereNotNull('prospect_products.purchased_at')))
                    ->orWhere(fn (Builder $s) => $s->where('audience', 'specific')->whereHas('customers', fn (Builder $c) => $c->where('prospects.id', $prospectId)));
            });
    }
}
