<?php

namespace Database\Seeders;

use App\Models\ProspectFormOption;
use Illuminate\Database\Seeder;

/** Seeds prospect form options from config/crm_seed.php (idempotent). */
class ProspectFormOptionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = config('crm_seed.prospect_form_options', []);

        foreach ($catalog as $type => $options) {
            foreach ($options as $index => $option) {
                ProspectFormOption::firstOrCreate(
                    [
                        'type' => $type,
                        'name' => $option['name'],
                    ],
                    [
                        'requires_details' => $option['requires_details'] ?? false,
                        'sort_order' => $option['sort_order'] ?? ($index + 1),
                        'is_active' => $option['is_active'] ?? true,
                    ]
                );
            }
        }
    }
}
