<?php

namespace App\Http\Requests\Api\V1;

class ReopenInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status_id' => ['nullable', 'integer', 'exists:intervention_statuses,id'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
