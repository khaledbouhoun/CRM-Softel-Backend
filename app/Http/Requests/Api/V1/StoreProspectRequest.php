<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProspectFormOption;
use Illuminate\Validation\Validator;

/**
 * Field format + existence checks. Business rules (active stage, active
 * products, loss reason requirements, configurable list details) are enforced
 * in ProspectService / StageTransitionService and reported with the same 422 structure.
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
            'business_activity_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profession' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profession_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => [
                'sometimes', 'nullable', 'string', 'max:150',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $trimmed = trim($value);
                    if (preg_match('/^[a-z][a-z0-9_]*$/', $trimmed)) {
                        return;
                    }
                    if (ProspectFormOption::where('type', 'source')->where('name', $trimmed)->exists()) {
                        return;
                    }
                    $fail('The source must be a code made of lowercase letters, digits and underscores (e.g. "facebook").');
                },
            ],
            'source_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_other' => ['sometimes', 'nullable', 'string', 'max:150'],
            'previous_software' => ['sometimes', 'nullable', 'string', 'max:150'],
            'previous_software_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            self::validateConfigurableDetails($this->all(), $v);
        });
    }

    public static function validateConfigurableDetails(array $input, Validator $v): void
    {
        $fields = [
            'source' => ['source_details', 'source_other'],
            'previous_software' => ['previous_software_details'],
            'business_activity' => ['business_activity_details'],
            'profession' => ['profession_details'],
        ];

        foreach ($fields as $type => $detailKeys) {
            $value = $input[$type] ?? null;
            if (!$value || !is_string($value) || trim($value) === '') {
                continue;
            }

            $trimmed = trim($value);
            $option = ProspectFormOption::query()
                ->where('type', $type)
                ->where(function ($q) use ($trimmed) {
                    $q->where('name', $trimmed)
                      ->orWhereRaw('LOWER(name) = ?', [strtolower($trimmed)]);
                })
                ->first();

            if ($option && $option->requires_details) {
                $hasDetail = false;
                foreach ($detailKeys as $key) {
                    if (!empty($input[$key]) && trim((string) $input[$key]) !== '') {
                        $hasDetail = true;
                        break;
                    }
                }

                if (!$hasDetail) {
                    $primaryKey = $detailKeys[0];
                    $v->errors()->add(
                        $primaryKey,
                        "Details are required when selecting \"{$option->name}\"."
                    );
                }
            }
        }
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number format is invalid. Use digits, spaces, dots, dashes, parentheses and an optional leading +.',
            'product_ids.*.distinct' => 'The same product cannot be selected twice.',
            'product_ids.*.exists' => 'The selected product does not exist.',
        ];
    }
}
