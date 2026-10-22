<?php

return [
    'service' => 'gateway',
    'internal_key' => env('INTERNAL_KEY'),
    'challenge_key' => env('CHALLENGE_KEY'),
    'chat_broadcast_key' => env('REVERB_APP_KEY'),
    'chat_broadcast_secret' => env('REVERB_APP_SECRET'),
    'chat_broadcast_app_id' => env('REVERB_APP_ID'),
    'chat_reverb_url' => env('CHAT_REVERB_URL', 'http://chat-reverb:8080'),
    'webrtc_stun_url' => env('WEBRTC_STUN_URL'),
    'webrtc_turn_url' => env('WEBRTC_TURN_URL'),
    'webrtc_turn_secret' => env('WEBRTC_TURN_SECRET'),
    'public_url' => env('PUBLIC_URL', 'http://localhost:8080'),
    'urls' => ['files' => env('FILES_URL', 'http://files:8000'), 'identity' => env('IDENTITY_URL', 'http://identity:8000'), 'configuration' => env('CONFIGURATION_URL', 'http://configuration:8000'), 'records' => env('RECORDS_URL', 'http://records:8000'), 'audit' => env('AUDIT_URL', 'http://audit:8000'), 'calendar' => env('CALENDAR_URL', 'http://calendar:8000'), 'notifications' => env('NOTIFICATIONS_URL', 'http://notifications:8000'), 'treasury' => env('TREASURY_URL', 'http://treasury:8000'), 'inventory' => env('INVENTORY_URL', 'http://inventory:8000'), 'chat' => env('CHAT_URL', 'http://chat:8000')],
];
