<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of one stage transition. Related stages and loss reasons
 * are loaded regardless of their is_active flag, so history stays readable
 * after configuration changes.
 */
class ProspectStageHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'from_stage_id',
        'to_stage_id',
        'loss_reason_id',
        'loss_note',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => LocalDateTime::class,
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(SalesStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(SalesStage::class, 'to_stage_id');
    }

    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class);
    }
}
