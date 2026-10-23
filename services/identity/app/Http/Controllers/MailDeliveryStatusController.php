<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class MailDeliveryStatusController
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require('mail.delivery.read');
        $now = now();

        $invitations = DB::table('invitations')->where('organization_id', $principal['organization_id'])
            ->whereNull('sent_at')->whereNull('revoked_at')->whereNull('consumed_at');
        $validInvitations = (clone $invitations)->where('expires_at', '>', $now);
        $retryableInvitations = (clone $validInvitations)->where('delivery_attempts', '<', 4);

        $notices = DB::table('security_notices')->where('organization_id', $principal['organization_id'])
            ->whereNull('sent_at');
        $retryableNotices = (clone $notices)->where('attempts', '<', 4);

        return ['data' => [
            'invitations' => [
                'pending' => (clone $invitations)->count(),
                'due' => (clone $retryableInvitations)->where('next_attempt_at', '<=', $now)->count(),
                'deferred' => (clone $retryableInvitations)->where('next_attempt_at', '>', $now)->count(),
                'exhausted' => (clone $validInvitations)->where('delivery_attempts', '>=', 4)->count(),
                'expired' => (clone $invitations)->where('expires_at', '<=', $now)->count(),
                'exhausted_items' => (clone $validInvitations)->where('delivery_attempts', '>=', 4)
                    ->orderBy('created_at')->orderBy('id')->limit(20)
                    ->get(['id', 'delivery_attempts as attempts', 'created_at', 'secret_encrypted'])
                    ->map(fn ($row) => ['id' => $row->id, 'attempts' => $row->attempts,
                        'created_at' => $row->created_at, 'retryable' => $row->secret_encrypted !== null]),
            ],
            'security_notices' => [
                'pending' => (clone $notices)->count(),
                'due' => (clone $retryableNotices)->where('next_attempt_at', '<=', $now)->count(),
                'deferred' => (clone $retryableNotices)->where('next_attempt_at', '>', $now)->count(),
                'exhausted' => (clone $notices)->where('attempts', '>=', 4)->count(),
                'exhausted_items' => (clone $notices)->where('attempts', '>=', 4)
                    ->orderBy('created_at')->orderBy('id')->limit(20)
                    ->get(['id', 'attempts', 'created_at', 'destination_encrypted'])
                    ->map(fn ($row) => ['id' => $row->id, 'attempts' => $row->attempts,
                        'created_at' => $row->created_at, 'retryable' => $row->destination_encrypted !== null]),
            ],
        ]];
    }
}
