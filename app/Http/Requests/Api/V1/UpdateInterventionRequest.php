<?php

namespace App\Http\Requests\Api\V1;

class UpdateInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'intervention_priority_id' => ['sometimes', 'nullable', 'integer', 'exists:intervention_priorities,id'],
            'assigned_employee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'title' => ['sometimes', 'string', 'max:200'],
        ];
    }
}
