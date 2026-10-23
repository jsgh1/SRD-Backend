<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\InternalClient;
use Srd\Outbox;

final class SessionService
{
    public function organization(string $id): array
    {
        return app(InternalClient::class)->call('configuration', 'GET', 'organizations/'.$id);
    }

    public function role(object $user, string $org): ?string
    {
        if (! $user->active) {
            return null;
        }
        if ($user->superadmin) {
            return 'superadmin';
        }

        return DB::table('memberships')->where('user_id', $user->id)->where('organization_id', $org)->where('active', true)->value('role');
    }

    public function create(object $user, string $org, string $terms): array
    {
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::uuid();
        DB::table('terms_acceptances')->insertOrIgnore(['user_id' => $user->id, 'organization_id' => $org, 'terms_version_id' => $terms, 'accepted_at' => now()]);
        DB::table('auth_sessions')->insert(['id' => $id, 'user_id' => $user->id, 'organization_id' => $org, 'token_hash' => hash('sha256', $token), 'last_activity_at' => now(), 'expires_at' => now()->addHours(8), 'created_at' => now()]);
        Outbox::record('auth.login', $org, $user->id, $id);

        return ['token' => $token];
    }

    public function resolve(string $token, bool $allowNewTerms = false, bool $touchActivity = true): array
    {
        $s = DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_activity_at', '>', now()->subMinutes(30))->first();
        abort_unless($s, 401);
        $user = DB::table('users')->where('id', $s->user_id)->first();
        $role = $user ? $this->role($user, $s->organization_id) : null;
        abort_unless($role, 401);
        $membership = $role === 'superadmin' ? null : DB::table('memberships')
            ->where('user_id', $user->id)->where('organization_id', $s->organization_id)
            ->where('active', true)->first(['id', 'version', 'person_id']);
        abort_unless($role === 'superadmin' || $membership, 401);
        $org = $this->organization($s->organization_id);
        $title = DB::table('member_profile_titles')->where('organization_id', $s->organization_id)
            ->where('user_id', $user->id)->first(['title', 'title_en']);
        $bio = DB::table('member_profile_bios')->where('organization_id', $s->organization_id)
            ->where('user_id', $user->id)->first(['bio', 'bio_en']);
        $accepted = DB::table('terms_acceptances')->where('user_id', $user->id)->where('organization_id', $org['id'])->where('terms_version_id', $org['terms']['id'])->exists();
        abort_unless($allowNewTerms || $accepted, 403);
        if ($touchActivity) DB::table('auth_sessions')->where('id', $s->id)->whereNull('revoked_at')->update(['last_activity_at' => now()]);

        return ['user_id' => $user->id, 'session_id' => $s->id, 'organization_id' => $org['id'], 'role' => $role,
            'membership_id' => $membership?->id, 'membership_version' => $membership ? (int)$membership->version : null,
            'person_id' => $membership?->person_id,
            'user' => ['name' => $user->name, 'email' => $user->email, 'theme' => $user->theme,
                'presence' => $user->presence, 'language' => $user->language,
                'title' => $title?->title, 'title_en' => $title?->title_en,
                'bio' => $bio?->bio, 'bio_en' => $bio?->bio_en],
            'organization' => $org, 'terms_required' => ! $accepted];
    }
}
