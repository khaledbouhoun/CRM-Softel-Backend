<?php

/*
|--------------------------------------------------------------------------
| Default message templates
|--------------------------------------------------------------------------
|
| Installed (never overwritten) by MessageTemplateDefaults::install() from the
| seeder and from a migration, so a database always has a template for every
| appointment kind in every supported language. Edit them afterwards from the
| settings screen.
|
| Placeholders: {client_name} {company_name} {product_name} {date} {time} {end}
|               {link} {meeting_url} {resource_url} {employee} {appointment_type}
| (older aliases {contact} {product} {start} still work.)
*/

return [

    'languages' => ['ar', 'fr', 'en'],

    'fallback_languages' => ['fr', 'en', 'ar'],

    /* [code, language, body] */
    'templates' => [
        ['appointment_demo', 'fr', 'Bonjour {client_name}, votre démo {product_name} est confirmée le {date} à {time}. Lien : {link}'],
        ['appointment_demo', 'en', 'Hello {client_name}, your {product_name} demo is confirmed on {date} at {time}. Link: {link}'],
        ['appointment_demo', 'ar', 'مرحبا {client_name}، تم تأكيد العرض التجريبي {product_name} بتاريخ {date} على الساعة {time}. الرابط: {link}'],

        ['appointment_meeting', 'fr', 'Bonjour {client_name}, votre rendez-vous avec SOFTEL est confirmé le {date} à {time}. Lien : {link}'],
        ['appointment_meeting', 'en', 'Hello {client_name}, your meeting with SOFTEL is confirmed on {date} at {time}. Link: {link}'],
        ['appointment_meeting', 'ar', 'مرحبا {client_name}، تم تأكيد اجتماعكم مع Softel بتاريخ {date} على الساعة {time}. الرابط: {link}'],

        ['appointment_training', 'fr', 'Bonjour {client_name}, votre formation {product_name} est prévue le {date} de {time} à {end}. Support : {link}'],
        ['appointment_training', 'en', 'Hello {client_name}, your {product_name} training is on {date} from {time} to {end}. Material: {link}'],
        ['appointment_training', 'ar', 'مرحبا {client_name}، التدريب على {product_name} بتاريخ {date} من {time} إلى {end}. المرجع: {link}'],

        ['appointment_accompaniment', 'fr', "Bonjour {client_name}, votre séance d'accompagnement {product_name} est prévue le {date} de {time} à {end}. Lien : {link}"],
        ['appointment_accompaniment', 'en', 'Hello {client_name}, your {product_name} accompaniment session is on {date} from {time} to {end}. Link: {link}'],
        ['appointment_accompaniment', 'ar', 'مرحبا {client_name}، جلسة المرافقة {product_name} بتاريخ {date} من {time} إلى {end}. الرابط: {link}'],

        // First training / follow-up training keep their own wording.
        ['training_first', 'fr', 'Bonjour {client_name}, votre première formation {product_name} est prévue le {date} de {time} à {end}. Support : {link}'],
        ['training_first', 'en', 'Hello {client_name}, your first {product_name} training is on {date} from {time} to {end}. Material: {link}'],
        ['training_first', 'ar', 'مرحبا {client_name}، تدريبكم الأول على {product_name} بتاريخ {date} من {time} إلى {end}. المرجع: {link}'],
        ['training_review', 'fr', 'Bonjour {client_name}, voici le support de révision {product_name} : {link}'],
        ['training_review', 'en', 'Hello {client_name}, here is the {product_name} review material: {link}'],
        ['training_review', 'ar', 'مرحبا {client_name}، هذا مرجع مراجعة {product_name}: {link}'],
    ],

];
