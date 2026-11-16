<?php

namespace App\Http\Requests\Api\V1;

class IndexAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'status' => ['sometimes', 'string', 'max:20'],
            'prospect_id' => ['sometimes', 'integer'],
            'group' => ['sometimes', 'string', 'max:20'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ];
    }
}
