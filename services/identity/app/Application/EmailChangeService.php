<?php

namespace App\Application;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Srd\DependencyFailure;
use Srd\Outbox;

final class EmailChangeService
{
    public function __construct(private ChallengeService $challenges) {}

    public function request(array $principal, string $email, string $password): array
    {
        $result = DB::transaction(function () use ($principal, $email, $password) {
            $user = DB::table('users')->where('id', $principal['user_id'])->lockForUpdate()->first();
            abort_unless($user && $user->active && Hash::check($password, $user->password), 401);
            abort_if($email === $user->email || DB::table('users')->where('email', $email)->exists(), 422);
            DB::table('auth_sessions')->where('id', $principal['session_id'])->where('user_id', $user->id)
                ->update(['reauthenticated_at' => now()]);

            return $this->challenges->issue($user, $principal['organization_id'], 'email_change', $email);
        });

        return $this->delivered($result, $email);
    }

    public function resend(array $principal, string $challengeId): array
    {
        $result = DB::transaction(function () use ($principal, $challengeId) {
            $user = DB::table('users')->where('id', $principal['user_id'])->lockForUpdate()->first();
            $session = DB::table('auth_sessions')->where('id', $principal['session_id'])
                ->where('user_id', $user->id)->whereNull('revoked_at')
                ->where('reauthenticated_at', '>', now()->subMinutes(5))->first();
            abort_unless($session, 401);
            $challenge = DB::table('auth_challenges')->where('id', $challengeId)
                ->where('user_id', $user->id)->where('purpose', 'email_change')->whereNull('consumed_at')->first();
            abort_unless($challenge, 422);

            return $this->challenges->issue($user, $principal['organization_id'], 'email_change', $challenge->destination)
                + ['destination' => $challenge->destination];
        });

        return $this->delivered($result, $result['destination'] ?? '');
    }

    public function confirm(array $principal, string $challengeId, string $code): array|false
    {
        return DB::transaction(function () use ($principal, $challengeId, $code) {
            // User before challenge is also the lock order used by issue/resend.
            $user = DB::table('users')->where('id', $principal['user_id'])->lockForUpdate()->first();
            if (! $user || ! $user->active) {
                return false;
            }
            $challenge = $this->challenges->consume($challengeId, 'email_change', $code, $user->id);
            if (! $challenge) {
                return false;
            }
            if (DB::table('users')->where('email', $challenge->destination)->where('id', '!=', $user->id)->exists()) {
                return false;
            }

            DB::table('users')->where('id', $user->id)->update(['email' => $challenge->destination, 'updated_at' => now()]);
            $revokedIds = DB::table('auth_sessions')->where('user_id', $user->id)
                ->where('id', '!=', $principal['session_id'])->whereNull('revoked_at')
                ->lockForUpdate()->pluck('id')->all();
            if ($revokedIds !== []) {
                DB::table('auth_sessions')->whereIn('id', $revokedIds)->update(['revoked_at' => now()]);
                app(ChatSocketClosureQueue::class)->enqueue($revokedIds);
            }
            DB::table('auth_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('security_notices')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $principal['organization_id'],
                'user_id' => $user->id, 'destination_encrypted' => Crypt::encryptString($user->email),
                'kind' => 'email_changed', 'next_attempt_at' => now(), 'created_at' => now(),
            ]);
            Outbox::record('profile.email_changed', $principal['organization_id'], $user->id, $user->id);

            return $revokedIds;
        });
    }

    private function delivered(array $result, string $email): array
    {
        if ($result['delivery_failed'] ?? false) {
            throw new DependencyFailure;
        }

        return $result + ['destination' => $email];
    }
}
