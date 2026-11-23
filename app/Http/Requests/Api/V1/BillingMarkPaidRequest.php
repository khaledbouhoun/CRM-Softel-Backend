<?php

namespace App\Http\Requests\Api\V1;

class BillingMarkPaidRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['paid_at' => ['nullable', 'date', $this->notAfterToday()]];
    }
}
