<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    private const FIELDS = [
        'name', 'description', 'is_active', 'pack_id', 'slug', 'image_path', 'gallery',
        'full_description', 'features', 'specifications', 'is_active_on_web', 'demo_url',
        'title_i18n', 'summary_i18n', 'description_i18n', 'tech_stack_i18n', 'sort_order',
    ];

    public function create(array $data): Product
    {
        $product = new Product(Arr::only($data, self::FIELDS));
        $product->is_active ??= true;
        $product->slug = $product->slug ?: Str::slug($product->name);
        $product->save();

        return $product->refresh();
    }

    public function update(Product $product, array $data): Product
    {
        $product->fill(Arr::only($data, self::FIELDS));
        $product->save();

        return $product;
    }

    /** Products linked to any prospect are never physically deleted. */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            if ($product->isInUse()) {
                throw BusinessRuleException::resourceInUse(
                    "The product \"{$product->name}\" is associated with prospects and cannot be deleted. Deactivate it instead (PUT with is_active=false).",
                );
            }

            $product->delete();
        });
    }
}
