<?php

use App\Http\Controllers\PersonController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('privacy-erasure-status', [\App\Http\Controllers\PrivacyRequestController::class, 'erasureStatus']);
    Route::get('privacy-requests', [\App\Http\Controllers\PrivacyRequestController::class, 'index']);
    Route::post('privacy-requests', [\App\Http\Controllers\PrivacyRequestController::class, 'store']);
    Route::patch('privacy-requests/{id}', [\App\Http\Controllers\PrivacyRequestController::class, 'update'])->whereUuid('id');
    Route::post('privacy-requests/{id}/execute-erasure', [\App\Http\Controllers\PrivacyRequestController::class, 'executeErasure'])->whereUuid('id');
    Route::get('public-logo/{id}', [\App\Http\Controllers\PlanillaSettingsController::class, 'publicLogo'])->whereUuid('id');
    Route::get('planilla-settings', [\App\Http\Controllers\PlanillaSettingsController::class, 'index']);
    Route::put('planilla-settings', [\App\Http\Controllers\PlanillaSettingsController::class, 'update']);
    Route::get('person-filter-settings', [\App\Http\Controllers\PersonFilterSettingsController::class, 'index']);
    Route::put('person-filter-settings', [\App\Http\Controllers\PersonFilterSettingsController::class, 'update']);
    Route::post('persons/{id}/photo-access', \App\Http\Controllers\PhotoAccessController::class)->whereUuid('id');
    Route::get('person-positions', [\App\Http\Controllers\PersonPositionController::class, 'index']);
    Route::put('person-positions', [\App\Http\Controllers\PersonPositionController::class, 'update']);
    Route::get('person-fields', [\App\Http\Controllers\PersonFieldController::class, 'index']);
    Route::put('person-fields', [\App\Http\Controllers\PersonFieldController::class, 'update']);
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    $c = PersonController::class;
    Route::get('dashboard', [$c, 'dashboard']);
    Route::get('persons', [$c, 'index']);
    Route::get('persons/export', [$c, 'export']);
    Route::post('persons/export-jobs', [$c, 'queueExport']);
    Route::get('persons/export-jobs/{id}', [$c, 'exportJob'])->whereUuid('id');
    Route::get('persons/export-jobs/{id}/download', [$c, 'downloadExportJob'])->whereUuid('id');
    Route::get('persons/export-pdf', [$c, 'exportPdf']);
    Route::get('persons/planilla', [$c, 'planilla']);
    Route::get('persons/planilla-preview', [$c, 'planillaPreview']);
    Route::get('persons/planilla-pdf', [$c, 'planillaPdf']);
    Route::post('persons', [$c, 'save']);
    Route::get('persons/lookup', [$c, 'lookup']);
    Route::get('persons/{id}', [$c, 'show'])->whereUuid('id');
    Route::get('persons/{id}/xlsx', [$c, 'exportIndividual'])->whereUuid('id');
    Route::get('persons/{id}/pdf', [$c, 'exportIndividualPdf'])->whereUuid('id');
    Route::patch('persons/{id}', [$c, 'save'])->whereUuid('id');
    Route::delete('persons/{id}', [$c, 'delete'])->whereUuid('id');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
