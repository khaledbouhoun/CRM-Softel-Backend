<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FollowUpActivityType;
use Illuminate\Validation\Rule;

/** Partial update of a pending follow-up (status changes use complete/cancel). */
class UpdateFollowUpRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('activity_type') && $this->input('activity_type') === 'appointment') {
            $this->merge(['activity_type' => 'meeting']);
        }
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_type' => ['sometimes', 'nullable', Rule::enum(FollowUpActivityType::class)],
            'scheduled_at' => ['sometimes', 'required', 'date', $this->notBeforeToday()],
            'status' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'prospect_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'Use POST /follow-ups/{id}/complete or /cancel to change the status.',
            'completed_at.prohibited' => 'completed_at is set automatically when the follow-up is completed.',
            'cancelled_at.prohibited' => 'cancelled_at is set automatically when the follow-up is cancelled.',
            'prospect_id.prohibited' => 'A follow-up cannot be moved to another prospect.',
        ];
    }
}
