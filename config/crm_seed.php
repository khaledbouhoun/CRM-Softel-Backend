<?php

/*
|--------------------------------------------------------------------------
| Initial CRM reference data
|--------------------------------------------------------------------------
|
| Used only by `php artisan db:seed`. Edit these lists BEFORE the first
| seeding to match the company's real pipeline. After that, manage stages,
| products and loss reasons through the API (no code change needed).
|
| Seeding is idempotent: rows are matched by name and existing rows are never
| overwritten, so re-running the seeder will not undo changes made in the app.
|
| stage_type must be one of: open, won, lost. The pipeline needs at least one
| active "open" stage (new prospects default to the first one).
|
*/

return [

    // Minimal starter pipeline (one stage per type). Replace or extend it with
    // the company's actual stages.
    'sales_stages' => [
        ['name' => 'Nouveau', 'stage_type' => 'open', 'color' => '#2563EB', 'description' => null],
        ['name' => 'Gagné', 'stage_type' => 'won', 'color' => '#16A34A', 'description' => null],
        ['name' => 'Perdu', 'stage_type' => 'lost', 'color' => '#DC2626', 'description' => null],
    ],

    'loss_reasons' => [
        ['name' => 'Trop cher', 'requires_note' => false],
        ['name' => 'Pas intéressé', 'requires_note' => false],
        ['name' => 'A choisi un concurrent', 'requires_note' => false],
        ['name' => 'Pas de réponse', 'requires_note' => false],
        ['name' => 'Fonctionnalité manquante', 'requires_note' => true],
        ['name' => 'Projet reporté', 'requires_note' => false],
        ['name' => 'Autre', 'requires_note' => true],
    ],

    // Products are seeded only when listed here explicitly. Example:
    // ['name' => 'Logiciel de gestion commerciale', 'description' => null],
    'products' => [],

];
