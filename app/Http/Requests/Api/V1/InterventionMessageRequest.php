<?php

namespace App\Http\Requests\Api\V1;

class InterventionMessageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'visibility' => ['required', 'in:customer,internal'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }
}
