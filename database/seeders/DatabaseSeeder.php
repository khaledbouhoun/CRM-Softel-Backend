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
        ]);
    }
}
