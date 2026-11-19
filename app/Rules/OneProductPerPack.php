<?php

namespace App\Rules;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Applied to an array of product ids: no two products may share the same pack_id. */
class OneProductPerPack implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $ids = array_values(array_unique(array_filter($value, 'is_numeric')));
        $packs = Product::query()->whereIn('id', $ids)->whereNotNull('pack_id')->with('pack:id,name')->get()->groupBy('pack_id');

        foreach ($packs as $products) {
            if ($products->count() > 1) {
                $fail('Only one product can be selected from the "'.$products->first()->pack->name.'" pack.');

                return;
            }
        }
    }
}
