<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterventionStatus extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'is_initial' => 'boolean', 'is_closed' => 'boolean',
            'awaits_customer' => 'boolean', 'is_delivered' => 'boolean',
        ];
    }
}
