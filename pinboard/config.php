<?php
return [
    'max_title_length'    => 100,
    'max_text_length'     => 1500,
    'max_active_pins'     => 2000,
    'default_list_limit'  => 200,

    'allowed_expirations' => [
        120,        // 2 min
        600,        // 10 min
        3600,       // 1 ora
        21600,      // 6 ore
        43200,      // 12 ore
        86400,      // 24 ore
        604800,     // 7 giorni
    ],

    'rate_limit' => [
        'enabled'                 => true,
        'max_requests_per_minute' => 20,
        'max_requests_per_hour'   => 200,
    ],

    'allowed_origins'       => [],
    'cleanup_probability'   => 100,
];
