<?php

namespace App\Http\Requests\Api\V1;

class BillingReceiptRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'receipt' => ['required', 'file', 'max:'.config('crm.billing.receipt_max_kb'), 'extensions:'.implode(',', config('crm.billing.receipt_extensions'))],
        ];
    }
}
