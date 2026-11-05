<?php

use App\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::post('events', [AuditController::class, 'ingest']);
    Route::get('events/export', [AuditController::class, 'export']);
    Route::get('events/export-pdf', [AuditController::class, 'exportPdf']);
    Route::get('events', [AuditController::class, 'index']);
});
