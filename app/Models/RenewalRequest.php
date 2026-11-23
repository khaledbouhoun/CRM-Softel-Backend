<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RenewalRequest extends Model
{
    /** The customer asked; staff have not priced it yet. */
    public const PENDING_QUOTE = 'pending_quote';

    /** Staff issued the proforma: the customer can pay cash. */
    public const QUOTED = 'quoted';

    /** Cash received, maintenance extended. */
    public const PAID = 'paid_cash';

    public const REJECTED = 'rejected';

    /** Requests still waiting for staff action or for the customer's cash. */
    public const OPEN = [self::PENDING_QUOTE, self::QUOTED];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => LocalDateTime::class, 'quoted_at' => LocalDateTime::class, 'years' => 'integer', 'amount_dzd' => 'integer', 'workstations_count' => 'integer', 'dossiers_count' => 'integer'];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
