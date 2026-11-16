<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }
}
