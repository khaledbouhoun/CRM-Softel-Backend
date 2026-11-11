<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ActivityType;
use Illuminate\Validation\Rule;

/** Body of POST /prospects/{id}/activities (the prospect comes from the URL). */
class StoreActivityRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('type')) {
            $type = $this->input('type');
            if ($type === 'appointment') {
                $this->merge(['type' => 'meeting']);
            } elseif ($type === 'note') {
                $this->merge(['type' => 'other']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ActivityType::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:2', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Defaults to "now" when omitted. Activities record what happened,
            // so they cannot be dated after today (planned work = follow-up).
            'activity_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:2000-01-01', $this->notAfterToday()],
        ];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'The type must be one of: '.implode(', ', ActivityType::values()).'.',
        ];
    }
}
