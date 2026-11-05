<?php
namespace App\Application;

use Illuminate\Support\Facades\DB;

final class PresenceService
{
    // Public representation never reveals the invisible preference or last-seen dates.
    public function effective(string $userId): string
    {
        return $this->effectiveMany([$userId])[$userId] ?? 'offline';
    }

    public function effectiveMany(array $userIds): array
    {
        if (!$userIds) return [];
        $users = DB::table('users')->whereIn('id', $userIds)->where('active', true)->get(['id', 'presence']);
        $connected = DB::table('auth_sessions')->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where('last_activity_at', '>', now()->subMinutes(30))
            ->where('presence_seen_at', '>', now()->subSeconds(90))->distinct()->pluck('user_id')->all();
        $result = [];
        foreach ($users as $user) $result[$user->id] = $user->presence !== 'invisible' && in_array($user->id, $connected, true) ? $user->presence : 'offline';
        return $result;
    }

    public function heartbeat(string $token): array
    {
        $p = app(SessionService::class)->resolve($token, false, false);
        // A concurrent logout cannot be undone by a late heartbeat.
        $session = DB::table('auth_sessions')->where('id', $p['session_id'])
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where('last_activity_at', '>', now()->subMinutes(30));
        $session->update(['presence_seen_at' => now()]);
        abort_unless($session->exists(), 401);
        return ['effective' => $this->effective($p['user_id']), 'preference' => $p['user']['presence'], 'ttl_seconds' => 90];
    }
}
