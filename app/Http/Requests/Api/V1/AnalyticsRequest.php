<?php

namespace App\Http\Requests\Api\V1;

class AnalyticsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'in:month,quarter,year,all'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
