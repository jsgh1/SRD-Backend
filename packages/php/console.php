<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Srd\OutboxPublisher;

Artisan::command('srd:outbox', function () {
    if (! Schema::hasTable('outbox_events')) {
        return;
    }
    $this->line(json_encode(app(OutboxPublisher::class)->publishBatch(), JSON_THROW_ON_ERROR));
    if (! @touch(storage_path('framework/scheduler-health/outbox-heartbeat'))) {
        $this->error('No se pudo actualizar el marcador de publicación.');

        return 1;
    }
})->purpose('Publicar eventos durables con reintentos y consumidor idempotente');
Artisan::command('srd:outbox-status', function () {
    if (! Schema::hasTable('outbox_events')) {
        $this->error('La tabla de eventos no está disponible. Ejecuta las migraciones.');
        return 1;
    }
    $this->line(json_encode(app(OutboxPublisher::class)->status(), JSON_THROW_ON_ERROR));
})->purpose('Consultar pendientes y agotados sin modificar ni reenviar eventos');
// A crashed invocation must not suppress publishing for Laravel's 24-hour default.
// A batch has at most 100 deliveries with a 15-second request timeout each.
Schedule::command('srd:outbox')->everyMinute()->withoutOverlapping(30);

Artisan::command('srd:scheduler-heartbeat', function () {
    $path = storage_path('framework/scheduler-health/scheduler-heartbeat');
    if (! @touch($path)) {
        $this->error('No se pudo actualizar el marcador del planificador.');

        return 1;
    }
})->purpose('Marcar el último ciclo ejecutado por el planificador');
Schedule::command('srd:scheduler-heartbeat')->everyMinute();
