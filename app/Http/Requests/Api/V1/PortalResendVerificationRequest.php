<?php

namespace App\Http\Requests\Api\V1;

class PortalResendVerificationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
        ];
    }
}
