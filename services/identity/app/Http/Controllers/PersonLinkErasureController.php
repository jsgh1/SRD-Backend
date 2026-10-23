<?php

namespace App\Http\Controllers;

use App\Application\ChatSocketClosureQueue;
use App\Application\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Outbox;

final class PersonLinkErasureController
{
    public function __invoke(Request $request, string $id, MembershipService $members): array
    {
        abort_unless($request->attributes->get('issuer') === 'records', 403);
        $p = $request->attributes->get('principal', []);
        abort_unless(is_array($p) && isset($p['organization_id'], $p['user_id'])
            && preg_match('/^[0-9a-f-]{36}$/i', $p['organization_id'])
            && preg_match('/^[0-9a-f-]{36}$/i', $p['user_id']), 403);

        return DB::transaction(function () use ($p, $id, $members) {
            $org = $p['organization_id'];
            $members->lockOrganization($org);
            $linked = DB::table('memberships')->where('organization_id', $org)
                ->where('person_id', $id)->lockForUpdate()->get();
            $userIds = $linked->pluck('user_id')->all();
            foreach ($linked as $membership) {
                DB::table('memberships')->where('id', $membership->id)->update([
                    'person_id' => null, 'version' => $membership->version + 1,
                ]);
                Outbox::record('membership.updated', $org, $p['user_id'], $membership->id);
            }
            $invitations = DB::table('invitations')->where('organization_id', $org)
                ->where('person_id', $id)->lockForUpdate()->pluck('id')->all();
            if ($invitations !== []) DB::table('invitations')->whereIn('id', $invitations)->update([
                'person_id' => null, 'revoked_at' => now(), 'secret_encrypted' => null,
            ]);
            foreach ($invitations as $invitationId) DB::table('invitations')->where('id', $invitationId)
                ->update(['email' => 'erased+'.$invitationId.'@invalid.local']);
            if ($userIds !== []) {
                $sessionIds = DB::table('auth_sessions')->where('organization_id', $org)
                    ->whereIn('user_id', $userIds)->whereNull('revoked_at')
                    ->lockForUpdate()->pluck('id')->all();
                if ($sessionIds !== []) {
                    DB::table('auth_sessions')->whereIn('id', $sessionIds)->update(['revoked_at' => now()]);
                    app(ChatSocketClosureQueue::class)->enqueue($sessionIds);
                }
                DB::table('auth_challenges')->where('organization_id', $org)
                    ->whereIn('user_id', $userIds)->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            }
            return ['data' => ['detached' => true]];
        });
    }
}
