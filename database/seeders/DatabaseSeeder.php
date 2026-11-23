<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SalesStageSeeder::class,
            LossReasonSeeder::class,
            ProductSeeder::class,
            ProspectFormOptionSeeder::class,
            PortalDefaultsSeeder::class,
        ]);

        // Fictitious demo records: never in tests, never on the production database
        // (`php artisan db:seed --force` is part of the production install steps).
        if (! app()->runningUnitTests() && ! app()->isProduction()) {
            $this->call(CrmDemoDataSeeder::class);
        }
    }
}
