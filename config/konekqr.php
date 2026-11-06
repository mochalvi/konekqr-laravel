<?php

return [
    // kq_live_… or kq_test_… from the Konek QR dashboard (API). Server side only.
    'api_key' => env('KONEKQR_API_KEY'),

    'base_url' => env('KONEKQR_BASE_URL', 'https://api.konekqr.web.id'),

    // Language of error details: id or en.
    'language' => env('KONEKQR_LANGUAGE', 'id'),

    // Seconds per request; long-polling events adds its wait on top.
    'timeout' => (int) env('KONEKQR_TIMEOUT', 30),

    // The endpoint's whsec_… secret. While rotating, list both, comma-separated.
    'webhook_secret' => env('KONEKQR_WEBHOOK_SECRET'),

    // Largest allowed distance between webhook-timestamp and now, in seconds.
    'webhook_tolerance' => (int) env('KONEKQR_WEBHOOK_TOLERANCE', 300),
];
