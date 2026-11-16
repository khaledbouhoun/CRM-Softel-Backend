<?php

namespace App\Http\Requests\Api\V1;

class CalendarRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee' => ['sometimes', 'string', 'max:100'],
            'prospect_id' => ['sometimes', 'integer'],
            'product_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', 'string', 'max:20'],
            'kinds' => ['sometimes', 'array'],
            'kinds.*' => ['in:appointment,follow_up,intervention'],
        ];
    }
}
