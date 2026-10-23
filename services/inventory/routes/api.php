<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::post('assets/{id}/photo-access', \App\Http\Controllers\AssetPhotoAccessController::class)->whereUuid('id');
    Route::get('assets', [InventoryController::class, 'index']);
    Route::get('assets/export', [InventoryController::class, 'export']);
    Route::get('assets/export-pdf', [InventoryController::class, 'exportPdf']);
    Route::post('assets', [InventoryController::class, 'store']);
    Route::get('assets/{id}', [InventoryController::class, 'show'])->whereUuid('id');
    Route::get('assets/{id}/movements/export', [InventoryController::class, 'exportMovements'])->whereUuid('id');
    Route::get('assets/{id}/movements/export-pdf', [InventoryController::class, 'exportMovementsPdf'])->whereUuid('id');
    Route::patch('assets/{id}', [InventoryController::class, 'update'])->whereUuid('id');
    Route::post('assets/{id}/movements', [InventoryController::class, 'movement'])->whereUuid('id');
    Route::post('assets/{id}/retire', [InventoryController::class, 'retire'])->whereUuid('id');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
