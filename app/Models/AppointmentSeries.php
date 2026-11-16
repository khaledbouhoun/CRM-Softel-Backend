<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSeries extends Model
{
    protected $table = 'appointment_series';

    protected $guarded = [];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'series_id')->orderBy('starts_at');
    }
}
