<?php

namespace App\Http\Requests\Api\V1;

class DashboardRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'recent_limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'upcoming_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
    }
}
