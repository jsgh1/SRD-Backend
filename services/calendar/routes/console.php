<?php

require __DIR__.'/../../../packages/php/console.php';

\Illuminate\Support\Facades\Artisan::command('srd:notification-deliveries', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('calendar_delivery_jobs')) return;
    $this->line(json_encode(app(\App\Application\DeliveryService::class)->deliverBatch(), JSON_THROW_ON_ERROR));
})->purpose('Entregar avisos de calendario con reintentos e idempotencia');

\Illuminate\Support\Facades\Schedule::command('srd:notification-deliveries')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Artisan::command('srd:calendar-backfill-reminders', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('calendar_delivery_jobs')) return;
    $this->line(json_encode(['scanned' => app(\App\Application\DeliveryService::class)->backfillFutureReminders()], JSON_THROW_ON_ERROR));
})->purpose('Preparar recordatorios de eventos anteriores sin duplicar entregas');
