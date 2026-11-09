<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StageType;
use Illuminate\Validation\Rule;

class IndexProspectRequest extends ApiFormRequest
{
    public const SORTABLE = ['name', 'city', 'created_at', 'updated_at'];

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sales_stage_id' => ['sometimes', 'integer'],
            'stage_type' => ['sometimes', Rule::enum(StageType::class)],
            'product_id' => ['sometimes', 'integer'],
            'loss_reason_id' => ['sometimes', 'integer'],
            'city' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', 'string', 'max:50'],
            'business_activity' => ['sometimes', 'string', 'max:150'],
            'previous_software' => ['sometimes', 'string', 'max:150'],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'updated_from' => ['sometimes', 'date_format:Y-m-d'],
            'updated_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:updated_from'],
            'sort' => ['sometimes', Rule::in(self::SORTABLE)],
            ...$this->paginationRules(),
        ];
    }
}
