<?php

namespace App\Http\Requests\Api\V1;

class ConvertCustomerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'note' => ['nullable', 'string', 'max:5000'],
            'won_stage_id' => ['nullable', 'integer', 'exists:sales_stages,id'],
        ];
    }
}
