<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentType extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_bookable' => 'boolean', 'is_active' => 'boolean'];
    }
}
