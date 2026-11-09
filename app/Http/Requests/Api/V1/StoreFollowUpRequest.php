<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FollowUpActivityType;
use Illuminate\Validation\Rule;

class StoreFollowUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_type' => ['sometimes', 'nullable', Rule::enum(FollowUpActivityType::class)],
            'scheduled_at' => ['required', 'date', $this->notBeforeToday()],
        ];
    }
}
