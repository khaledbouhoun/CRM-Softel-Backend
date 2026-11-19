<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\FollowUpActivityType;
use App\Enums\FollowUpStatus;
use App\Enums\StageType;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\FollowUpTask;
use App\Models\Intervention;
use App\Models\InterventionMessage;
use App\Models\InterventionPriority;
use App\Models\InterventionStatus;
use App\Models\InterventionType;
use App\Models\LossReason;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use App\Models\ProspectStageHistory;
use App\Models\SalesStage;
use App\Models\Trial;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class CrmDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Resolve Stages
        $openStage = SalesStage::query()->where('stage_type', StageType::Open->value)->orderBy('sort_order')->first();
        $wonStage = SalesStage::query()->where('stage_type', StageType::Won->value)->orderBy('sort_order')->first();
        $lostStage = SalesStage::query()->where('stage_type', StageType::Lost->value)->orderBy('sort_order')->first();

        $demoStage = SalesStage::query()->where('name', 'like', '%Démo%')
            ->orWhere('name', 'like', '%Présent%')
            ->first() ?? $openStage;

        $devisStage = SalesStage::query()->where('name', 'like', '%Devis%')
            ->first() ?? $openStage;

        $postponedStage = SalesStage::query()->where('name', 'like', '%Report%')
            ->first() ?? $lostStage;

        // 2. Resolve Loss reasons
        $reasonPrice = LossReason::query()->where('name', 'like', '%cher%')->first()
            ?? LossReason::firstOrCreate(['name' => 'Trop cher'], ['requires_note' => false, 'sort_order' => 1]);

        $reasonFeature = LossReason::query()->where('name', 'like', '%manquante%')->first()
            ?? LossReason::firstOrCreate(['name' => 'Fonctionnalité manquante'], ['requires_note' => true, 'sort_order' => 2]);

        $reasonPostponed = LossReason::query()->where('name', 'like', '%reporté%')->first()
            ?? LossReason::firstOrCreate(['name' => 'Projet reporté'], ['requires_note' => false, 'sort_order' => 3]);

        // 3. Resolve Products
        $gestcom = Product::query()->where('name', 'GestCom')->first()
            ?? Product::firstOrCreate(['name' => 'GestCom'], ['description' => 'Gestion Commerciale', 'is_active' => true]);

        $production = Product::query()->where('name', 'GestCom Production')->first()
            ?? Product::query()->where('name', 'like', '%Production%')->first()
            ?? Product::firstOrCreate(['name' => 'GestCom Production'], ['description' => 'Gestion de production industrielle', 'is_active' => true]);

        $importation = Product::query()->where('name', 'GestCom Importation')->first()
            ?? Product::firstOrCreate(['name' => 'GestCom Importation'], ['description' => 'Gestion des importations', 'is_active' => true]);

        $paie = Product::query()->where('name', 'GestCom Paie')->first()
            ?? Product::query()->where('name', 'like', '%Pa%')->first()
            ?? Product::firstOrCreate(['name' => 'GestCom Paie'], ['description' => 'Gestion de la paie et RH', 'is_active' => true]);

        // 4. Resolve Portal defaults
        $demoApptType = AppointmentType::query()->where('code', 'demo')->first();
        $trainingApptType = AppointmentType::query()->where('code', 'training')->first() ?? $demoApptType;
        $accompanimentApptType = AppointmentType::query()->where('code', 'accompaniment')->first() ?? $demoApptType;

        $p1 = InterventionPriority::query()->where('code', 'p1')->first();
        $p2 = InterventionPriority::query()->where('code', 'p2')->first();
        $p3 = InterventionPriority::query()->where('code', 'p3')->first();

        $statusNew = InterventionStatus::query()->where('code', 'new')->first();
        $statusReview = InterventionStatus::query()->where('code', 'under_review')->first() ?? $statusNew;
        $statusProgress = InterventionStatus::query()->where('code', 'in_progress')->first() ?? $statusNew;
        $statusDelivered = InterventionStatus::query()->where('code', 'delivered')->first() ?? $statusNew;

        $typeBug = InterventionType::query()->where('code', 'bug')->first();
        $typeQuestion = InterventionType::query()->where('code', 'question')->first();
        $typeRemark = InterventionType::query()->where('code', 'remark')->first();
        $typeFix = InterventionType::query()->where('code', 'fix')->first();

        // -------------------------------------------------------------
        // SCENARIO 1: Converted Customer - SARL Atlas Boissons & Jus
        // -------------------------------------------------------------
        $atlas = Prospect::firstOrCreate(
            ['email' => 'client.atlas@softel.dz'],
            [
                'name' => 'SARL Atlas Boissons & Jus',
                'contact_person' => 'Sofiane Hamidi',
                'phone' => '0560123456',
                'address' => 'Zone Industrielle Si Mustapha, Lot 14',
                'city' => 'Boumerdès',
                'business_activity' => 'Production',
                'business_activity_details' => 'Fabrication et embouteillage de jus et boissons gazeuses',
                'profession' => 'Food',
                'source' => 'Website',
                'previous_software' => 'Excel',
                'sales_stage_id' => $wonStage->id,
                'converted_at' => Carbon::now()->subDays(25),
                'converted_by' => 'Khaled B.',
                'conversion_note' => 'Client a validé le contrat annuel GestCom + Module Production.',
                'notes' => 'Client de référence agroalimentaire avec 3 ateliers de fabrication.',
            ]
        );

        // Attach products
        $atlas->products()->syncWithoutDetaching([
            $gestcom->id => ['purchased_at' => Carbon::now()->subDays(25)],
            $production->id => ['purchased_at' => Carbon::now()->subDays(25)],
        ]);

        // Stage history
        if ($atlas->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $atlas->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(60),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $atlas->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $demoStage->id,
                'changed_at' => Carbon::now()->subDays(45),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $atlas->id,
                'from_stage_id' => $demoStage->id,
                'to_stage_id' => $devisStage->id,
                'changed_at' => Carbon::now()->subDays(35),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $atlas->id,
                'from_stage_id' => $devisStage->id,
                'to_stage_id' => $wonStage->id,
                'changed_at' => Carbon::now()->subDays(25),
            ]);
        }

        // Portal Account
        $atlasAccount = PortalAccount::firstOrCreate(
            ['email' => 'client.atlas@softel.dz'],
            [
                'prospect_id' => $atlas->id,
                'name' => 'Sofiane Hamidi',
                'phone' => '0560123456',
                'password' => 'password123',
                'locale' => 'fr',
                'is_active' => true,
                'last_login_at' => Carbon::now()->subHours(3),
            ]
        );

        // Past activities
        if ($atlas->activities()->doesntExist()) {
            Activity::create([
                'prospect_id' => $atlas->id,
                'type' => ActivityType::Meeting,
                'title' => 'Démonstration Module Production en usine',
                'description' => 'Démonstration complète de la gestion des nomenclatures de fabrication et traçabilité des lots.',
                'outcome' => 'Direction générale très convaincue. Demande d\'un devis détaillé pour 5 postes réseau.',
                'activity_at' => Carbon::now()->subDays(45)->setTime(10, 0),
            ]);
            Activity::create([
                'prospect_id' => $atlas->id,
                'type' => ActivityType::Call,
                'title' => 'Négociation commerciale',
                'description' => 'Discussion des remises de volume et du calendrier des sessions de formation.',
                'outcome' => 'Accord conclu pour signature et acompte.',
                'activity_at' => Carbon::now()->subDays(28)->setTime(14, 30),
            ]);
        }

        // Follow up completed
        if ($atlas->followUps()->doesntExist()) {
            FollowUpTask::create([
                'prospect_id' => $atlas->id,
                'title' => 'Suivi de démarrage post-déploiement',
                'description' => 'S\'assurer de la bonne saisie des stocks initiaux avec le chef magasinier.',
                'activity_type' => FollowUpActivityType::Call,
                'scheduled_at' => Carbon::now()->subDays(10)->setTime(11, 0),
                'status' => FollowUpStatus::Completed,
                'completed_at' => Carbon::now()->subDays(10)->setTime(11, 45),
                'closing_note' => 'Tout fonctionne correctement. Le magasinier est autonome sur les entrées/sorties.',
            ]);
        }

        // Training Appointment
        if ($trainingApptType && Appointment::query()->where('prospect_id', $atlas->id)->doesntExist()) {
            Appointment::create([
                'appointment_type_id' => $trainingApptType->id,
                'prospect_id' => $atlas->id,
                'portal_account_id' => $atlasAccount->id,
                'product_id' => $production->id,
                'title' => 'Formation Équipe Production SOFTEL',
                'starts_at' => Carbon::now()->subDays(15)->setTime(9, 30),
                'ends_at' => Carbon::now()->subDays(15)->setTime(11, 30),
                'status' => Appointment::COMPLETED,
                'assigned_employee' => 'Khaled B.',
                'outcome' => 'Formation réussie des 3 chefs d\'ateliers aux ordres de fabrication.',
            ]);
        }

        // Interventions (Support tickets)
        if ($typeQuestion && $statusDelivered && Intervention::query()->where('reference', 'INT-ATLAS01')->doesntExist()) {
            $ticket1 = Intervention::create([
                'reference' => 'INT-ATLAS01',
                'source' => 'customer',
                'intervention_type_id' => $typeQuestion->id,
                'intervention_status_id' => $statusDelivered->id,
                'intervention_priority_id' => $p3?->id,
                'prospect_id' => $atlas->id,
                'portal_account_id' => $atlasAccount->id,
                'product_id' => $gestcom->id,
                'title' => 'Paramétrage format étiquette code-barres',
                'description' => 'Ajustement des dimensions d\'impression sur notre imprimante Zebra ZD220.',
                'customer_resolution' => 'Gabarit 100x150mm configuré à distance avec succès.',
                'assigned_employee' => 'Khaled B.',
                'closed_at' => Carbon::now()->subDays(5),
            ]);

            InterventionMessage::create([
                'intervention_id' => $ticket1->id,
                'author_type' => 'customer',
                'author_name' => 'Sofiane Hamidi',
                'visibility' => 'customer',
                'body' => 'Bonjour, nous avons reçu la nouvelle imprimante mais les marges sont décalées.',
            ]);
            InterventionMessage::create([
                'intervention_id' => $ticket1->id,
                'author_type' => 'employee',
                'author_name' => 'Support SOFTEL',
                'visibility' => 'customer',
                'body' => 'Bonjour M. Hamidi, nous nous sommes connectés pour recalibrer le pilote. Vous pouvez lancer un test.',
            ]);
            InterventionMessage::create([
                'intervention_id' => $ticket1->id,
                'author_type' => 'customer',
                'author_name' => 'Sofiane Hamidi',
                'visibility' => 'customer',
                'body' => 'Parfait, le centrage est impeccable. Merci pour votre rapidité !',
            ]);
        }

        if ($typeRemark && $statusProgress && Intervention::query()->where('reference', 'INT-ATLAS02')->doesntExist()) {
            $ticket2 = Intervention::create([
                'reference' => 'INT-ATLAS02',
                'source' => 'customer',
                'intervention_type_id' => $typeRemark->id,
                'intervention_status_id' => $statusProgress->id,
                'intervention_priority_id' => $p2?->id,
                'prospect_id' => $atlas->id,
                'portal_account_id' => $atlasAccount->id,
                'product_id' => $production->id,
                'title' => 'Suggestion récapitulatif pertes matières premières',
                'description' => 'Possibilité d\'imprimer un état récapitulatif mensuel des écarts théorique/réel par ligne.',
                'assigned_employee' => 'Amine M.',
            ]);

            InterventionMessage::create([
                'intervention_id' => $ticket2->id,
                'author_type' => 'customer',
                'author_name' => 'Sofiane Hamidi',
                'visibility' => 'customer',
                'body' => 'Ce rapport nous serait très précieux pour préparer notre audit de fin de mois.',
            ]);
            InterventionMessage::create([
                'intervention_id' => $ticket2->id,
                'author_type' => 'employee',
                'author_name' => 'Support SOFTEL',
                'visibility' => 'customer',
                'body' => 'Bonjour M. Hamidi, suggestion transmise à l\'équipe de développement. Ce rapport sera disponible dans la prochaine mise à jour.',
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 2: Converted Customer - EURL MediTech Distribution
        // -------------------------------------------------------------
        $meditech = Prospect::firstOrCreate(
            ['email' => 'client.meditech@softel.dz'],
            [
                'name' => 'EURL MediTech Distribution',
                'contact_person' => 'Dr. Leila Zerrouki',
                'phone' => '0661234567',
                'address' => '12 Rue Didouche Mourad',
                'city' => 'Alger Centre',
                'business_activity' => 'Wholesale',
                'business_activity_details' => 'Distribution de réactifs de laboratoire et dispositifs médicaux',
                'profession' => 'IT & Tech',
                'source' => 'Customer referral',
                'previous_software' => 'Sage',
                'sales_stage_id' => $wonStage->id,
                'converted_at' => Carbon::now()->subDays(18),
                'converted_by' => 'Amine M.',
                'conversion_note' => 'Migration depuis Sage 100 vers GestCom Importation validée.',
                'notes' => 'Importateur médical avec volumétrie importante en devises étrangères (EUR, USD).',
            ]
        );

        $meditech->products()->syncWithoutDetaching([
            $gestcom->id => ['purchased_at' => Carbon::now()->subDays(18)],
            $importation->id => ['purchased_at' => Carbon::now()->subDays(18)],
        ]);

        if ($meditech->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $meditech->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(40),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $meditech->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $wonStage->id,
                'changed_at' => Carbon::now()->subDays(18),
            ]);
        }

        $meditechAccount = PortalAccount::firstOrCreate(
            ['email' => 'client.meditech@softel.dz'],
            [
                'prospect_id' => $meditech->id,
                'name' => 'Dr. Leila Zerrouki',
                'phone' => '0661234567',
                'password' => 'password123',
                'locale' => 'fr',
                'is_active' => true,
                'last_login_at' => Carbon::now()->subDay(),
            ]
        );

        // Active Trial for testing import module
        if (Trial::query()->where('prospect_id', $meditech->id)->doesntExist()) {
            Trial::create([
                'prospect_id' => $meditech->id,
                'product_id' => $importation->id,
                'starts_at' => Carbon::now()->subDays(10),
                'ends_at' => Carbon::now()->addDays(20),
                'url' => 'https://demo.softel.dz/trial/meditech-import',
                'created_by' => 'Amine M.',
                'internal_notes' => 'Accès bac à sable pour tests d\'importation maritime avec douanes.',
            ]);
        }

        // Active ticket under review
        if ($typeFix && $statusReview && Intervention::query()->where('reference', 'INT-MEDIT01')->doesntExist()) {
            $ticketMed = Intervention::create([
                'reference' => 'INT-MEDIT01',
                'source' => 'customer',
                'intervention_type_id' => $typeFix->id,
                'intervention_status_id' => $statusReview->id,
                'intervention_priority_id' => $p1?->id,
                'prospect_id' => $meditech->id,
                'portal_account_id' => $meditechAccount->id,
                'product_id' => $importation->id,
                'title' => 'Taux de change douanier à date de domiciliation',
                'description' => 'Besoin d\'appliquer le cours officiel à la date de domiciliation bancaire pour le calcul du coût d\'approche.',
                'assigned_employee' => 'Khaled B.',
            ]);

            InterventionMessage::create([
                'intervention_id' => $ticketMed->id,
                'author_type' => 'customer',
                'author_name' => 'Dr. Leila Zerrouki',
                'visibility' => 'customer',
                'body' => 'Bonjour, sur nos dossiers en EUR, le logiciel prend le cours du jour au lieu du cours de domiciliation.',
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 3: Pipeline Prospect - Devis Envoyé (Follow-up TODAY)
        // -------------------------------------------------------------
        $poly = Prospect::firstOrCreate(
            ['email' => 'contact@polyemballage-setif.dz'],
            [
                'name' => 'SARL Poly-Emballage Sétif',
                'contact_person' => 'Samir Khelifi',
                'phone' => '0550987654',
                'address' => 'Zone Industrielle Ain Arnat',
                'city' => 'Sétif',
                'business_activity' => 'Production',
                'business_activity_details' => 'Fabrication de films plastiques et emballages souples',
                'profession' => 'Other',
                'source' => 'Phone call',
                'previous_software' => 'PC Compta',
                'sales_stage_id' => $devisStage->id,
                'notes' => 'Devis N° DEV-2026-118 envoyé le 12/11/2026.',
            ]
        );

        $poly->products()->syncWithoutDetaching([$gestcom->id, $production->id]);

        if ($poly->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $poly->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(14),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $poly->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $demoStage->id,
                'changed_at' => Carbon::now()->subDays(8),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $poly->id,
                'from_stage_id' => $demoStage->id,
                'to_stage_id' => $devisStage->id,
                'changed_at' => Carbon::now()->subDays(4),
            ]);
        }

        // Follow up DUE TODAY -> Dashboard "Today" KPI!
        if ($poly->followUps()->where('status', FollowUpStatus::Pending->value)->doesntExist()) {
            FollowUpTask::create([
                'prospect_id' => $poly->id,
                'title' => 'Relance devis N° DEV-2026-118 M. Khelifi',
                'description' => 'Appeler le directeur financier pour discuter de l\'offre et des modalités de paiement.',
                'activity_type' => FollowUpActivityType::Call,
                'scheduled_at' => Carbon::now()->setTime(14, 0),
                'status' => FollowUpStatus::Pending,
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 4: Pipeline Prospect - Démo (Upcoming Follow-up & Appointment)
        // -------------------------------------------------------------
        $cme = Prospect::firstOrCreate(
            ['email' => 'cme.alger@gmail.com'],
            [
                'name' => 'Comptoir Moderne d\'Électricité (CME)',
                'contact_person' => 'Yacine Benali',
                'phone' => '0770334455',
                'address' => 'Zone d\'Activité Rouiba',
                'city' => 'Rouiba',
                'business_activity' => 'Wholesale',
                'business_activity_details' => 'Distribution de matériel électrique et luminaires industriels',
                'profession' => 'Electronics',
                'source' => 'Facebook',
                'previous_software' => 'None',
                'sales_stage_id' => $demoStage->id,
                'notes' => 'Intéressé par GestCom pour gérer 2 dépôts et la facturation comptoir.',
            ]
        );

        $cme->products()->syncWithoutDetaching([$gestcom->id]);

        if ($cme->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $cme->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(6),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $cme->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $demoStage->id,
                'changed_at' => Carbon::now()->subDays(2),
            ]);
        }

        // Confirmed Demo Appointment in upcoming calendar
        if ($demoApptType && Appointment::query()->where('prospect_id', $cme->id)->doesntExist()) {
            Appointment::create([
                'appointment_type_id' => $demoApptType->id,
                'prospect_id' => $cme->id,
                'product_id' => $gestcom->id,
                'title' => 'Démonstration GestCom Vente & Stock pour CME',
                'starts_at' => Carbon::now()->addDays(2)->setTime(10, 0),
                'ends_at' => Carbon::now()->addDays(2)->setTime(11, 0),
                'status' => Appointment::CONFIRMED,
                'assigned_employee' => 'Khaled B.',
                'meeting_url' => 'https://meet.google.com/sof-crm-demo',
                'internal_notes' => 'Préparer une base exemple avec des articles de câblage et disjoncteurs.',
            ]);
        }

        // UPCOMING Follow-up in 3 days -> Dashboard "Upcoming" KPI!
        if ($cme->followUps()->where('status', FollowUpStatus::Pending->value)->doesntExist()) {
            FollowUpTask::create([
                'prospect_id' => $cme->id,
                'title' => 'Débriefing post-démonstration avec M. Benali',
                'description' => 'Recueillir les impressions après la démo et élaborer le devis personnalisé.',
                'activity_type' => FollowUpActivityType::Call,
                'scheduled_at' => Carbon::now()->addDays(3)->setTime(11, 0),
                'status' => FollowUpStatus::Pending,
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 5: Pipeline Prospect - Nouveau (Overdue Follow-up)
        // -------------------------------------------------------------
        $pharmacie = Prospect::firstOrCreate(
            ['email' => 'pharmacie.bouzid@yahoo.fr'],
            [
                'name' => 'Pharmacie Centrale de l\'Ouest',
                'contact_person' => 'Dr. Karim Bouzid',
                'phone' => '0541223344',
                'address' => 'Boulevard Front de Mer',
                'city' => 'Oran',
                'business_activity' => 'Commerce',
                'business_activity_details' => 'Officine pharmaceutique et parapharmacie',
                'profession' => 'Other',
                'source' => 'Website',
                'previous_software' => 'Other software',
                'sales_stage_id' => $openStage->id,
                'notes' => 'Inscrit via formulaire web pour renseignements sur la gestion des ordonnances.',
            ]
        );

        $pharmacie->products()->syncWithoutDetaching([$gestcom->id]);

        // OVERDUE Follow-up scheduled 2 days ago -> Dashboard "Overdue" KPI!
        if ($pharmacie->followUps()->where('status', FollowUpStatus::Pending->value)->doesntExist()) {
            FollowUpTask::create([
                'prospect_id' => $pharmacie->id,
                'title' => 'Premier contact téléphonique Dr. Bouzid',
                'description' => 'Qualifier le besoin pour la gestion des stocks de médicaments.',
                'activity_type' => FollowUpActivityType::Call,
                'scheduled_at' => Carbon::now()->subDays(2)->setTime(10, 30),
                'status' => FollowUpStatus::Pending,
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 6: Inbound Prospect - Nouveau
        // -------------------------------------------------------------
        $quincaillerie = Prospect::firstOrCreate(
            ['phone' => '0655667788'],
            [
                'name' => 'ETS Quincaillerie Générale Blida',
                'contact_person' => 'Rachid Mansour',
                'email' => 'quincaillerie.mansour@gmail.com',
                'city' => 'Blida',
                'business_activity' => 'Wholesale',
                'business_activity_details' => 'Quincaillerie industrielle et outillage professionnel',
                'profession' => 'Construction',
                'source' => 'Friend',
                'previous_software' => 'Excel',
                'sales_stage_id' => $openStage->id,
                'notes' => 'Prospect recommandé par un client Softel.',
            ]
        );

        $quincaillerie->products()->syncWithoutDetaching([$gestcom->id]);

        if ($quincaillerie->activities()->doesntExist()) {
            Activity::create([
                'prospect_id' => $quincaillerie->id,
                'type' => ActivityType::Call,
                'title' => 'Appel entrant de prise de contact',
                'description' => 'M. Mansour souhaite équiper 2 magasins à Blida avec synchronisation réseau.',
                'outcome' => 'Documentation générale envoyée par e-mail.',
                'activity_at' => Carbon::now()->subDays(1)->setTime(16, 0),
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 7: Lost Prospect - Trop cher
        // -------------------------------------------------------------
        $patisserie = Prospect::firstOrCreate(
            ['email' => 'la.tradition.patisserie@gmail.com'],
            [
                'name' => 'Boulangerie & Pâtisserie La Tradition',
                'contact_person' => 'Mourad Dahmani',
                'phone' => '0558112233',
                'city' => 'Tizi Ouzou',
                'business_activity' => 'Commerce',
                'profession' => 'Food',
                'source' => 'Instagram',
                'previous_software' => 'None',
                'sales_stage_id' => $lostStage->id,
                'loss_reason_id' => $reasonPrice->id,
                'loss_note' => 'A jugé l\'investissement trop élevé pour une structure de 3 personnes.',
            ]
        );

        $patisserie->products()->syncWithoutDetaching([$gestcom->id]);

        if ($patisserie->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $patisserie->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(30),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $patisserie->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $lostStage->id,
                'loss_reason_id' => $reasonPrice->id,
                'loss_note' => 'A jugé l\'investissement trop élevé pour une structure de 3 personnes.',
                'changed_at' => Carbon::now()->subDays(12),
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 8: Lost Prospect - Fonctionnalité manquante
        // -------------------------------------------------------------
        $transit = Prospect::firstOrCreate(
            ['email' => 'contact@transit-maritime.dz'],
            [
                'name' => 'Transit Maritime & Logistique Portuaire',
                'contact_person' => 'Omar Belkacem',
                'phone' => '0561778899',
                'city' => 'Bejaia',
                'business_activity' => 'Services',
                'profession' => 'Other',
                'source' => 'Website',
                'previous_software' => 'Other software',
                'sales_stage_id' => $lostStage->id,
                'loss_reason_id' => $reasonFeature->id,
                'loss_note' => 'Besoin impératif d\'une connexion EDI directe avec le système portuaire APCS.',
            ]
        );

        $transit->products()->syncWithoutDetaching([$importation->id]);

        if ($transit->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $transit->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(20),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $transit->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $lostStage->id,
                'loss_reason_id' => $reasonFeature->id,
                'loss_note' => 'Besoin impératif d\'une connexion EDI directe avec le système portuaire APCS.',
                'changed_at' => Carbon::now()->subDays(6),
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 9: Postponed Prospect - Projet reporté
        // -------------------------------------------------------------
        $textile = Prospect::firstOrCreate(
            ['email' => 'meziane.textile@hotmail.com'],
            [
                'name' => 'Textile Moderne Tlemcen',
                'contact_person' => 'Bilal Meziane',
                'phone' => '0791445566',
                'city' => 'Tlemcen',
                'business_activity' => 'Production',
                'profession' => 'Clothing',
                'source' => 'Phone call',
                'sales_stage_id' => $postponedStage->id,
                'loss_reason_id' => $reasonPostponed->id,
                'loss_note' => 'Déménagement de l\'atelier prévu pour le premier semestre 2027.',
            ]
        );

        $textile->products()->syncWithoutDetaching([$gestcom->id, $production->id]);

        if ($textile->stageHistories()->doesntExist()) {
            ProspectStageHistory::create([
                'prospect_id' => $textile->id,
                'from_stage_id' => null,
                'to_stage_id' => $openStage->id,
                'changed_at' => Carbon::now()->subDays(35),
            ]);
            ProspectStageHistory::create([
                'prospect_id' => $textile->id,
                'from_stage_id' => $openStage->id,
                'to_stage_id' => $postponedStage->id,
                'loss_reason_id' => $reasonPostponed->id,
                'loss_note' => 'Déménagement de l\'atelier prévu pour le premier semestre 2027.',
                'changed_at' => Carbon::now()->subDays(14),
            ]);
        }

        // -------------------------------------------------------------
        // SCENARIO 10: Website Portal Inquirer - Pending Demo Request
        // -------------------------------------------------------------
        $maghreb = Prospect::firstOrCreate(
            ['email' => 'demo.portal@softel.dz'],
            [
                'name' => 'SARL Maghreb Plast',
                'contact_person' => 'Farouk Djelloul',
                'phone' => '0552113355',
                'address' => 'Zone Industrielle Oued Smar',
                'city' => 'Alger',
                'business_activity' => 'Production',
                'business_activity_details' => 'Injection plastique et emballages pharmaceutiques',
                'profession' => 'Other',
                'source' => 'Website',
                'previous_software' => 'Excel',
                'sales_stage_id' => $openStage->id,
                'notes' => 'Demande de démonstration transmise via le portail web.',
            ]
        );

        $maghreb->products()->syncWithoutDetaching([$gestcom->id]);

        $demoAccount = PortalAccount::firstOrCreate(
            ['email' => 'demo.portal@softel.dz'],
            [
                'prospect_id' => $maghreb->id,
                'name' => 'Farouk Djelloul',
                'phone' => '0552113355',
                'password' => 'password123',
                'locale' => 'fr',
                'is_active' => true,
                'last_login_at' => Carbon::now()->subMinutes(40),
            ]
        );

        if (PortalRequest::query()->where('portal_account_id', $demoAccount->id)->doesntExist()) {
            PortalRequest::create([
                'portal_account_id' => $demoAccount->id,
                'prospect_id' => $maghreb->id,
                'product_id' => $gestcom->id,
                'type' => 'demo',
                'status' => 'pending',
                'company' => 'SARL Maghreb Plast',
                'contact_name' => 'Farouk Djelloul',
                'job_title' => 'Directeur Général',
                'phone' => '0552113355',
                'email' => 'demo.portal@softel.dz',
                'wilaya' => 'Alger',
                'city' => 'Oued Smar',
                'business_activity' => 'Production',
                'notes' => 'Nous souhaitons assister à une démonstration en direct du logiciel GestCom pour évaluer le passage d\'Excel vers votre solution.',
                'source' => 'website',
            ]);
        }
    }
}
