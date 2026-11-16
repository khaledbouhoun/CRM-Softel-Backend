<?php

namespace App\Http\Requests\Api\V1;

class AppointmentReasonRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:2000']];
    }
}
