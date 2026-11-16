<?php

namespace App\Http\Requests\Api\V1;

class ConfirmAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'meeting_url' => ['nullable', 'url:http,https', 'max:500'],
            'assigned_employee' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
