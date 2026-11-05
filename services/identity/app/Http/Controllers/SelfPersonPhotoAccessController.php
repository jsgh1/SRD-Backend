<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class SelfPersonPhotoAccessController
{
    public function __invoke(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'records', 403);
        $p = Access::require('persons.read');
        abort_unless(isset($p['session_id']), 403);
        $session = DB::table('auth_sessions')->where('id', $p['session_id'])
            ->where('user_id', $p['user_id'])->where('organization_id', $p['organization_id'])
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where('last_activity_at', '>', now()->subMinutes(30))->exists();
        abort_unless($session, 403);
        $linked = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $p['organization_id'])->where('m.user_id', $p['user_id'])
            ->where('m.person_id', $id)->where('m.active', true)->where('u.active', true)->exists();
        abort_unless($linked, 403);

        return ['data' => ['authorized' => true]];
    }
}
