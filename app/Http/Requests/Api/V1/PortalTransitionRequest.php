<?php

namespace App\Http\Requests\Api\V1;

class PortalTransitionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer', 'exists:intervention_statuses,id'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
