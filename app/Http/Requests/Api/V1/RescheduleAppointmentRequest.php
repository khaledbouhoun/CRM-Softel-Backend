<?php

namespace App\Http\Requests\Api\V1;

class RescheduleAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'assigned_employee' => ['nullable', 'string', 'max:100'],
        ];
    }
}
