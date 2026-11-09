<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductService
{
    private const FIELDS = ['name', 'description', 'is_active'];

    public function create(array $data): Product
    {
        $product = new Product(Arr::only($data, self::FIELDS));
        $product->is_active ??= true;
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
