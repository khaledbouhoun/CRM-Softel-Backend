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

    'dashboard' => [
        'recent_activities_limit' => 10,
        /* "Upcoming" follow-ups counted on the dashboard: the next N days after today. */
        'upcoming_days' => (int) env('CRM_DASHBOARD_UPCOMING_DAYS', 7),
    ],

];
