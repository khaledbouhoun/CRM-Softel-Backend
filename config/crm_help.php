<?php

/*
|--------------------------------------------------------------------------
| Parameters help
|--------------------------------------------------------------------------
|
| section => ['title', 'help', 'fields' => [field => ['label', 'help', 'example']]]
| Served by GET /api/v1/settings/help (all) and /settings/help/{section}.
|
*/

return [

    'scheduling' => [
        'title' => 'Scheduling rules',
        'help' => 'Define when customers and employees can book demos, trainings and accompaniments.',
        'fields' => [
            'working_days' => ['label' => 'Working days', 'help' => 'Days appointments can be booked. 0 = Sunday ... 6 = Saturday.', 'example' => '[0, 1, 2, 3, 4] for Sunday to Thursday'],
            'work_start' => ['label' => 'Opening time', 'help' => 'First time of day a slot can start (24h, HH:MM).', 'example' => '09:00'],
            'work_end' => ['label' => 'Closing time', 'help' => 'Time of day by which every slot must end. Must be after the opening time.', 'example' => '16:00'],
            'slot_minutes' => ['label' => 'Slot length (minutes)', 'help' => 'Length of one bookable slot. Customers choose among slots of this length.', 'example' => '60'],
            'hold_expiry_minutes' => ['label' => 'Hold expiry (minutes)', 'help' => 'How long a requested demo slot stays reserved while waiting for staff confirmation, before it is released.', 'example' => '1440 (24 hours)'],
            'booking_horizon_days' => ['label' => 'Booking horizon (days)', 'help' => 'How far in the future a customer may book.', 'example' => '30'],
            'min_notice_hours' => ['label' => 'Minimum notice (hours)', 'help' => 'Earliest a customer can book from now, so the team has time to prepare.', 'example' => '12'],
            'accompaniment_occurrences' => ['label' => 'Accompaniment sessions', 'help' => 'Default number of sessions created when scheduling an accompaniment series.', 'example' => '4'],
            'accompaniment_interval_days' => ['label' => 'Days between sessions', 'help' => 'Default gap between two sessions of an accompaniment series.', 'example' => '7 (weekly)'],
            'trial_default_days' => ['label' => 'Default trial length (days)', 'help' => 'Length of a trial/demo access when no end date is chosen.', 'example' => '14'],
            'whatsapp_country_code' => ['label' => 'WhatsApp country code', 'help' => 'Digits only, no plus sign. Used to turn local phone numbers into international numbers.', 'example' => '213 for Algeria'],
        ],
    ],

    'appointment_types' => [
        'title' => 'Appointment types',
        'help' => 'The four types are fixed: Demo, Meeting, Training and Accompaniment. You can only adjust the default duration, color and whether customers can book them online.',
        'fields' => [
            'default_duration_minutes' => ['label' => 'Default duration (minutes)', 'help' => 'Used when an appointment is created without an end time.', 'example' => '120 for a training'],
            'color' => ['label' => 'Calendar color', 'help' => 'Hex color shown in the calendar.', 'example' => '#16A34A'],
            'customer_bookable' => ['label' => 'Bookable by customers', 'help' => 'If on, customers can request this type from the website.', 'example' => 'On for Demo, off for the others'],
        ],
    ],

    'holidays' => [
        'title' => 'Holidays',
        'help' => 'Days with no appointments, on top of the non-working days.',
        'fields' => [
            'date' => ['label' => 'Date', 'help' => 'The day that is closed.', 'example' => '2026-07-05'],
            'name' => ['label' => 'Name', 'help' => 'Shown in the calendar.', 'example' => 'Independence Day'],
        ],
    ],

    'products' => [
        'title' => 'Products',
        'help' => 'The product catalogue is the single source of truth for the CRM and the public website.',
        'fields' => [
            'name' => ['label' => 'Name', 'help' => 'Name shown to employees and customers.', 'example' => 'GestCom Ultra'],
            'pack_id' => ['label' => 'Pack', 'help' => 'Group tiers of the same suite. A customer can pick only one product per pack.', 'example' => 'GestCom (Basic, Ultra)'],
            'slug' => ['label' => 'Website slug', 'help' => 'Lowercase words separated by dashes, used in the website address. Generated from the name if empty.', 'example' => 'gestcom-ultra'],
            'demo_url' => ['label' => 'Demo link', 'help' => 'Permanent link of the product demo. Demos are generated from it; products without it cannot be offered as a demo.', 'example' => 'https://demo.softel.dz/gestcom'],
            'is_active_on_web' => ['label' => 'Visible on website', 'help' => 'If on (and the product is active) it appears in the public catalogue.', 'example' => 'On'],
            'full_description' => ['label' => 'Full description', 'help' => 'Long presentation text for the product page.', 'example' => 'Complete sales and stock management for wholesalers...'],
            'features' => ['label' => 'Features', 'help' => 'One short line per feature.', 'example' => '["Invoicing", "Multi-warehouse stock"]'],
            'specifications' => ['label' => 'Specifications', 'help' => 'Key/value technical details.', 'example' => '{"Platform": "Windows", "Database": "MySQL"}'],
        ],
    ],

    'intervention_types' => [
        'title' => 'Intervention types',
        'help' => 'Kinds of after-sales requests (bug, question, service...). Types are deactivated, never deleted, to keep history.',
        'fields' => [
            'code' => ['label' => 'Code', 'help' => 'Stable identifier: lowercase letters, digits and underscores. Cannot be changed later.', 'example' => 'bug'],
            'name' => ['label' => 'Name', 'help' => 'Label shown in the app.', 'example' => 'Bug'],
            'source' => ['label' => 'Created by', 'help' => 'Who may open this type: customer, employee, or both.', 'example' => 'customer'],
        ],
    ],

    'intervention_statuses' => [
        'title' => 'Intervention statuses',
        'help' => 'Steps of the support workflow. Transitions decide which status can follow which.',
        'fields' => [
            'customer_label' => ['label' => 'Customer label', 'help' => 'Wording customers see instead of the internal name.', 'example' => 'We need more information'],
            'is_initial' => ['label' => 'Initial status', 'help' => 'Status given to a new intervention. Only one should be initial.', 'example' => 'New'],
            'is_closed' => ['label' => 'Closes the intervention', 'help' => 'Reaching this status locks replies until an employee reopens it.', 'example' => 'Resolved'],
            'awaits_customer' => ['label' => 'Waiting for customer', 'help' => 'Marks interventions where the team waits for the customer.', 'example' => 'Waiting for customer information'],
            'is_delivered' => ['label' => 'Delivered', 'help' => 'Marks that the fix or feature was delivered.', 'example' => 'Delivered'],
        ],
    ],

    'intervention_priorities' => [
        'title' => 'Priorities',
        'help' => 'Urgency levels with a response target.',
        'fields' => [
            'response_target_hours' => ['label' => 'Response target (hours)', 'help' => 'Expected time to a first response. Empty = no target.', 'example' => '24'],
        ],
    ],

    'message_templates' => [
        'title' => 'Message templates',
        'help' => 'WhatsApp texts for appointments: one template per appointment kind (demo, meeting, training, accompaniment) and per language (ar, fr, en). The client receives the template in their own language, or French if yours is missing. The employee opens a prefilled WhatsApp link and presses send.',
        'fields' => [
            'code' => ['label' => 'Code', 'help' => 'Which appointment the message is for: appointment_demo, appointment_meeting, appointment_training, appointment_accompaniment (training_first / training_review are used first for trainings).', 'example' => 'appointment_training'],
            'locale' => ['label' => 'Language', 'help' => 'ar, fr or en. Matches the language set on the client.', 'example' => 'fr'],
            'body' => ['label' => 'Message', 'help' => 'Text with placeholders filled in automatically: {client_name}, {company_name}, {product_name}, {date}, {time}, {end}, {link}, {employee}, {appointment_type}.', 'example' => 'Bonjour {client_name}, votre formation {product_name} est prevue le {date} de {time} a {end}.'],
        ],
    ],

    'product_resources' => [
        'title' => 'Product resources',
        'help' => 'Guides, videos and links customers who own the product can open.',
        'fields' => [
            'title' => ['label' => 'Title', 'help' => 'Name shown to the customer.', 'example' => 'Quick start guide'],
            'url' => ['label' => 'Link', 'help' => 'Public http(s) address of the resource.', 'example' => 'https://drive.example.com/guide.pdf'],
            'kind' => ['label' => 'Type', 'help' => 'pdf, video, link or other.', 'example' => 'pdf'],
            'customer_visible' => ['label' => 'Visible to customers', 'help' => 'Turn off for internal-only material.', 'example' => 'On'],
            'is_training_material' => ['label' => 'Training material', 'help' => 'Attach automatically to training reminders.', 'example' => 'On'],
        ],
    ],

];
