<?php

namespace App\Http\Requests\Api\V1;

/**
 * Field format + existence checks. Business rules (active stage, active
 * products, loss reason requirements) are enforced in ProspectService /
 * StageTransitionService and reported with the same 422 structure.
 */
class StoreProspectRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            ...self::contactRules(),
            'name' => ['required', 'string', 'max:150'],
            'sales_stage_id' => ['sometimes', 'nullable', 'integer', 'exists:sales_stages,id'],
            'loss_reason_id' => ['sometimes', 'nullable', 'integer', 'exists:loss_reasons,id'],
            'loss_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }

    public static function contactRules(): array
    {
        return [
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:'.self::PHONE_REGEX],
            'email' => ['sometimes', 'nullable', 'string', 'max:191', 'email:rfc'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_activity' => ['sometimes', 'nullable', 'string', 'max:150'],
            // Stable code (facebook, website, ...). New codes are accepted so the catalogue can grow.
            'source' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'source_other' => ['sometimes', 'nullable', 'string', 'max:150'],
            'previous_software' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number format is invalid. Use digits, spaces, dots, dashes, parentheses and an optional leading +.',
            'source.regex' => 'The source must be a code made of lowercase letters, digits and underscores (e.g. "facebook").',
            'product_ids.*.distinct' => 'The same product cannot be selected twice.',
            'product_ids.*.exists' => 'The selected product does not exist.',
        ];
    }
}
