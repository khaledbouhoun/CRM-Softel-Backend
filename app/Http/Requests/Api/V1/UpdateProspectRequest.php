<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Prospect;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Partial update: only submitted fields change. If product_ids is submitted it
 * replaces the whole product list.
 *
 * The stage and loss fields may be echoed back unchanged (so clients can PUT
 * the object they received) but cannot be changed here: stage changes must go
 * through POST /prospects/{id}/change-stage so they are validated and recorded
 * in the stage history.
 */
class UpdateProspectRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            ...StoreProspectRequest::contactRules(),
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sales_stage_id' => ['sometimes', $this->unchanged('sales_stage_id')],
            'loss_reason_id' => ['sometimes', $this->unchanged('loss_reason_id')],
            'loss_note' => ['sometimes', $this->unchanged('loss_note')],
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            StoreProspectRequest::validateConfigurableDetails($this->all(), $v);
        });
    }

    public function messages(): array
    {
        return (new StoreProspectRequest)->messages();
    }

    private function unchanged(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            /** @var Prospect $prospect */
            $prospect = $this->route('prospect');

            if ((string) $value !== (string) $prospect->{$field}) {
                $fail("The {$attribute} cannot be changed here. Use POST /api/v1/prospects/{$prospect->id}/change-stage.");
            }
        };
    }
}
