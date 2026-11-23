<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Invoice;
use Illuminate\Validation\Rule;

class BillingInvoiceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Invoice::TYPES)],
            'amount_dzd' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'status' => ['sometimes', Rule::in([Invoice::PAID_CASH, Invoice::PENDING_CASH])],
            'paid_at' => ['nullable', 'date', $this->notAfterToday()],
        ];
    }
}
