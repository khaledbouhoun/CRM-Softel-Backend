<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

/** Body of POST /activities/{id}/link-prospect. */
class LinkActivityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'prospect_id' => ['required', 'integer', Rule::exists('prospects', 'id')->whereNull('deleted_at')],
            // Must be true to move an activity that already belongs to another prospect.
            'confirm_relink' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['prospect_id.exists' => 'The selected prospect does not exist.'];
    }
}
