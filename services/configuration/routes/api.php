<?php

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::get('quick-links', [\App\Http\Controllers\QuickLinkController::class, 'index']);
    Route::patch('quick-links/{scope}', [\App\Http\Controllers\QuickLinkController::class, 'update'])->whereIn('scope', ['organization', 'personal']);
    Route::get('platform/organizations', [\App\Http\Controllers\PlatformController::class, 'index']);
    Route::post('platform/organizations', [\App\Http\Controllers\PlatformController::class, 'store']);
    Route::patch('platform/organizations/{id}', [\App\Http\Controllers\PlatformController::class, 'status'])->whereUuid('id');
    Route::get('organizations/code/{code}', [OrganizationController::class, 'publicConfig']);
    Route::get('organizations/{id}', [OrganizationController::class, 'byId']);
    Route::patch('organization', [OrganizationController::class, 'update']);
    Route::post('organization/terms', [OrganizationController::class, 'terms']);
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
