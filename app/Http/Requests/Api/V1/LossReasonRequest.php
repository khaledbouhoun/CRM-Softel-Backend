<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LossReason;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PUT) of a loss reason. */
class LossReasonRequest extends ApiFormRequest
{
    public function rules(): array
    {
        /** @var LossReason|null $reason */
        $reason = $this->route('lossReason');
        $required = $reason ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:100', Rule::unique('loss_reasons', 'name')->ignore($reason?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'requires_note' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
