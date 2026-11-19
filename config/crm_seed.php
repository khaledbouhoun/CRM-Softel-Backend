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

    // Standard sales pipeline for SOFTEL CRM
    'sales_stages' => [
        ['name' => 'Nouveau', 'stage_type' => 'open', 'color' => '#2563EB', 'sort_order' => 1, 'description' => 'Nouveau prospect enregistré'],
        ['name' => 'Qualification', 'stage_type' => 'open', 'color' => '#6366F1', 'sort_order' => 2, 'description' => 'Évaluation du besoin et du budget'],
        ['name' => 'Présentation / Démo', 'stage_type' => 'open', 'color' => '#8B5CF6', 'sort_order' => 3, 'description' => 'Démonstration logicielle'],
        ['name' => 'Devis Envoyé', 'stage_type' => 'open', 'color' => '#F59E0B', 'sort_order' => 4, 'description' => 'Proposition commerciale transmise'],
        ['name' => 'Négociation', 'stage_type' => 'open', 'color' => '#0EA5E9', 'sort_order' => 5, 'description' => 'Discussion des conditions finales'],
        ['name' => 'Gagné', 'stage_type' => 'won', 'color' => '#16A34A', 'sort_order' => 6, 'description' => 'Vente conclue et convertie en client'],
        ['name' => 'Perdu', 'stage_type' => 'lost', 'color' => '#DC2626', 'sort_order' => 7, 'description' => 'Prospect perdu'],
        ['name' => 'Reporté', 'stage_type' => 'lost', 'color' => '#64748B', 'sort_order' => 8, 'description' => 'Projet reporté à une date ultérieure'],
    ],

    'loss_reasons' => [
        ['name' => 'Trop cher', 'requires_note' => false, 'sort_order' => 1],
        ['name' => 'Pas intéressé', 'requires_note' => false, 'sort_order' => 2],
        ['name' => 'A choisi un concurrent', 'requires_note' => true, 'sort_order' => 3],
        ['name' => 'Pas de réponse', 'requires_note' => false, 'sort_order' => 4],
        ['name' => 'Fonctionnalité manquante', 'requires_note' => true, 'sort_order' => 5],
        ['name' => 'Projet reporté', 'requires_note' => false, 'sort_order' => 6],
        ['name' => 'Budget insuffisant', 'requires_note' => false, 'sort_order' => 7],
        ['name' => 'Autre', 'requires_note' => true, 'sort_order' => 8],
    ],

    // SOFTEL official product catalog
    'products' => [
        [
            'name' => 'GestCom',
            'description' => 'Solution complète de gestion commerciale (achats, ventes, facturation, stocks, trésorerie et points de vente).',
        ],
        [
            'name' => 'GestCom Production',
            'description' => 'Gestion de production industrielle, ordres de fabrication (OF), nomenclatures techniques et traçabilité des lots.',
        ],
        [
            'name' => 'GestCom Importation',
            'description' => 'Gestion des dossiers d\'importation, calcul du coût de revient en devises, frais d\'approche et suivi douanier.',
        ],
        [
            'name' => 'GestCom Paie',
            'description' => 'Gestion intégrée de la paie, déclarations fiscales et sociales algériennes (CNAS, IRG, CACOBATPH) et RH.',
        ],
        [
            'name' => 'El Mouïn',
            'description' => 'Solution rapide et intuitive pour points de vente, terminaux de caisse (POS) et commerces de détail.',
        ],
    ],

    // Prospect form configurable list starter options
    'prospect_form_options' => [
        'source' => [
            ['name' => 'Facebook', 'requires_details' => true],
            ['name' => 'Instagram', 'requires_details' => true],
            ['name' => 'Friend', 'requires_details' => false],
            ['name' => 'Customer referral', 'requires_details' => false],
            ['name' => 'Website', 'requires_details' => false],
            ['name' => 'Phone call', 'requires_details' => false],
            ['name' => 'Other', 'requires_details' => true],
        ],
        'previous_software' => [
            ['name' => 'Sage', 'requires_details' => false],
            ['name' => 'PC Compta', 'requires_details' => false],
            ['name' => 'Excel', 'requires_details' => false],
            ['name' => 'Other software', 'requires_details' => true],
            ['name' => 'None', 'requires_details' => false],
            ['name' => 'Other', 'requires_details' => true],
        ],
        'business_activity' => [
            ['name' => 'Production', 'requires_details' => false],
            ['name' => 'Wholesale', 'requires_details' => false],
            ['name' => 'Commerce', 'requires_details' => false],
            ['name' => 'Construction', 'requires_details' => false],
            ['name' => 'Services', 'requires_details' => false],
            ['name' => 'Import', 'requires_details' => false],
            ['name' => 'Other', 'requires_details' => true],
        ],
        'profession' => [
            ['name' => 'Electronics', 'requires_details' => false],
            ['name' => 'Cars', 'requires_details' => false],
            ['name' => 'Food', 'requires_details' => false],
            ['name' => 'Clothing', 'requires_details' => false],
            ['name' => 'IT & Tech', 'requires_details' => false],
            ['name' => 'Other', 'requires_details' => true],
        ],
    ],

];
