<?php
return [
    // Maximum content length (characters)
    'max_content_length' => 10000,

    // Maximum views per share
    'max_views_limit' => 100,

    // Share expiration time (seconds) - 7 days
    'share_expiration' => 7 * 24 * 60 * 60,

    // Maximum number of active shares (prevents storage exhaustion)
    'max_active_shares' => 10000,

    // Rate limiting (file-based, per IP)
    'rate_limit' => [
        'enabled' => true,
        'max_requests_per_minute' => 10,
        'max_requests_per_hour' => 100
    ],

    // Allowed origins for CORS - only these domains can call the API
    // Leave empty to disable CORS (same-origin only)
    'allowed_origins' => [
    ],

    // Cleanup probability (1-100) - chance of running cleanup on each request
    'cleanup_probability' => 10,
];