<?php

namespace App\Http\Requests\Api\V1;

class BillingMaintenanceRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
        ];
    }
}
