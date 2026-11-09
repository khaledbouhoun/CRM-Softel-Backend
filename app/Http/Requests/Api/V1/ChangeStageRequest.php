<?php

namespace App\Http\Requests\Api\V1;

/**
 * loss_reason_id / loss_note are conditionally required depending on the
 * target stage type and the loss reason configuration; that check lives in
 * StageTransitionService so it is shared with prospect creation.
 */
class ChangeStageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'sales_stage_id' => ['required', 'integer', 'exists:sales_stages,id'],
            'loss_reason_id' => ['sometimes', 'nullable', 'integer', 'exists:loss_reasons,id'],
            'loss_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
