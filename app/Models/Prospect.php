<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use App\Enums\FollowUpStatus;
use App\Enums\ProductRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prospect extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * sales_stage_id, loss_reason_id and loss_note are deliberately not
     * fillable: they only change through StageTransitionService so that every
     * change is validated and written to the stage history.
     */
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'language',
        'address',
        'city',
        'business_activity',
        'business_activity_details',
        'profession',
        'profession_details',
        'source',
        'source_details',
        'source_other',
        'previous_software',
        'previous_software_details',
        'notes',
    ];

    protected function casts(): array
    {
        return ['converted_at' => LocalDateTime::class];
    }

    /** Prospects/customers linked to a product (interest and/or ownership, see ProductRelation). */
    public function scopeForProduct(Builder $query, int $productId, ProductRelation|string $relation = ProductRelation::Linked): Builder
    {
        $relation = ProductRelation::from($relation instanceof ProductRelation ? $relation->value : $relation);

        return $query->whereHas('products', function (Builder $q) use ($productId, $relation) {
            $q->where('products.id', $productId);

            match ($relation) {
                ProductRelation::Owned => $q->whereNotNull('prospect_products.purchased_at'),
                ProductRelation::Interested => $q->whereNull('prospect_products.purchased_at'),
                ProductRelation::Linked => null,
            };
        });
    }

    /** A customer is a prospect that went through the explicit conversion. */
    public function isCustomer(): bool
    {
        return $this->converted_at !== null;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function renewalRequests(): HasMany
    {
        return $this->hasMany(RenewalRequest::class);
    }

    public function portalAccounts(): HasMany
    {
        return $this->hasMany(PortalAccount::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function trials(): HasMany
    {
        return $this->hasMany(Trial::class);
    }

    public function salesStage(): BelongsTo
    {
        return $this->belongsTo(SalesStage::class);
    }

    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'prospect_products')
            ->using(ProspectProduct::class)
            ->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }

    public function pendingFollowUps(): HasMany
    {
        return $this->followUps()->where('status', FollowUpStatus::Pending->value);
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(ProspectStageHistory::class);
    }

    /** Relations every prospect response includes. */
    public static function defaultRelations(): array
    {
        return ['salesStage', 'lossReason', 'products'];
    }
}
