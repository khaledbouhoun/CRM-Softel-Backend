<?php

namespace App\Http\Requests\Api\V1;

class CompleteAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['outcome' => ['required', 'string', 'max:5000']];
    }
}
