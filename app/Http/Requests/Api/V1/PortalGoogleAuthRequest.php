<?php

namespace App\Http\Requests\Api\V1;

class PortalGoogleAuthRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'google_id' => ['required', 'string', 'max:191'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'name' => ['required', 'string', 'max:150'],
            'locale' => ['nullable', 'in:ar,en,fr'],
            // Only https pictures are kept: the URL is rendered in other people's browsers.
            'avatar_url' => ['nullable', 'string', 'max:500', 'url:https'],
        ];
    }
}
