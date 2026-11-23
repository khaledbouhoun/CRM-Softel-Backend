<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PortalAccount;
use App\Rules\OneProductPerPack;
use App\Rules\ValidWilaya;
use App\Services\PortalProfileService;
use App\Support\Wilayas;

class PortalSubmitRequest extends ApiFormRequest
{
    /** A returning customer only picks the new request: whatever is missing comes from their saved details. */
    protected function prepareForValidation(): void
    {
        $account = $this->attributes->get('portal_account');
        $merge = [];

        if ($account instanceof PortalAccount) {
            foreach (app(PortalProfileService::class)->requestDefaults($account) as $field => $value) {
                if (! $this->filled($field) && filled($value)) {
                    $merge[$field] = $value;
                }
            }
        }

        $wilaya = $merge['wilaya'] ?? $this->input('wilaya');

        if (is_string($wilaya)) {
            $merge['wilaya'] = Wilayas::canonical($wilaya);
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:demo,quotation,trial'],
            // One product (product_id) or several (product_ids), never two from the same pack.
            'product_id' => ['required_without:product_ids', 'prohibits:product_ids', 'integer', 'exists:products,id'],
            'product_ids' => ['required_without:product_id', 'array', 'min:1', 'max:20', new OneProductPerPack],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'company' => ['required', 'string', 'max:150'],
            'contact_name' => ['required', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:'.self::PHONE_REGEX],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'wilaya' => ['required', 'string', 'max:100', new ValidWilaya],
            'city' => ['required', 'string', 'max:100'],
            'business_activity' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'slot_start' => ['nullable', 'date'],
        ];
    }
}
