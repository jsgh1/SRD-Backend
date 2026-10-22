<?php
return [
    'signature_marker' => env('CLAMD_UPDATE_MARKER', '/var/lib/clamav/srd-update-ok'),
    'objects' => env('PHOTO_OBJECTS', storage_path('app/private/photos')),
    'quarantine' => env('PHOTO_QUARANTINE', storage_path('app/private/quarantine')),
    'scanner' => env('CLAMD_ADDRESS', 'tcp://antivirus:3310'),
    'quota' => (int) env('PHOTO_QUOTA_BYTES', 5 * 1024 * 1024 * 1024),
];
