<?php

namespace App\Http\Requests\Api\V1;

class PortalMessageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:10000']];
    }
}
