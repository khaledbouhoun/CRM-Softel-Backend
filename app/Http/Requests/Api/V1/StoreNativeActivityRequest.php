<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ActivityType;
use Illuminate\Validation\Rule;

/**
 * Body of POST /activities: an activity created on its own. The prospect is
 * optional and can be set later with POST /activities/{id}/link-prospect.
 */
class StoreNativeActivityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ActivityType::class)],
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'activity_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:2000-01-01', $this->notAfterToday()],
            'prospect_id' => ['sometimes', 'nullable', 'integer', Rule::exists('prospects', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'The type must be one of: '.implode(', ', ActivityType::values()).'.',
            'prospect_id.exists' => 'The selected prospect does not exist.',
        ];
    }
}
