<?php

namespace Database\Seeders;

use App\Enums\StageType;
use App\Models\SalesStage;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/** Seeds the starter pipeline from config/crm_seed.php (idempotent). */
class SalesStageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('crm_seed.sales_stages', []) as $index => $stage) {
            if (StageType::tryFrom($stage['stage_type'] ?? '') === null) {
                throw new InvalidArgumentException("Invalid stage_type for seeded stage [{$stage['name']}].");
            }

            SalesStage::firstOrCreate(
                ['name' => $stage['name']],
                [
                    'description' => $stage['description'] ?? null,
                    'color' => $stage['color'] ?? null,
                    'stage_type' => $stage['stage_type'],
                    'sort_order' => $stage['sort_order'] ?? $index + 1,
                    'is_active' => $stage['is_active'] ?? true,
                ],
            );
        }
    }
}
