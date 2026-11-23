<?php

namespace App\Http\Requests\Api\V1;

class BillingQuoteRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['amount_dzd' => ['required', 'integer', 'min:1', 'max:1000000000']];
    }
}
