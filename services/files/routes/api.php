<?php
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::post('chat-attachments', [\App\Http\Controllers\ChatAttachmentController::class, 'store']);
    Route::get('chat-attachments/{id}', [\App\Http\Controllers\ChatAttachmentController::class, 'show'])->whereUuid('id');
    Route::get('folder-access', [\App\Http\Controllers\FolderAccessController::class,'show']);
    Route::put('folder-access', [\App\Http\Controllers\FolderAccessController::class,'update']);
    Route::get('folder-documents', [\App\Http\Controllers\FolderDocumentController::class,'index']);
    Route::get('folder-quota', \App\Http\Controllers\FolderQuotaController::class);
    Route::get('folder-documents/search', [\App\Http\Controllers\FolderDocumentController::class,'search']);
    Route::post('folder-documents', [\App\Http\Controllers\FolderDocumentController::class,'store']);
    Route::patch('folder-documents/{id}', [\App\Http\Controllers\FolderDocumentController::class,'rename'])->whereUuid('id');
    Route::post('folder-documents/{id}/move', [\App\Http\Controllers\FolderDocumentController::class,'move'])->whereUuid('id');
    Route::get('folder-documents/{id}/download', [\App\Http\Controllers\FolderDocumentController::class,'download'])->whereUuid('id');
    Route::get('folder-documents/{id}/preview', [\App\Http\Controllers\FolderDocumentController::class,'preview'])->whereUuid('id');
    Route::delete('folder-documents/{id}', [\App\Http\Controllers\FolderDocumentController::class,'destroy'])->whereUuid('id');
    Route::get('folders', [\App\Http\Controllers\FolderController::class, 'index']);
    Route::post('folders', [\App\Http\Controllers\FolderController::class, 'store']);
    Route::post('folders/{id}/move', [\App\Http\Controllers\FolderController::class, 'move'])->whereUuid('id');
    Route::patch('folders/{id}', [\App\Http\Controllers\FolderController::class, 'rename'])->whereUuid('id');
    Route::delete('folders/{id}', [\App\Http\Controllers\FolderController::class, 'destroy'])->whereUuid('id');
});

Route::post('photo-deletions/{id}', \App\Http\Controllers\PhotoDeletionController::class)->whereUuid('id')->middleware('internal');
Route::get('antivirus-status', \App\Http\Controllers\AntivirusStatusController::class)->middleware('internal');
Route::get('outbox-status', \App\Http\Controllers\FileOutboxStatusController::class)->middleware('internal');
Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id')->middleware('internal');

Route::middleware(['internal', \App\Http\Middleware\PhotoContext::class])->group(function () {
    Route::get('users/{id}/photos', [PhotoController::class, 'index'])->whereUuid('id');
    Route::get('users/{id}/photos/{slot}', [PhotoController::class, 'show'])->whereUuid('id')->whereIn('slot', ['avatar']);
    Route::put('users/{id}/photos/{slot}', [PhotoController::class, 'store'])->whereUuid('id')->whereIn('slot', ['avatar']);
    Route::delete('users/{id}/photos/{slot}', [PhotoController::class, 'destroy'])->whereUuid('id')->whereIn('slot', ['avatar']);
    Route::get('assets/{id}/photos', [PhotoController::class, 'index'])->whereUuid('id');
    Route::get('assets/{id}/photos/{slot}', [PhotoController::class, 'show'])->whereUuid('id')->whereIn('slot', ['front', 'side', 'detail']);
    Route::put('assets/{id}/photos/{slot}', [PhotoController::class, 'store'])->whereUuid('id')->whereIn('slot', ['front', 'side', 'detail']);
    Route::delete('assets/{id}/photos/{slot}', [PhotoController::class, 'destroy'])->whereUuid('id')->whereIn('slot', ['front', 'side', 'detail']);
    Route::get('persons/{id}/photos', [PhotoController::class, 'index'])->whereUuid('id');
    Route::get('persons/{id}/photos/{slot}', [PhotoController::class, 'show'])->whereUuid('id')->whereIn('slot', ['person', 'document', 'property']);
    Route::put('persons/{id}/photos/{slot}', [PhotoController::class, 'store'])->whereUuid('id')->whereIn('slot', ['person', 'document', 'property']);
    Route::delete('persons/{id}/photos/{slot}', [PhotoController::class, 'destroy'])->whereUuid('id')->whereIn('slot', ['person', 'document', 'property']);
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
