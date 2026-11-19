<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductRelation;
use App\Enums\StageType;
use Illuminate\Validation\Rule;

/** Filters for the (non-paginated) products / sales stages / loss reasons lists. */
class ConfigListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'stage_type' => ['sometimes', Rule::enum(StageType::class)],
            // Products only: narrow to what one prospect/customer is interested in or owns.
            'prospect_id' => ['sometimes', 'integer', 'exists:prospects,id'],
            'relation' => ['sometimes', Rule::enum(ProductRelation::class)],
            'has_demo' => ['sometimes', Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
            'pack_id' => ['sometimes', 'integer', 'exists:packs,id'],
        ];
    }
}
