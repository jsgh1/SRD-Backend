<?php

use App\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::get('delivery-status', \App\Http\Controllers\DeliveryStatusController::class);
    Route::post('delivery-status/{id}/retry', \App\Http\Controllers\DeliveryRetryController::class)->whereUuid('id');
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::get('settings', [CalendarController::class, 'settings']);
    Route::put('settings', [CalendarController::class, 'updateSettings']);
    Route::get('invitations', [CalendarController::class, 'invitations']);
    Route::post('invitations/{id}/respond', [CalendarController::class, 'respond'])->whereUuid('id');
    Route::get('events', [CalendarController::class, 'index']);
    Route::get('events/search', [CalendarController::class, 'search']);
    Route::post('events', [CalendarController::class, 'create']);
    Route::get('events/{id}', [CalendarController::class, 'show'])->whereUuid('id');
    Route::patch('events/{id}', [CalendarController::class, 'update'])->whereUuid('id');
    Route::post('events/{id}/cancel', [CalendarController::class, 'cancel'])->whereUuid('id');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
