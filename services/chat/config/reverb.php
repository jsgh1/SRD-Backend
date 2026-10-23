<?php

return [
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            'host' => '0.0.0.0',
            'port' => 8080,
            'path' => '',
            'hostname' => null,
            'options' => ['tls' => []],
            'max_request_size' => 10000,
            'scaling' => ['enabled' => false, 'channel' => 'reverb', 'server' => []],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => ['host' => env('REVERB_HOST', 'chat-reverb'), 'port' => 8080,
                'scheme' => 'http', 'useTLS' => false],
            'allowed_origins' => [env('REVERB_ALLOWED_ORIGIN', 'localhost')],
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_connections' => 1000,
            'max_message_size' => 10000,
            'accept_client_events_from' => 'none',
            'rate_limiting' => ['enabled' => true, 'max_attempts' => 60,
                'decay_seconds' => 60, 'terminate_on_limit' => true],
        ]],
    ],
];
