<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterventionStatusTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_allowed' => 'boolean', 'is_active' => 'boolean'];
    }
}
