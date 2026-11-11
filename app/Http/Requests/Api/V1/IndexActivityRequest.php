<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ActivityType;
use Illuminate\Validation\Rule;

class IndexActivityRequest extends ApiFormRequest
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
            'prospect_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', Rule::enum(ActivityType::class)],
            // 1 = only activities linked to a prospect, 0 = only unlinked ones.
            'linked' => ['sometimes', 'boolean'],
            'origin' => ['sometimes', 'in:direct,follow_up'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            ...$this->paginationRules(),
        ];
    }
}
