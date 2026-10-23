<?php

return [
    'service' => 'records',
    'internal_key' => env('INTERNAL_KEY'),
    'challenge_key' => env('CHALLENGE_KEY'),
    'public_url' => env('PUBLIC_URL', 'http://localhost:8080'),
    'urls' => ['files' => env('FILES_URL', 'http://files:8000'), 'identity' => env('IDENTITY_URL', 'http://identity:8000'), 'configuration' => env('CONFIGURATION_URL', 'http://configuration:8000'), 'records' => env('RECORDS_URL', 'http://records:8000'), 'notifications' => env('NOTIFICATIONS_URL', 'http://notifications:8000'), 'audit' => env('AUDIT_URL', 'http://audit:8000')],
];
