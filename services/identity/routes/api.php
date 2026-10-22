<?php

\Illuminate\Support\Facades\Route::post('platform/organizations/{id}/administrators', [\App\Http\Controllers\MembershipController::class, 'inviteAdministrator'])->middleware('internal')->whereUuid('id');

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PlatformAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal')->group(function () {
    Route::post('persons/{id}/detach-for-erasure', \App\Http\Controllers\PersonLinkErasureController::class)->whereUuid('id');
    Route::get('platform/accounts', [PlatformAccountController::class, 'index']);
    Route::patch('platform/accounts/{id}', [PlatformAccountController::class, 'update'])->whereUuid('id');
    Route::post('calendar-participants/resolve', \App\Http\Controllers\CalendarParticipantsController::class);
    Route::post('chat-contacts/resolve', \App\Http\Controllers\ChatContactController::class);
    Route::get('contacts', \App\Http\Controllers\ContactController::class);
    Route::get('contacts/{id}', [\App\Http\Controllers\ContactController::class, 'show'])->whereUuid('id');
    Route::post('users/{id}/photo-access', \App\Http\Controllers\UserPhotoAccessController::class)->whereUuid('id');
    Route::post('persons/{id}/self-photo-access', \App\Http\Controllers\SelfPersonPhotoAccessController::class)->whereUuid('id');
    Route::post('auth/presence', function (\Illuminate\Http\Request $r, \App\Application\PresenceService $presence) {
        $d = $r->validate(['token' => 'required|string|max:256']);
        return ['data' => $presence->heartbeat($d['token'])];
    });
    Route::get('outbox-status', \Srd\OutboxStatusController::class);
    Route::get('mail-delivery-status', \App\Http\Controllers\MailDeliveryStatusController::class);
    Route::post('mail-delivery-status/{type}/{id}/retry', \App\Http\Controllers\MailDeliveryRetryController::class)
        ->whereIn('type', ['invitations', 'security-notices'])->whereUuid('id');
    Route::post('outbox-status/{id}/retry', \Srd\OutboxRetryController::class)->whereUuid('id');
    Route::get('members', [MembershipController::class, 'index']);
    Route::get('members/folder-readers', [MembershipController::class, 'folderReaders']);
    Route::post('members/folder-readers/verify', [MembershipController::class, 'verifyFolderReaders']);
    Route::patch('members/{id}', [MembershipController::class, 'update'])->whereUuid('id');
    Route::get('invitations', [MembershipController::class, 'invitations']);
    Route::get('invitations/person/{id}/status', [MembershipController::class, 'personInvitationStatus'])->whereUuid('id');
    Route::post('invitations', [MembershipController::class, 'invite']);
    Route::delete('invitations/{id}', [MembershipController::class, 'revoke'])->whereUuid('id');
    Route::post('invitations/inspect', [MembershipController::class, 'inspect']);
    Route::post('invitations/accept', [MembershipController::class, 'accept']);
    Route::post('profile/email-change', [EmailChangeController::class, 'request']);
    Route::post('profile/email-change/resend', [EmailChangeController::class, 'resend']);
    Route::post('profile/email-change/confirm', [EmailChangeController::class, 'confirm']);
    Route::patch('profile', [ProfileController::class, 'update']);
    foreach (['login', 'verify', 'resend', 'cancel', 'me', 'logout', 'recover', 'reset', 'revokeOthers', 'switchOrganization'] as $action) {
        Route::post('auth/'.$action, [AuthController::class, $action]);
    }
});

\Illuminate\Support\Facades\Route::get('scheduler-status', \Srd\SchedulerHealthController::class)->middleware('internal');
