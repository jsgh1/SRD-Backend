<?php

namespace App\Application;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Srd\Outbox;
use Srd\InternalClient;

final class InvitationService
{
    public function __construct(private MembershipService $members, private SessionService $sessions) {}

    private function digest(string $id, string $secret): string
    {
        return hash_hmac('sha256', 'invitation|'.$id.'|'.$secret, config('srd.challenge_key'));
    }

    public function listing(array $p, int $page): array
    {
        $q = DB::table('invitations')->where('organization_id', $p['organization_id']);

        return ['items' => (clone $q)->select('id', 'email', 'role', 'person_id', 'expires_at', 'consumed_at', 'revoked_at', 'sent_at', 'delivery_attempts', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 25)->get(), 'total' => $q->count(), 'page' => $page, 'page_size' => 25];
    }

    public function personStatus(array $p, string $personId): array
    {
        $organizationId = $p['organization_id'];
        if (DB::table('memberships')->where('organization_id', $organizationId)->where('person_id', $personId)->exists()) {
            return ['status' => 'active'];
        }
        $pending = DB::table('invitations')->where('organization_id', $organizationId)->where('person_id', $personId)
            ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        return ['status' => $pending ? 'pending' : 'available'];
    }

    private function person(array $p, string $id, string $email): array
    {
        $person = app(InternalClient::class)->call('records', 'GET', 'persons/'.$id, [], $p);
        abort_unless(strtolower(trim((string) ($person['email'] ?? ''))) === $email, 422,
            'El correo debe coincidir con el registro de la persona.');
        return $person;
    }

