<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FollowUpActivityType;
use Illuminate\Validation\Rule;

/** Body of POST /follow-ups/{id}/convert-to-activity (all fields optional). */
class ConvertFollowUpRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('activity_type') && $this->input('activity_type') === 'appointment') {
            $this->merge(['activity_type' => 'meeting']);
        }
    }

    public function rules(): array
    {
        return self::activityRules();
    }

    public static function activityRules(): array
    {
        return [
            // Only used when the follow-up has no type of its own (older rows).
            'activity_type' => ['sometimes', 'nullable', Rule::enum(FollowUpActivityType::class)],
            'activity_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:2000-01-01'],
        ];
    }
}
