<?php

namespace App\Http\Requests\Api\V1;

/**
 * Body of POST /sales-stages/reorder and /loss-reasons/reorder:
 * { "ids": [3, 1, 2, ...] } — every existing id, in the desired order.
 */
class ReorderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
