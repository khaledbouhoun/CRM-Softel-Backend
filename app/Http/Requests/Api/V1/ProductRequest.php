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
        ];
    }
}
