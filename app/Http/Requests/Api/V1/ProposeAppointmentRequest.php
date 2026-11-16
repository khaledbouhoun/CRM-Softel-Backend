<?php

namespace App\Http\Requests\Api\V1;

class ProposeAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
