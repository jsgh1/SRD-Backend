<?php

use App\Http\Controllers\TreasuryController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::get('treasury', [TreasuryController::class, 'index']);
    Route::get('treasury/export', [TreasuryController::class, 'export']);
    Route::get('treasury/export-pdf', [TreasuryController::class, 'exportPdf']);
    Route::post('treasury/opening', [TreasuryController::class, 'opening']);
    Route::post('treasury/movements', [TreasuryController::class, 'store']);
    Route::get('treasury/movements/{id}', [TreasuryController::class, 'show'])->whereUuid('id');
    Route::get('treasury/movements/{id}/pdf', [TreasuryController::class, 'receiptPdf'])->whereUuid('id');
    Route::get('treasury/movements/{id}/xlsx', [TreasuryController::class, 'receiptXlsx'])->whereUuid('id');
    Route::post('treasury/movements/{id}/reverse', [TreasuryController::class, 'reverse'])->whereUuid('id');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
