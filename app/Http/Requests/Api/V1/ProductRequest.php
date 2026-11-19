<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PUT) of a product. */
class ProductRequest extends ApiFormRequest
{
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $required = $product ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:150', Rule::unique('products', 'name')->ignore($product?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'pack_id' => ['sometimes', 'nullable', 'integer', 'exists:packs,id'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gallery' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery.*' => ['string', 'max:500'],
            'full_description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'features' => ['sometimes', 'nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:300'],
            'specifications' => ['sometimes', 'nullable', 'array', 'max:50'],
            'is_active_on_web' => ['sometimes', 'boolean'],
            'demo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
        ];
    }
}
