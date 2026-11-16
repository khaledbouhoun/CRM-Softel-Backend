<?php

namespace App\Models;

use App\Casts\LocalDateTime;
use Illuminate\Database\Eloquent\Model;

class PortalPasswordReset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => LocalDateTime::class, 'used_at' => LocalDateTime::class];
    }
}
