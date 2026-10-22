<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\VoiceCallController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::get('chat-attachment-references/{id}', \App\Http\Controllers\ChatAttachmentReferenceController::class)->whereUuid('id');
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::get('conversations', [ChatController::class, 'index']);
    Route::post('conversations', [ChatController::class, 'start']);
    Route::get('conversations/{id}', [ChatController::class, 'show'])->whereUuid('id');
    Route::post('conversations/{id}/activity', [ChatController::class, 'activity'])->whereUuid('id');
    Route::get('conversations/{id}/messages', [ChatController::class, 'messages'])->whereUuid('id');
    Route::get('conversations/{id}/search', [ChatController::class, 'search'])->whereUuid('id');
    Route::get('conversations/{id}/pinned', [ChatController::class, 'pinned'])->whereUuid('id');
    Route::delete('conversations/{id}/messages', [ChatController::class, 'clear'])->whereUuid('id');
    Route::post('conversations/{id}/messages/{messageId}/pin', [ChatController::class, 'pin'])->whereUuid('id')->whereUuid('messageId');
    Route::delete('conversations/{id}/messages/{messageId}', [ChatController::class, 'hide'])->whereUuid('id')->whereUuid('messageId');
    Route::post('conversations/{id}/messages', [ChatController::class, 'send'])->whereUuid('id');
    Route::get('conversations/{id}/messages/{messageId}/attachment', [ChatController::class, 'attachment'])->whereUuid('id')->whereUuid('messageId');
    Route::post('conversations/{id}/receipts', [ChatController::class, 'receipt'])->whereUuid('id');
    Route::get('voice-calls', [VoiceCallController::class, 'index']);
    Route::post('conversations/{conversationId}/calls', [VoiceCallController::class, 'start'])->whereUuid('conversationId');
    Route::get('conversations/{conversationId}/calls/{callId}', [VoiceCallController::class, 'show'])->whereUuid('conversationId')->whereUuid('callId');
    Route::post('conversations/{conversationId}/calls/{callId}/transition', [VoiceCallController::class, 'transition'])->whereUuid('conversationId')->whereUuid('callId');
    Route::get('conversations/{conversationId}/calls/{callId}/signals', [VoiceCallController::class, 'signals'])->whereUuid('conversationId')->whereUuid('callId');
    Route::post('conversations/{conversationId}/calls/{callId}/signal', [VoiceCallController::class, 'signal'])->whereUuid('conversationId')->whereUuid('callId');
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
