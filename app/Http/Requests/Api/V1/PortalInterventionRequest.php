<?php

namespace App\Http\Requests\Api\V1;

class PortalInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'intervention_type_id' => ['required', 'integer', 'exists:intervention_types,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
        ];
    }
}
