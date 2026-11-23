<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Imports the marketing catalogue that used to live in the website's own database (idempotent, matched on slug).
 * Run once: php artisan db:seed --class=PublicProductsSeeder
 * Images: leave "image_path" empty to use the website's bundled picture, or upload/paste a URL in the CRM.
 */
class PublicProductsSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'slug' => 'gestcom',
                'title' => [
                    'en' => 'GESTCOM — Commercial Management',
                    'fr' => 'GESTCOM — Gestion Commerciale',
                    'ar' => 'جيستكوم — التسيير التجاري',
                ],
                'summary' => [
                    'en' => 'A complete commercial management solution designed to help businesses manage their day-to-day commercial operations efficiently.',
                    'fr' => 'Une solution complète de gestion commerciale conçue pour accompagner les entreprises dans la gestion efficace de leurs opérations quotidiennes.',
                    'ar' => 'حل متكامل للتسيير التجاري يساعد المؤسسات على إدارة عملياتها التجارية اليومية بكفاءة.',
                ],
                'description' => [
                    'en' => 'GESTCOM provides the essential tools needed to manage commercial activities, including purchasing, sales, invoicing, customers, suppliers, stock management and financial follow-up. Designed around real business workflows, it provides a centralized environment for managing day-to-day commercial operations.',
                    'fr' => 'GESTCOM regroupe les outils essentiels nécessaires à la gestion des activités commerciales, notamment les achats, les ventes, la facturation, les clients, les fournisseurs, les stocks et le suivi financier. Conçu autour des réalités de l’entreprise, il offre un environnement centralisé pour gérer efficacement les opérations quotidiennes.',
                    'ar' => 'يوفر جيستكوم الأدوات الأساسية اللازمة لإدارة النشاط التجاري، بما في ذلك المشتريات والمبيعات والفوترة والعملاء والموردين وتسيير المخزون والمتابعة المالية. وقد صُمم وفق احتياجات العمل اليومية لتوفير بيئة موحدة لإدارة العمليات التجارية بكفاءة.',
                ],
                'tech_stack' => [
                    'en' => [
                        'Purchasing',
                        'Sales',
                        'Inventory',
                        'Treasury',
                    ],
                    'fr' => [
                        'Achats',
                        'Ventes',
                        'Gestion des stocks',
                        'Trésorerie',
                    ],
                    'ar' => [
                        'المشتريات',
                        'المبيعات',
                        'إدارة المخزون',
                        'الخزينة',
                    ],
                ],
                'sort_order' => 1,
            ],
            [
                'slug' => 'gestcom-importation',
                'title' => [
                    'en' => 'GESTCOM — Importation',
                    'fr' => 'GESTCOM — Importation',
                    'ar' => 'جيستكوم — تسيير الاستيراد',
                ],
                'summary' => [
                    'en' => 'A dedicated solution for managing import operations, costs and related commercial processes.',
                    'fr' => 'Une solution dédiée à la gestion des opérations d’importation, des coûts et des processus commerciaux associés.',
                    'ar' => 'حل مخصص لإدارة عمليات الاستيراد والتكاليف والعمليات التجارية المرتبطة بها.',
                ],
                'description' => [
                    'en' => 'GESTCOM Importation helps businesses organize and monitor their import operations, from supplier and purchase documentation to associated expenses and the calculation of the actual cost of imported goods.',
                    'fr' => 'GESTCOM Importation permet aux entreprises d’organiser et de suivre leurs opérations d’importation, depuis les documents fournisseurs et achats jusqu’au suivi des frais associés et au calcul du coût réel des marchandises importées.',
                    'ar' => 'يساعد جيستكوم للاستيراد المؤسسات على تنظيم ومتابعة عمليات الاستيراد، بدءًا من وثائق الموردين والمشتريات وصولًا إلى متابعة التكاليف المرتبطة وحساب التكلفة الفعلية للسلع المستوردة.',
                ],
                'tech_stack' => [
                    'en' => [
                        'Import Management',
                        'Supplier Management',
                        'Purchase Management',
                        'Import Costs',
                        'Landed Cost',
                        'Commercial Management',
                    ],
                    'fr' => [
                        'Gestion des importations',
                        'Gestion des fournisseurs',
                        'Gestion des achats',
                        'Frais d\'approche',
                        'Coût de revient',
                        'Gestion commerciale',
                    ],
                    'ar' => [
                        'إدارة الاستيراد',
                        'إدارة الموردين',
                        'إدارة المشتريات',
                        'مصاريف الاستيراد',
                        'حساب التكلفة الفعلية',
                        'التسيير التجاري',
                    ],
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'gestcom-production',
                'title' => [
                    'en' => 'GESTCOM — Production',
                    'fr' => 'GESTCOM — Production',
                    'ar' => 'جيستكوم — إدارة الإنتاج',
                ],
                'summary' => [
                    'en' => 'A production management solution designed to help industrial businesses organize and monitor their production operations.',
                    'fr' => 'Une solution de gestion de production conçue pour aider les entreprises industrielles à organiser et suivre leurs opérations de production.',
                    'ar' => 'حل لإدارة الإنتاج يساعد المؤسسات الصناعية على تنظيم ومتابعة عمليات الإنتاج.',
                ],
                'description' => [
                    'en' => 'GESTCOM Production extends management capabilities to industrial operations, helping businesses organize production workflows, materials, manufacturing operations and production costs within an integrated management environment.',
                    'fr' => 'GESTCOM Production étend les fonctionnalités de gestion aux opérations industrielles et permet d’organiser les flux de production, les matières, les opérations de fabrication et les coûts de production dans un environnement de gestion intégré.',
                    'ar' => 'يوسع جيستكوم للإنتاج إمكانيات التسيير لتشمل العمليات الصناعية، حيث يساعد المؤسسات على تنظيم عمليات الإنتاج والمواد وعمليات التصنيع وتكاليف الإنتاج ضمن بيئة تسيير متكاملة.',
                ],
                'tech_stack' => [
                    'en' => [
                        'Production Management',
                        'Manufacturing',
                        'Materials Management',
                        'Production Workflows',
                        'Production Costs',
                        'Industrial Management',
                    ],
                    'fr' => [
                        'Gestion de production',
                        'Fabrication',
                        'Gestion des matières',
                        'Flux de production',
                        'Coûts de production',
                        'Gestion industrielle',
                    ],
                    'ar' => [
                        'إدارة الإنتاج',
                        'التصنيع',
                        'إدارة المواد الأولية',
                        'مراحل الإنتاج',
                        'تكاليف الإنتاج',
                        'التسيير الصناعي',
                    ],
                ],
                'sort_order' => 3,
            ],
            [
                'slug' => 'gestcom-paie',
                'title' => [
                    'en' => 'GESTCOM — Payroll',
                    'fr' => 'GESTCOM — Paie',
                    'ar' => 'جيستكوم — تسيير الأجور والرواتب',
                ],
                'summary' => [
                    'en' => 'A payroll management solution designed to simplify employee payroll processing and related administrative operations.',
                    'fr' => 'Une solution de gestion de la paie conçue pour simplifier le traitement des salaires et les opérations administratives associées.',
                    'ar' => 'حل لإدارة الأجور والرواتب يهدف إلى تسهيل معالجة رواتب الموظفين والعمليات الإدارية المرتبطة بها.',
                ],
                'description' => [
                    'en' => 'GESTCOM Payroll helps organizations manage employee payroll operations, pay slips, attendance information and related administrative processes in an organized environment.',
                    'fr' => 'GESTCOM Paie permet aux organisations de gérer les opérations liées aux salaires, les fiches de paie, les informations de présence et les processus administratifs associés dans un environnement organisé.',
                    'ar' => 'يساعد جيستكوم للرواتب المؤسسات على إدارة عمليات الأجور والرواتب وكشوف الأجور ومعلومات الحضور والعمليات الإدارية المرتبطة بها ضمن بيئة منظمة.',
                ],
                'tech_stack' => [
                    'en' => [
                        'Payroll Management',
                        'Employee Management',
                        'Pay Slips',
                        'Attendance',
                        'Administrative Management',
                    ],
                    'fr' => [
                        'Gestion de la paie',
                        'Gestion des employés',
                        'Fiches de paie',
                        'Gestion des présences',
                        'Gestion administrative',
                    ],
                    'ar' => [
                        'إدارة الأجور والرواتب',
                        'إدارة الموظفين',
                        'كشوف الأجور',
                        'متابعة الحضور والغياب',
                        'التسيير الإداري',
                    ],
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($products as $d) {
            Product::updateOrCreate(
                ['slug' => $d['slug']],
                [
                    'name' => $d['title']['en'],
                    'title_i18n' => $d['title'],
                    'summary_i18n' => $d['summary'],
                    'description_i18n' => $d['description'],
                    'tech_stack_i18n' => $d['tech_stack'],
                    'description' => $d['summary']['en'],
                    'sort_order' => $d['sort_order'],
                    'is_active' => true,
                    'is_active_on_web' => true,
                ],
            );
        }
    }
}
