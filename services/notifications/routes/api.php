<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::post('deliveries', [NotificationController::class, 'deliver']);
    Route::get('preferences', [NotificationController::class, 'preferences']);
    Route::put('preferences', [NotificationController::class, 'updatePreferences']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    Route::delete('notifications/{id}', [NotificationController::class, 'dismiss'])->whereUuid('id');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
