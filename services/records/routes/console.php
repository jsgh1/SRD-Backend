<?php

require __DIR__.'/../../../packages/php/console.php';

\Illuminate\Support\Facades\Artisan::command('srd:photos-dispatch-deletions', function () {
    $this->line(json_encode(app(\App\Application\PhotoDeletionPublisher::class)->publishBatch()));
})->purpose('Entregar órdenes persistentes de limpieza de fotos tras borrar personas');
\Illuminate\Support\Facades\Schedule::command('srd:photos-dispatch-deletions')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Artisan::command('srd:person-export-jobs', function () {
    $this->line(json_encode(app(\App\Application\PersonExportJobs::class)->processBatch()));
})->purpose('Procesar exportaciones Excel y PDF de personas en segundo plano');
\Illuminate\Support\Facades\Schedule::command('srd:person-export-jobs')->everyMinute()->withoutOverlapping();
