<?php

namespace App\Http\Requests\Api\V1;

class ActivateTrialRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'url' => ['required', 'url:http,https', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
