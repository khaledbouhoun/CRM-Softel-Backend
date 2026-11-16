<?php

namespace App\Http\Requests\Api\V1;

class IndexInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'status_id' => ['sometimes', 'integer'],
            'priority_id' => ['sometimes', 'integer'],
            'type_id' => ['sometimes', 'integer'],
            'source' => ['sometimes', 'in:customer,employee'],
            'assigned_employee' => ['sometimes', 'string', 'max:100'],
            'prospect_id' => ['sometimes', 'integer'],
            'open' => ['sometimes', 'boolean'],
        ];
    }
}
