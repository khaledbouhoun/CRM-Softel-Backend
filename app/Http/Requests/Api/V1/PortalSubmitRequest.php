<?php

namespace App\Http\Requests\Api\V1;

class PortalSubmitRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:demo,quotation,trial'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'company' => ['required', 'string', 'max:150'],
            'contact_name' => ['required', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:'.self::PHONE_REGEX],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'wilaya' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'business_activity' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'slot_start' => ['nullable', 'date'],
        ];
    }
}
