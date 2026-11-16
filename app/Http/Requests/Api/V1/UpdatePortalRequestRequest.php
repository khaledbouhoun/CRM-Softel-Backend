<?php

namespace App\Http\Requests\Api\V1;

class UpdatePortalRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:handled,rejected,cancelled'],
            'customer_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
