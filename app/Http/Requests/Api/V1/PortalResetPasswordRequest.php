<?php

namespace App\Http\Requests\Api\V1;

class PortalResetPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
