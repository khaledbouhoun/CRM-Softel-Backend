<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
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
