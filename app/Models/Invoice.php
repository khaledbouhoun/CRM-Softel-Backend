<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const TYPES = ['license', 'maintenance_renewal'];

    public const PAID_CASH = 'paid_cash';

    public const PENDING_CASH = 'pending_cash';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => LocalDateTime::class, 'paid_at' => LocalDateTime::class, 'amount_dzd' => 'integer'];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function renewalRequest(): BelongsTo
    {
        return $this->belongsTo(RenewalRequest::class);
    }

    public function hasPdf(): bool
    {
        return filled($this->pdf_path);
    }
}
