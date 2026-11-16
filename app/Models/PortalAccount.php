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

    protected $fillable = ['prospect_id', 'name', 'email', 'phone', 'password', 'locale', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'password' => 'hashed', 'last_login_at' => LocalDateTime::class];
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