    public function issue(array $p, string $email, string $role, ?string $personId = null): array
    {
        $this->sessions->organization($p['organization_id']);
        abort_unless($this->members->canInvite($p['user_id'], $p['organization_id'], $role, $personId), 403);
        if ($personId !== null) $this->person($p, $personId, $email);

        return DB::transaction(function () use ($p, $email, $role, $personId) {
            $this->members->lockOrganization($p['organization_id']);
            abort_unless($this->members->canInvite($p['user_id'], $p['organization_id'], $role, $personId), 403);
            if ($personId !== null) abort_if(DB::table('memberships')->where('organization_id', $p['organization_id'])
                ->where('person_id', $personId)->exists(), 409);
            $user = DB::table('users')->where('email', $email)->first();
            abort_if($user && ($user->superadmin || ! $user->active || DB::table('memberships')->where('organization_id', $p['organization_id'])->where('user_id', $user->id)->exists()), 409);
            $q = DB::table('invitations')->where('organization_id', $p['organization_id'])->where('email', $email);
            $last = (clone $q)->orderByDesc('created_at')->first();
            abort_if($last && Carbon::parse($last->created_at)->gt(now()->subMinute()), 429);
            abort_if((clone $q)->where('created_at', '>', now()->subHour())->count() >= 5, 429);
            (clone $q)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'secret_encrypted' => null]);
            if ($personId !== null) DB::table('invitations')->where('organization_id', $p['organization_id'])
                ->where('person_id', $personId)->whereNull('consumed_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'secret_encrypted' => null]);
            $id = (string) Str::uuid();
            $secret = bin2hex(random_bytes(32));
            DB::table('invitations')->insert(['id' => $id, 'organization_id' => $p['organization_id'], 'invited_by' => $p['user_id'], 'email' => $email,
                'role' => $role, 'person_id' => $personId, 'secret_hash' => $this->digest($id, $secret), 'secret_encrypted' => Crypt::encryptString($secret),
                'expires_at' => now()->addDay(), 'next_attempt_at' => now(), 'created_at' => now()]);
            Outbox::record('invitation.created', $p['organization_id'], $p['user_id'], $id);

            return ['id' => $id, 'message' => 'Invitación pendiente de envío. El planificador intentará entregarla al correo local.'];
        });
    }

    public function revoke(array $p, string $id): void
    {
        DB::transaction(function () use ($p, $id) {
            $this->members->lockOrganization($p['organization_id']);
            abort_unless($this->members->canManage($p['user_id'], $p['organization_id']), 403);
            $row = DB::table('invitations')->where('organization_id', $p['organization_id'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->consumed_at, 409);
            if (! $row->revoked_at) {
                DB::table('invitations')->where('id', $id)->update(['revoked_at' => now(), 'secret_encrypted' => null]);
                Outbox::record('invitation.revoked', $p['organization_id'], $p['user_id'], $id);
            }
        });
    }

    private function valid(?object $row, string $secret): bool
    {
        return $row && ! $row->consumed_at && ! $row->revoked_at && $row->sent_at && $row->accept_attempts < 5
            && Carbon::parse($row->expires_at)->gt(now()) && hash_equals($row->secret_hash, $this->digest($row->id, $secret));
    }

    public function inspect(string $id, string $secret): array
    {
        $row = DB::table('invitations')->where('id', $id)->first();
        abort_unless($this->valid($row, $secret), 422);
        abort_unless($this->members->canInvite($row->invited_by, $row->organization_id, $row->role, $row->person_id), 422);
        $org = $this->sessions->organization($row->organization_id);

        return ['email' => $row->email, 'role' => $row->role, 'organization' => $org,
            'existing_account' => DB::table('users')->where('email', $row->email)->exists()];
    }

    public function accept(array $data): array
    {
        $hint = DB::table('invitations')->where('id', $data['invitation_id'])->first();
        abort_unless($this->valid($hint, $data['secret']), 422);
        if ($hint->person_id) $this->person([
            'user_id' => $hint->invited_by, 'organization_id' => $hint->organization_id,
            'role' => 'viewer',
        ], $hint->person_id, $hint->email);
        $result = DB::transaction(function () use ($data, $hint) {
            $this->members->lockOrganization($hint->organization_id);
            $row = DB::table('invitations')->where('id', $hint->id)->lockForUpdate()->first();
            if (! $this->valid($row, $data['secret']) || ! $this->members->canInvite($row->invited_by, $row->organization_id, $row->role, $row->person_id)) {
                return null;
            }
            $org = $this->sessions->organization($row->organization_id);
            abort_unless($org['terms']['id'] === $data['terms_version_id'], 409);
            $user = DB::table('users')->where('email', $row->email)->lockForUpdate()->first();
            if ($user && (! $user->active || $user->superadmin || ! Hash::check($data['password'], $user->password))) {
                DB::table('invitations')->where('id', $row->id)->increment('accept_attempts');
                Outbox::record('invitation.accept_rejected', $row->organization_id, null, $row->id, 'rejected');

                return null;
            }
            if ($user && DB::table('memberships')->where('organization_id', $row->organization_id)->where('user_id', $user->id)->exists()) {
                return null;
            }
            if ($row->person_id && DB::table('memberships')->where('organization_id', $row->organization_id)
                ->where('person_id', $row->person_id)->exists()) return null;
            $userId = $user->id ?? (string) Str::uuid();
            if (! $user) {
                DB::table('users')->insert(['id' => $userId, 'email' => $row->email, 'name' => $data['name'],
                    'password' => Hash::make($data['password']), 'language' => $data['language'] ?? 'es',
                    'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId, 'organization_id' => $row->organization_id,
                'role' => $row->role, 'person_id' => $row->person_id]);
            DB::table('terms_acceptances')->insertOrIgnore(['user_id' => $userId, 'organization_id' => $row->organization_id, 'terms_version_id' => $data['terms_version_id'], 'accepted_at' => now()]);
            DB::table('invitations')->where('id', $row->id)->update(['consumed_at' => now(), 'secret_encrypted' => null]);
            Outbox::record('invitation.accepted', $row->organization_id, $userId, $row->id);

            return ['organization_code' => $org['code'], 'message' => 'Invitación aceptada. Inicia sesión y verifica el código de acceso.'];
        });
        abort_unless($result, 422);

        return $result;
    }

    public function deliver(): void
    {
        $rows = DB::table('invitations')->whereNull('sent_at')->whereNull('revoked_at')->whereNull('consumed_at')
            ->where('delivery_attempts', '<', 4)->where('next_attempt_at', '<=', now())->limit(50)->get(['id', 'organization_id']);
        foreach ($rows as $hint) {
            DB::transaction(function () use ($hint) {
                $this->members->lockOrganization($hint->organization_id);
                $row = DB::table('invitations')->where('id', $hint->id)->lockForUpdate()->first();
                if (! $row || $row->sent_at || $row->revoked_at || $row->consumed_at || $row->delivery_attempts >= 4 || Carbon::parse($row->next_attempt_at)->gt(now())) {
                    return;
                }
                if (Carbon::parse($row->expires_at)->lte(now()) || ! $this->members->canInvite($row->invited_by, $row->organization_id, $row->role, $row->person_id)) {
                    DB::table('invitations')->where('id', $row->id)->update(['revoked_at' => now(), 'secret_encrypted' => null]);

                    return;
                }
                try {
                    $org = $this->sessions->organization($row->organization_id);
                    $recipient = DB::table('users')->where('email', $row->email)->first(['language']);
                    $preferred = $recipient?->language ?? DB::table('users')->where('id', $row->invited_by)->value('language');
                    $language = $preferred === 'en' ? 'en' : 'es';
                    $link = config('srd.public_url').'/invite#'.$row->id.'.'.Crypt::decryptString($row->secret_encrypted).'.'.$language;
                    Mail::send('invitation', ['link' => $link, 'organization' => $org['name'], 'organizationCode' => $org['code'], 'language' => $language],
                        fn ($mail) => $mail->to($row->email)->subject($language === 'en' ? 'Private invitation to SRD' : 'Invitación privada a SRD'));
                    DB::table('invitations')->where('id', $row->id)->update(['sent_at' => now(), 'secret_encrypted' => null]);
                    Outbox::record('invitation.sent', $row->organization_id, $row->invited_by, $row->id);
                } catch (\Throwable) {
                    $attempt = $row->delivery_attempts + 1;
                    DB::table('invitations')->where('id', $row->id)->update(['delivery_attempts' => $attempt, 'next_attempt_at' => now()->addMinutes([1, 5, 15, 15][$attempt - 1])]);
                }
            });
        }
    }
}
