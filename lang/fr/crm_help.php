<?php

/*
| Aide des paramètres, en français. Même structure que config/crm_help.php
| (anglais, langue de repli). Servie par GET /api/v1/settings/help?locale=fr.
*/

return [

    'scheduling' => [
        'title' => 'Règles de planification',
        'help' => 'Définissez quand les clients et les employés peuvent réserver des démos, des formations et des accompagnements.',
        'fields' => [
            'working_days' => ['label' => 'Jours ouvrés', 'help' => 'Jours où l\'on peut réserver un rendez-vous. 0 = dimanche ... 6 = samedi.', 'example' => '[0, 1, 2, 3, 4] pour dimanche à jeudi'],
            'work_start' => ['label' => 'Heure d\'ouverture', 'help' => 'Première heure à laquelle un créneau peut commencer (24 h, HH:MM).', 'example' => '09:00'],
            'work_end' => ['label' => 'Heure de fermeture', 'help' => 'Heure avant laquelle chaque créneau doit se terminer. Doit être postérieure à l\'ouverture.', 'example' => '16:00'],
            'slot_minutes' => ['label' => 'Durée d\'un créneau (minutes)', 'help' => 'Durée d\'un créneau réservable. Les clients choisissent parmi des créneaux de cette durée.', 'example' => '60'],
            'hold_expiry_minutes' => ['label' => 'Durée de blocage (minutes)', 'help' => 'Temps pendant lequel un créneau de démo demandé reste réservé en attendant la confirmation de l\'équipe, avant d\'être libéré.', 'example' => '1440 (24 heures)'],
            'booking_horizon_days' => ['label' => 'Horizon de réservation (jours)', 'help' => 'Jusqu\'à combien de jours à l\'avance un client peut réserver.', 'example' => '30'],
            'min_notice_hours' => ['label' => 'Préavis minimum (heures)', 'help' => 'Délai minimum entre maintenant et la réservation, pour laisser à l\'équipe le temps de se préparer.', 'example' => '12'],
            'accompaniment_occurrences' => ['label' => 'Séances d\'accompagnement', 'help' => 'Nombre de séances créées par défaut lors de la planification d\'une série d\'accompagnement.', 'example' => '4'],
            'accompaniment_interval_days' => ['label' => 'Jours entre les séances', 'help' => 'Écart par défaut entre deux séances d\'une série d\'accompagnement.', 'example' => '7 (chaque semaine)'],
            'trial_default_days' => ['label' => 'Durée d\'essai par défaut (jours)', 'help' => 'Durée d\'un accès d\'essai ou de démo quand aucune date de fin n\'est choisie.', 'example' => '14'],
            'whatsapp_country_code' => ['label' => 'Indicatif WhatsApp du pays', 'help' => 'Chiffres uniquement, sans le signe plus. Sert à transformer les numéros locaux en numéros internationaux.', 'example' => '213 pour l\'Algérie'],
        ],
    ],

    'appointment_types' => [
        'title' => 'Types de rendez-vous',
        'help' => 'Les quatre types sont fixes : démo, réunion, formation et accompagnement. Vous pouvez seulement régler la durée par défaut, la couleur et la possibilité de réserver en ligne.',
        'fields' => [
            'default_duration_minutes' => ['label' => 'Durée par défaut (minutes)', 'help' => 'Utilisée quand un rendez-vous est créé sans heure de fin.', 'example' => '120 pour une formation'],
            'color' => ['label' => 'Couleur du calendrier', 'help' => 'Couleur hexadécimale affichée dans le calendrier.', 'example' => '#16A34A'],
            'customer_bookable' => ['label' => 'Réservable par les clients', 'help' => 'Si activé, les clients peuvent demander ce type depuis le site web.', 'example' => 'Activé pour la démo, désactivé pour les autres'],
        ],
    ],

    'holidays' => [
        'title' => 'Jours fériés',
        'help' => 'Jours sans rendez-vous, en plus des jours non ouvrés.',
        'fields' => [
            'date' => ['label' => 'Date', 'help' => 'Le jour fermé.', 'example' => '2026-07-05'],
            'name' => ['label' => 'Nom', 'help' => 'Affiché dans le calendrier.', 'example' => 'Fête de l\'indépendance'],
        ],
    ],

    'products' => [
        'title' => 'Produits',
        'help' => 'Le catalogue de produits est la source unique du CRM et du site web public.',
        'fields' => [
            'name' => ['label' => 'Nom', 'help' => 'Nom affiché aux employés et aux clients.', 'example' => 'GestCom Ultra'],
            'pack_id' => ['label' => 'Pack', 'help' => 'Regroupe les niveaux d\'une même suite. Un client ne peut choisir qu\'un produit par pack.', 'example' => 'GestCom (Basic, Ultra)'],
            'slug' => ['label' => 'Identifiant web', 'help' => 'Mots en minuscules séparés par des tirets, utilisés dans l\'adresse du site. Généré depuis le nom s\'il est vide.', 'example' => 'gestcom-ultra'],
            'demo_url' => ['label' => 'Lien de la démo', 'help' => 'Lien permanent de la démo du produit. Les démos sont générées à partir de lui ; un produit sans lien ne peut pas être proposé en démo.', 'example' => 'https://demo.softel.dz/gestcom'],
            'is_active_on_web' => ['label' => 'Visible sur le site', 'help' => 'Si activé (et si le produit est actif), il apparaît dans le catalogue public.', 'example' => 'Activé'],
            'full_description' => ['label' => 'Description complète', 'help' => 'Texte de présentation long pour la page du produit.', 'example' => 'Gestion complète des ventes et du stock pour les grossistes...'],
            'features' => ['label' => 'Fonctionnalités', 'help' => 'Une courte ligne par fonctionnalité.', 'example' => '["Facturation", "Stock multi-dépôts"]'],
            'specifications' => ['label' => 'Caractéristiques', 'help' => 'Détails techniques sous forme clé/valeur.', 'example' => '{"Plateforme": "Windows", "Base de données": "MySQL"}'],
        ],
    ],

    'intervention_types' => [
        'title' => 'Types d\'intervention',
        'help' => 'Types de demandes après-vente (bug, question, service...). Les types sont désactivés, jamais supprimés, pour conserver l\'historique.',
        'fields' => [
            'code' => ['label' => 'Code', 'help' => 'Identifiant stable : lettres minuscules, chiffres et tirets bas. Ne peut plus être modifié ensuite.', 'example' => 'bug'],
            'name' => ['label' => 'Nom', 'help' => 'Libellé affiché dans l\'application.', 'example' => 'Bug'],
            'source' => ['label' => 'Créé par', 'help' => 'Qui peut ouvrir ce type : le client, l\'employé ou les deux.', 'example' => 'client'],
        ],
    ],

    'intervention_statuses' => [
        'title' => 'Statuts d\'intervention',
        'help' => 'Étapes du processus de support. Les transitions décident quel statut peut en suivre un autre.',
        'fields' => [
            'customer_label' => ['label' => 'Libellé client', 'help' => 'Formulation vue par les clients à la place du nom interne.', 'example' => 'Nous avons besoin de plus d\'informations'],
            'is_initial' => ['label' => 'Statut initial', 'help' => 'Statut donné à une nouvelle intervention. Un seul statut doit être initial.', 'example' => 'Nouvelle'],
            'is_closed' => ['label' => 'Clôture l\'intervention', 'help' => 'Atteindre ce statut bloque les réponses jusqu\'à ce qu\'un employé la rouvre.', 'example' => 'Résolue'],
            'awaits_customer' => ['label' => 'En attente du client', 'help' => 'Marque les interventions pour lesquelles l\'équipe attend le client.', 'example' => 'En attente d\'informations du client'],
            'is_delivered' => ['label' => 'Livrée', 'help' => 'Indique que le correctif ou la fonctionnalité a été livré.', 'example' => 'Livrée'],
        ],
    ],

    'intervention_transitions' => [
        'title' => 'Transitions de statut',
        'help' => 'Quel statut peut suivre quel autre. Un statut sans transition sortante est une impasse : gardez au moins une sortie pour chaque statut ouvert.',
        'fields' => [
            'from_status_id' => ['label' => 'Statut de départ', 'help' => 'Statut de l\'intervention avant le changement.', 'example' => 'Nouvelle'],
            'to_status_id' => ['label' => 'Statut d\'arrivée', 'help' => 'Statut vers lequel l\'intervention passe.', 'example' => 'En cours'],
            'customer_allowed' => ['label' => 'Autorisée pour les clients', 'help' => 'Si activé, le client peut déclencher cette transition depuis le portail (par exemple pour rouvrir).', 'example' => 'Désactivé de Résolue vers En cours'],
        ],
    ],

    'intervention_priorities' => [
        'title' => 'Priorités',
        'help' => 'Niveaux d\'urgence avec un objectif de réponse.',
        'fields' => [
            'response_target_hours' => ['label' => 'Objectif de réponse (heures)', 'help' => 'Délai attendu avant une première réponse. Vide = pas d\'objectif.', 'example' => '24'],
        ],
    ],

    'message_templates' => [
        'title' => 'Modèles de messages',
        'help' => 'Textes WhatsApp pour les rendez-vous : un modèle par type de rendez-vous (démo, réunion, formation, accompagnement) et par langue (ar, fr, en). Le client reçoit le modèle dans sa langue, ou en français si le sien manque. L\'employé ouvre un lien WhatsApp prérempli et appuie sur envoyer.',
        'fields' => [
            'code' => ['label' => 'Code', 'help' => 'Rendez-vous concerné : appointment_demo, appointment_meeting, appointment_training, appointment_accompaniment (training_first / training_review servent d\'abord pour les formations).', 'example' => 'appointment_training'],
            'locale' => ['label' => 'Langue', 'help' => 'ar, fr ou en. Correspond à la langue définie sur le client.', 'example' => 'fr'],
            'body' => ['label' => 'Message', 'help' => 'Texte avec des champs remplis automatiquement : {client_name}, {company_name}, {product_name}, {date}, {time}, {end}, {link}, {employee}, {appointment_type}.', 'example' => 'Bonjour {client_name}, votre formation {product_name} est prévue le {date} de {time} à {end}.'],
        ],
    ],

    'product_resources' => [
        'title' => 'Ressources produit',
        'help' => 'Guides, vidéos et liens que peuvent ouvrir les clients qui possèdent le produit.',
        'fields' => [
            'title' => ['label' => 'Titre', 'help' => 'Nom affiché au client.', 'example' => 'Guide de démarrage rapide'],
            'url' => ['label' => 'Lien', 'help' => 'Adresse http(s) publique de la ressource.', 'example' => 'https://drive.example.com/guide.pdf'],
            'kind' => ['label' => 'Type', 'help' => 'pdf, video, link ou other.', 'example' => 'pdf'],
            'customer_visible' => ['label' => 'Visible par les clients', 'help' => 'Désactivez pour du matériel interne.', 'example' => 'Activé'],
            'is_training_material' => ['label' => 'Support de formation', 'help' => 'Joint automatiquement aux rappels de formation.', 'example' => 'Activé'],
        ],
    ],

];
