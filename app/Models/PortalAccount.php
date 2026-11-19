<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer-facing identity. Fully separate from employee access. */
class PortalAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id', 'name', 'email', 'phone', 'password', 'locale', 'is_active',
        'google_id', 'avatar_url', 'company', 'contact_name', 'job_title', 'wilaya', 'city', 'business_activity', 'onboarded_at', 'email_verified_at', 'email_verification_token', 'email_verification_expires_at',
    ];

    protected $hidden = ['password', 'email_verification_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
            'last_login_at' => LocalDateTime::class,
            'onboarded_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ])->save();
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class)->withTrashed();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PortalToken::class);
    }
}
