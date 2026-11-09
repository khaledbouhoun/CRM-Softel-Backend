<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/** Seeds products only when config/crm_seed.php lists some (idempotent). */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('crm_seed.products', []) as $product) {
            Product::firstOrCreate(
                ['name' => $product['name']],
                [
                    'description' => $product['description'] ?? null,
                    'is_active' => $product['is_active'] ?? true,
                ],
            );
        }
    }
}
