<?php

/*
|--------------------------------------------------------------------------
| SOFTEL CRM settings
|--------------------------------------------------------------------------
|
| Runtime behaviour of the CRM API. Business data that users configure from
| the application (stages, products, loss reasons) lives in the database,
| not here; see config/crm_seed.php for the initial values.
|
*/

return [

    /*
    | Networks allowed to call the API. Comma separated IPs or CIDR ranges.
    | Defaults to loopback + private (RFC 1918) LAN ranges. Requests from any
    | other address receive HTTP 403. Set to "*" to disable the check.
    */
    'allowed_networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CRM_ALLOWED_NETWORKS', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))
    ))),

    /* Requests per minute per client IP. */
    'rate_limit_per_minute' => (int) env('CRM_RATE_LIMIT_PER_MINUTE', 600),

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    'stage_transitions' => [
        /*
        | When false, a prospect that reached a won or lost stage cannot be
        | moved to any other stage anymore.
        */
        'allow_reopening_closed' => (bool) env('CRM_ALLOW_REOPENING_CLOSED', true),
    ],

    /*
    | Suggested stable codes for the optional prospect profile fields. Clients
    | translate them; the API also accepts other values (free text for
    | business_activity / previous_software, new codes for source) so the list
    | can grow without a migration.
    */
    'catalogs' => [
        'business_activities' => ['wholesale', 'retail', 'manufacturing', 'construction', 'services', 'other'],
        'prospect_sources' => ['facebook', 'instagram', 'friend_referral', 'customer_referral', 'website', 'phone_call', 'other'],
        'previous_software' => ['sage', 'pc_compta', 'excel', 'other_software', 'none', 'other'],
    ],

    /*
    | Defaults for the scheduling rules. Every key can be overridden from the
    | CRM settings screen (stored in crm_settings); nothing here is a business
    | constant. working_days uses 0 = Sunday ... 6 = Saturday (Algerian week by default).
    */
    'scheduling' => [
        'working_days' => [0, 1, 2, 3, 4],
        'work_start' => '09:00',
        'work_end' => '16:00',
        'slot_minutes' => 60,
        'hold_expiry_minutes' => 1440,
        'booking_horizon_days' => 30,
        'min_notice_hours' => 12,
        'accompaniment_occurrences' => 4,
        'accompaniment_interval_days' => 7,
        'trial_default_days' => 14,
        'whatsapp_country_code' => '213',
    ],

    /*
    | Customer portal surface (/api/portal/v1). It is NOT protected by the LAN
    | allow-list: it is called server-to-server by the public website with the
    | shared service key, and by customer bearer tokens issued by this API.
    */
    'portal' => [
        'service_key' => env('CRM_PORTAL_SERVICE_KEY'),
        /* Optional extra IP/CIDR allow-list for the website server. Empty = key only. */
        'allowed_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('CRM_PORTAL_ALLOWED_NETWORKS', ''))))),
        'token_ttl_days' => (int) env('CRM_PORTAL_TOKEN_TTL_DAYS', 30),
        'reset_ttl_minutes' => (int) env('CRM_PORTAL_RESET_TTL_MINUTES', 60),
        'rate_limit_per_minute' => (int) env('CRM_PORTAL_RATE_LIMIT_PER_MINUTE', 120),
        'login_attempts_per_minute' => (int) env('CRM_PORTAL_LOGIN_ATTEMPTS', 5),
        'upload_max_kb' => 5120,
        'upload_mimes' => ['jpg', 'jpeg', 'png', 'pdf', 'txt', 'zip'],
    ],

    /*
    | Files uploaded by employees and installers of product releases. They are
    | stored through Laravel's Storage facade on a private disk (default disk
    | unless CRM_UPLOAD_DISK is set, e.g. "s3"), so moving to cloud storage is a
    | configuration change. PHP's own upload_max_filesize / post_max_size (php.ini)
    | must be at least as large as the limits below.
    */
    'uploads' => [
        'disk' => env('CRM_UPLOAD_DISK'),
        'staff_attachment_max_kb' => (int) env('CRM_STAFF_UPLOAD_MAX_KB', 102400),
        'staff_attachment_extensions' => ['pdf', 'docx', 'txt', 'exe', 'zip', 'rar', 'jpg', 'jpeg', 'png'],
        'max_files_per_reply' => 5,
        'installer_max_kb' => (int) env('CRM_INSTALLER_MAX_KB', 1048576),
        'installer_extensions' => ['exe', 'zip', 'rar'],
    ],

    'dashboard' => [
        'recent_activities_limit' => 10,
        /* "Upcoming" follow-ups counted on the dashboard: the next N days after today. */
        'upcoming_days' => (int) env('CRM_DASHBOARD_UPCOMING_DAYS', 7),
    ],

];
