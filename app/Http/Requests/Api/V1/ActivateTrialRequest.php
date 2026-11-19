<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\OneProductPerPack;

class ActivateTrialRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['required_without:product_ids', 'prohibits:product_ids', 'integer', 'exists:products,id'],
            'product_ids' => ['required_without:product_id', 'array', 'min:1', 'max:20', new OneProductPerPack],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            // The demo link is the product's fixed demo_url; manual URLs are no longer accepted.
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
