<?php

namespace App\Http\Requests\Api\V1;

class StoreInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'intervention_type_id' => ['required', 'integer', 'exists:intervention_types,id'],
            'intervention_priority_id' => ['nullable', 'integer', 'exists:intervention_priorities,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'customer_ids' => ['sometimes', 'array', 'max:500'],
            'customer_ids.*' => ['integer', 'distinct', 'exists:prospects,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'assigned_employee' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
