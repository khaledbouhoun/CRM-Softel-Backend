<?php

namespace Database\Seeders;

use App\Models\LossReason;
use Illuminate\Database\Seeder;

/** Seeds loss reasons from config/crm_seed.php (idempotent). */
class LossReasonSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('crm_seed.loss_reasons', []) as $index => $reason) {
            LossReason::firstOrCreate(
                ['name' => $reason['name']],
                [
                    'description' => $reason['description'] ?? null,
                    'requires_note' => $reason['requires_note'] ?? false,
                    'sort_order' => $reason['sort_order'] ?? $index + 1,
                    'is_active' => $reason['is_active'] ?? true,
                ],
            );
        }
    }
}
