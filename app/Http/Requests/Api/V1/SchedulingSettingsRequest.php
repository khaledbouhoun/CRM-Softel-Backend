<?php

namespace App\Http\Requests\Api\V1;

class SchedulingSettingsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'working_days' => ['sometimes', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:0,6', 'distinct'],
            'work_start' => ['sometimes', 'date_format:H:i'],
            'work_end' => ['sometimes', 'date_format:H:i', 'after:work_start'],
            'slot_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'hold_expiry_minutes' => ['sometimes', 'integer', 'between:5,43200'],
            'booking_horizon_days' => ['sometimes', 'integer', 'between:1,365'],
            'min_notice_hours' => ['sometimes', 'integer', 'between:0,720'],
            'demo_capacity' => ['sometimes', 'integer', 'between:1,50'],
            'accompaniment_occurrences' => ['sometimes', 'integer', 'between:1,52'],
            'accompaniment_interval_days' => ['sometimes', 'integer', 'between:1,365'],
            'trial_default_days' => ['sometimes', 'integer', 'between:1,365'],
            'whatsapp_country_code' => ['sometimes', 'string', 'regex:/^[0-9]{1,4}$/'],
        ];
    }
}
