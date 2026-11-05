<?php

require __DIR__.'/../../../packages/php/console.php';

\Illuminate\Support\Facades\Artisan::command('srd:chat-deliveries', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('chat_delivery_jobs')) return;
    $this->line(json_encode(app(\App\Application\ChatDeliveryService::class)->deliverBatch(), JSON_THROW_ON_ERROR));
})->purpose('Entregar avisos de Chat con reintentos e idempotencia');

\Illuminate\Support\Facades\Schedule::command('srd:chat-deliveries')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Artisan::command('srd:chat-call-expiry', function () {
    if (!\Illuminate\Support\Facades\Schema::hasColumn('voice_calls', 'expires_at')) return;
    $this->line((string) app(\App\Application\VoiceCallService::class)->expireBatch());
})->purpose('Cerrar llamadas abandonadas y retirar señales WebRTC');

\Illuminate\Support\Facades\Schedule::command('srd:chat-call-expiry')->everyMinute()->withoutOverlapping();
