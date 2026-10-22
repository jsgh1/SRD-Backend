<?php

namespace App\Http\Controllers;

use App\Application\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class UserPhotoAccessController
{
    public function __invoke(Request $request, SessionService $sessions, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'files', 403);
        $action = $request->validate(['action' => 'required|in:contacts.read,profile.write'])['action'];
        $principal = Access::require($action);
        $actor = DB::table('users')->where('id', $principal['user_id'])->first();
        $target = DB::table('users')->where('id', $id)->first();
        abort_unless($actor && $target && $sessions->role($actor, $principal['organization_id'])
            && $sessions->role($target, $principal['organization_id']), 404);
        abort_if($action === 'profile.write' && $id !== $principal['user_id'], 403);

        return ['data' => ['authorized' => true]];
    }
}
