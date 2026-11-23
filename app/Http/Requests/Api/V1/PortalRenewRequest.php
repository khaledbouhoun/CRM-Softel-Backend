<?php

namespace App\Http\Requests\Api\V1;

class PortalRenewRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'years' => ['required', 'integer', 'min:1', 'max:5'],
            'workstations_count' => ['required', 'integer', 'min:1', 'max:999'],
            'dossiers_count' => ['required', 'integer', 'min:1', 'max:999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
