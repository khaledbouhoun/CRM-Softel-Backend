<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FollowUpStatus;
use Illuminate\Validation\Rule;

class IndexFollowUpRequest extends ApiFormRequest
{
    /** Due windows; all imply status = pending. See FollowUpTask. */
    public const SCOPES = ['overdue', 'today', 'upcoming'];

    public const SORTABLE = ['scheduled_at', 'created_at', 'completed_at'];

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(FollowUpStatus::class)],
            'scope' => ['sometimes', Rule::in(self::SCOPES)],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'prospect_id' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTABLE)],
            ...$this->paginationRules(),
        ];
    }
}
