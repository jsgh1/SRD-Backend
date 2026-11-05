<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Outbox;

final class MailDeliveryRetryController
{
    public function __invoke(Request $request, string $type, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require('mail.delivery.manage');
        abort_unless(in_array($type, ['invitations', 'security-notices'], true), 404);

        DB::transaction(function () use ($principal, $type, $id) {
            if ($type === 'invitations') {
                $row = DB::table('invitations')->where('id', $id)
                    ->where('organization_id', $principal['organization_id'])->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_unless($row->sent_at === null && $row->revoked_at === null
                    && $row->consumed_at === null && $row->secret_encrypted !== null
                    && CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture()
                    && (int) $row->delivery_attempts >= 4, 409);
                DB::table('invitations')->where('id', $id)->update([
                    'delivery_attempts' => 0, 'next_attempt_at' => now(),
                ]);
                $action = 'mail.invitation_retry_requested';
            } else {
                $row = DB::table('security_notices')->where('id', $id)
                    ->where('organization_id', $principal['organization_id'])->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_unless($row->sent_at === null && $row->destination_encrypted !== null
                    && (int) $row->attempts >= 4, 409);
                DB::table('security_notices')->where('id', $id)->update([
                    'attempts' => 0, 'next_attempt_at' => now(),
                ]);
                $action = 'mail.security_notice_retry_requested';
            }
            Outbox::record($action, $principal['organization_id'], $principal['user_id'], $id);
        });

        return ['data' => ['queued' => true]];
    }
}
