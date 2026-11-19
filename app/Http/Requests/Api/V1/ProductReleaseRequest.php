<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProductRelease;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create (POST, may carry the installer as multipart) and partial update (PUT, JSON) of a release. */
class ProductReleaseRequest extends ApiFormRequest
{
    public function rules(): array
    {
        /** @var ProductRelease|null $release */
        $release = $this->route('release');
        $required = $release ? ['sometimes', 'required'] : ['required'];
        $productId = $this->input('product_id', $release?->product_id);

        return [
            'product_id' => [...$required, 'integer', 'exists:products,id'],
            'version_number' => [...$required, 'string', 'max:50', Rule::unique('product_releases', 'version_number')->where('product_id', $productId)->ignore($release?->id)],
            'changelog' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'download_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'release_date' => [...$required, 'date'],
            'audience' => [...$required, Rule::in(ProductRelease::AUDIENCES)],
            'customer_ids' => ['sometimes', 'array', 'max:1000'],
            'customer_ids.*' => ['integer', 'distinct', 'exists:prospects,id'],
            'is_published' => ['sometimes', 'boolean'],
            'installer' => ['sometimes', 'file', 'max:'.config('crm.uploads.installer_max_kb'), 'extensions:'.implode(',', config('crm.uploads.installer_extensions'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $audience = $this->input('audience', $this->route('release')?->audience);
            $count = $this->has('customer_ids') ? count((array) $this->input('customer_ids')) : ($this->route('release')?->customers()->count() ?? 0);

            if ($audience === 'specific' && $count === 0) {
                $v->errors()->add('customer_ids', 'Choose at least one customer for a specific audience.');
            }
        });
    }
}
