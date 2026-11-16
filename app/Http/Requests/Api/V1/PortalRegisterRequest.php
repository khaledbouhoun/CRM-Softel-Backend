<?php

namespace App\Http\Requests\Api\V1;

class PortalRegisterRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:191', 'unique:portal_accounts,email'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:'.self::PHONE_REGEX],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'locale' => ['nullable', 'in:ar,en,fr'],
        ];
    }
}
