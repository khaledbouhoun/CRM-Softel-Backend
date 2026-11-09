<?php

namespace App\Http\Requests\Api\V1;

/** Body of POST /follow-ups/{id}/complete and /cancel. */
class CloseFollowUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'closing_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
