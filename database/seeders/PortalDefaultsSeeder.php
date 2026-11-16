<?php

namespace Database\Seeders;

use App\Models\AppointmentType;
use App\Models\InterventionPriority;
use App\Models\InterventionStatus;
use App\Models\InterventionStatusTransition;
use App\Models\InterventionType;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Initial, editable configuration. Idempotent (updateOrCreate by code): run it
 * once after migrating; afterwards every value is managed from CRM settings.
 */
class PortalDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['demo', 'Demo', 'demo', 60, '#009EE0', true, 1],
            ['meeting', 'Meeting', 'meeting', 60, '#7C3AED', false, 2],
            ['training', 'Training', 'training', 120, '#16A34A', false, 3],
            ['accompaniment', 'Accompaniment', 'accompaniment', 120, '#F59E0B', false, 4],
        ] as [$code, $name, $group, $minutes, $color, $bookable, $order]) {
            AppointmentType::updateOrCreate(['code' => $code], [
                'name' => $name, 'group' => $group, 'default_duration_minutes' => $minutes,
                'color' => $color, 'customer_bookable' => $bookable, 'sort_order' => $order,
            ]);
        }

        foreach ([
            ['bug', 'Bug', 'customer', '#DC2626'], ['question', 'Question', 'customer', '#0EA5E9'],
            ['remark', 'Remark / suggestion', 'customer', '#8B5CF6'], ['fix', 'Fix', 'employee', '#16A34A'],
            ['update', 'Product update', 'employee', '#009EE0'], ['release', 'New release / feature', 'employee', '#F59E0B'],
            ['service', 'Service intervention', 'both', '#64748B'],
        ] as $i => [$code, $name, $source, $color]) {
            InterventionType::updateOrCreate(['code' => $code], ['name' => $name, 'source' => $source, 'color' => $color, 'sort_order' => $i + 1]);
        }

        foreach ([
            ['p1', 'P1 - Critical', '#DC2626', 4], ['p2', 'P2 - High', '#F97316', 24],
            ['p3', 'P3 - Normal', '#EAB308', 72], ['p4', 'P4 - Low', '#64748B', 168],
        ] as $i => [$code, $name, $color, $hours]) {
            InterventionPriority::updateOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'response_target_hours' => $hours, 'sort_order' => $i + 1]);
        }

        // code, internal name, customer label, color, initial, closed, awaits customer, delivered
        $statuses = [
            ['new', 'New', 'Received', '#64748B', true, false, false, false],
            ['under_review', 'Under review', 'Under review', '#0EA5E9', false, false, false, false],
            ['waiting_customer', 'Waiting for customer information', 'We need more information', '#F59E0B', false, false, true, false],
            ['queued', 'Accepted / queued', 'Accepted', '#8B5CF6', false, false, false, false],
            ['in_progress', 'In progress', 'In progress', '#009EE0', false, false, false, false],
            ['rejected', 'Rejected', 'Declined', '#DC2626', false, true, false, false],
            ['testing', 'In testing / QA', 'Being verified', '#EAB308', false, false, false, false],
            ['delivered', 'Delivered', 'Delivered', '#16A34A', false, false, false, true],
            ['closed', 'Closed', 'Closed', '#475569', false, true, false, false],
            ['reopened', 'Reopened', 'Reopened', '#F97316', false, false, false, false],
        ];
        $ids = [];
        foreach ($statuses as $i => [$code, $name, $label, $color, $initial, $closed, $awaits, $delivered]) {
            $ids[$code] = InterventionStatus::updateOrCreate(['code' => $code], [
                'name' => $name, 'customer_label' => $label, 'color' => $color, 'is_initial' => $initial,
                'is_closed' => $closed, 'awaits_customer' => $awaits, 'is_delivered' => $delivered, 'sort_order' => $i + 1,
            ])->id;
        }

        $employee = [
            'new' => ['under_review', 'rejected'],
            'under_review' => ['waiting_customer', 'queued', 'rejected', 'delivered'],
            'waiting_customer' => ['under_review', 'closed'],
            'queued' => ['in_progress', 'rejected'],
            'in_progress' => ['testing', 'waiting_customer', 'delivered'],
            'testing' => ['in_progress', 'delivered'],
            'delivered' => ['closed', 'reopened'],
            'rejected' => ['reopened', 'closed'],
            'closed' => ['reopened'],
            'reopened' => ['under_review', 'in_progress'],
        ];
        $customer = ['waiting_customer>under_review', 'delivered>reopened', 'closed>reopened'];
        foreach ($employee as $from => $targets) {
            foreach ($targets as $to) {
                InterventionStatusTransition::updateOrCreate(
                    ['from_status_id' => $ids[$from], 'to_status_id' => $ids[$to]],
                    ['customer_allowed' => in_array("{$from}>{$to}", $customer, true)],
                );
            }
        }

        foreach ([
            ['training_first', 'fr', 'Bonjour {contact}, votre première formation {product} est prévue le {date} de {start} à {end}. Support : {resource_url}'],
            ['training_first', 'en', 'Hello {contact}, your first {product} training is on {date} from {start} to {end}. Material: {resource_url}'],
            ['training_first', 'ar', 'مرحبا {contact}، تدريبكم الأول على {product} بتاريخ {date} من {start} إلى {end}. المرجع: {resource_url}'],
            ['training_review', 'fr', 'Bonjour {contact}, voici le support de révision {product} : {resource_url}'],
            ['training_review', 'en', 'Hello {contact}, here is the {product} review material: {resource_url}'],
            ['training_review', 'ar', 'مرحبا {contact}، هذا مرجع مراجعة {product}: {resource_url}'],
        ] as [$code, $locale, $body]) {
            MessageTemplate::updateOrCreate(['code' => $code, 'locale' => $locale], ['body' => $body]);
        }
    }
}
