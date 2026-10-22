<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class MembershipTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private string $org = '11111111-1111-4111-8111-111111111111';
    private string $other = '22222222-2222-4222-8222-222222222222';
    private string $terms = '33333333-3333-4333-8333-333333333333';
    private string $admin = '44444444-4444-4444-8444-444444444444';
    private array $principal;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert(['id' => $this->admin, 'email' => 'admin@example.test', 'name' => 'Administrador sintético', 'password' => Hash::make('Clave-de-prueba-123'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->admin, 'organization_id' => $this->org, 'role' => 'admin']);
        $this->principal = ['user_id' => $this->admin, 'organization_id' => $this->org, 'role' => 'admin'];
        Http::fake(fn ($request) => Http::response(['data' => ['id' => $this->org, 'code' => 'junta-prueba', 'name' => 'Junta sintética', 'terms' => ['id' => $this->terms, 'version' => 1, 'body' => 'Términos de prueba para vinculación.']]]));
    }

    private function invite(string $email = 'nuevo@example.test', string $role = 'registrar'): array
    {
        $id = $this->internal('POST', 'invitations', ['email' => $email, 'role' => $role], $this->principal)->assertOk()->json('data.id');
        $this->artisan('srd:invitations')->assertExitCode(0);
        $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        preg_match('/\/invite#([a-f0-9-]+)\.([a-f0-9]{64})/', $message->getHtmlBody(), $m);
        $this->assertCount(3, $m);
        $this->assertSame($id, $m[1]);
        return ['invitation_id' => $id, 'secret' => $m[2]];
    }

    public function test_person_invitation_status_tracks_pending_and_accepted_access(): void
    {
        $person = (string) Str::uuid();
        $path = 'invitations/person/'.$person.'/status';
        $this->internal('GET', $path, [], $this->principal)->assertOk()->assertJsonPath('data.status', 'available');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn ($request) => Http::response(['data' => ['id' => $person, 'email' => 'person@example.test']]));
        $this->internal('POST', 'invitations', ['email' => 'person@example.test', 'role' => 'viewer', 'person_id' => $person], $this->principal)->assertOk();
        $this->internal('GET', $path, [], $this->principal)->assertOk()->assertJsonPath('data.status', 'pending');
        $member = (string) Str::uuid();
        DB::table('users')->insert(['id' => $member, 'email' => 'person@example.test', 'name' => 'Persona',
            'password' => Hash::make('Clave-de-prueba-123'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $member,
            'organization_id' => $this->org, 'role' => 'viewer', 'person_id' => $person]);
        $this->internal('GET', $path, [], $this->principal)->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_registrar_can_invite_only_a_matching_person_as_viewer_and_the_link_survives_acceptance(): void
    {
        $registrar = (string) Str::uuid();
        $person = (string) Str::uuid();
        DB::table('users')->insert(['id' => $registrar, 'email' => 'registrar@example.test', 'name' => 'Registradora',
            'password' => Hash::make('Clave-de-prueba-123'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $registrar,
            'organization_id' => $this->org, 'role' => 'registrar']);
        $principal = ['user_id' => $registrar, 'organization_id' => $this->org, 'role' => 'registrar'];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function ($request) use ($person) {
            if (str_contains($request->url(), '/persons/')) return Http::response(['data' => [
                'id' => $person, 'email' => 'persona@example.test', 'first_names' => 'Persona',
            ]]);
            return Http::response(['data' => ['id' => $this->org, 'code' => 'junta-prueba', 'name' => 'Junta sintética',
                'terms' => ['id' => $this->terms, 'version' => 1, 'body' => 'Términos de prueba.']]]);
        });
        $payload = ['email' => 'persona@example.test', 'role' => 'viewer', 'person_id' => $person];
        $this->internal('POST', 'invitations', $payload, $principal)->assertOk();
        $this->assertDatabaseHas('invitations', ['email' => 'persona@example.test', 'person_id' => $person, 'role' => 'viewer']);
        $this->internal('POST', 'invitations', array_replace($payload, ['role' => 'admin']), $principal)->assertForbidden();
        $this->internal('POST', 'invitations', array_replace($payload, ['person_id' => null]), $principal)->assertForbidden();
        $this->internal('POST', 'invitations', array_replace($payload, ['email' => 'otro@example.test']), $principal)->assertUnprocessable();
        $this->artisan('srd:invitations')->assertExitCode(0);
        $mail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringContainsString('<strong>junta-prueba</strong>', $mail->getHtmlBody());
        preg_match('/\/invite#([a-f0-9-]+)\.([a-f0-9]{64})/', $mail->getHtmlBody(), $match);
        $this->assertCount(3, $match);
        $this->internal('POST', 'invitations/accept', $this->acceptance(['invitation_id' => $match[1], 'secret' => $match[2]]))->assertOk();
        $this->assertDatabaseHas('memberships', ['organization_id' => $this->org, 'role' => 'viewer', 'person_id' => $person]);
    }

    public function test_only_the_active_account_linked_to_a_person_may_edit_its_own_photo(): void
    {
        $viewer = (string) Str::uuid();
        $person = (string) Str::uuid();
        $session = (string) Str::uuid();
        DB::table('users')->insert(['id' => $viewer, 'email' => 'self-photo@example.test', 'name' => 'Foto propia',
            'password' => Hash::make('Clave-de-prueba-123'), 'created_at' => now(), 'updated_at' => now()]);
        $membership = (string) Str::uuid();
        DB::table('memberships')->insert(['id' => $membership, 'user_id' => $viewer,
            'organization_id' => $this->org, 'role' => 'viewer', 'person_id' => $person]);
        DB::table('auth_sessions')->insert(['id' => $session, 'user_id' => $viewer,
            'organization_id' => $this->org, 'token_hash' => hash('sha256', 'self-photo-test'),
            'last_activity_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $principal = ['user_id' => $viewer, 'organization_id' => $this->org, 'role' => 'viewer', 'session_id' => $session];
        $this->internal('POST', "persons/$person/self-photo-access", [], $principal, 'records')
            ->assertOk()->assertJsonPath('data.authorized', true);
        $this->internal('POST', 'persons/'.Str::uuid().'/self-photo-access', [], $principal, 'records')->assertForbidden();
        $this->internal('POST', "persons/$person/self-photo-access", [], $principal, 'files')->assertUnauthorized();
        DB::table('auth_sessions')->where('id', $session)->update(['revoked_at' => now()]);
        $this->internal('POST', "persons/$person/self-photo-access", [], $principal, 'records')->assertForbidden();
        DB::table('auth_sessions')->where('id', $session)->update(['revoked_at' => null]);
        DB::table('memberships')->where('id', $membership)->update(['active' => false]);
        $this->internal('POST', "persons/$person/self-photo-access", [], $principal, 'records')->assertForbidden();
    }

    public function test_invitation_uses_existing_recipient_language_before_inviter_language(): void
    {
        DB::table('users')->insert([
            'id' => (string) Str::uuid(), 'email' => 'english-recipient@example.test',
            'name' => 'English recipient', 'password' => Hash::make('Clave-de-prueba-123'),
            'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $credential = $this->invite('english-recipient@example.test');
        $mail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Private invitation to SRD', $mail->getSubject());
        $this->assertStringContainsString('<html lang="en">', $mail->getHtmlBody());
        $this->assertStringContainsString('Review invitation', $mail->getHtmlBody());
        $this->assertStringNotContainsString('Revisar invitación', $mail->getHtmlBody());
        $this->assertStringContainsString('.en"', $mail->getHtmlBody());
        $this->internal('POST', 'invitations/accept', array_replace($this->acceptance($credential), [
            'language' => 'es',
        ]))->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'english-recipient@example.test', 'language' => 'en']);
    }

    public function test_new_recipient_inherits_inviter_language_for_the_invitation(): void
    {
        DB::table('users')->where('id', $this->admin)->update(['language' => 'en']);
        $credential = $this->invite('new-recipient@example.test');
        $mail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Private invitation to SRD', $mail->getSubject());
        $this->assertStringContainsString('<html lang="en">', $mail->getHtmlBody());
        $this->assertStringContainsString('<strong>Junta sintética</strong> invited you', $mail->getHtmlBody());
        $this->assertStringContainsString('Review invitation', $mail->getHtmlBody());
        $this->assertStringContainsString('.en"', $mail->getHtmlBody());
        $this->internal('POST', 'invitations/accept', array_replace($this->acceptance($credential), [
            'language' => 'fr',
        ]))->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'new-recipient@example.test']);
        $this->internal('POST', 'invitations/accept', array_replace($this->acceptance($credential), [
            'language' => 'en',
        ]))->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'new-recipient@example.test', 'language' => 'en']);
    }

    public function test_folder_reader_candidates_are_active_scoped_and_versioned(): void
    {
        $ids = [];
        foreach (['viewer' => $this->org, 'registrar' => $this->other] as $role => $org) {
            $user = (string) Str::uuid(); $id = (string) Str::uuid(); $ids[$role] = $id;
            DB::table('users')->insert(['id'=>$user,'name'=>'Lector 50%_!','email'=>$role.'@example.test',
                'password'=>'unused-test-hash','created_at'=>now(),'updated_at'=>now()]);
            DB::table('memberships')->insert(['id'=>$id,'user_id'=>$user,'organization_id'=>$org,'role'=>$role,'version'=>3]);
        }
        $this->internal('GET','members/folder-readers',['search'=>'50%_!'],$this->principal)->assertOk()
            ->assertJsonPath('data.total',1)->assertJsonPath('data.items.0.id',$ids['viewer']);
        $this->internal('POST','members/folder-readers/verify',['ids'=>[$ids['viewer']]],$this->principal)
            ->assertOk()->assertJsonPath('data.items.0.version',3);
        $this->internal('POST','members/folder-readers/verify',['ids'=>[]],$this->principal)
            ->assertOk()->assertJsonPath('data.items',[]);
        $this->internal('POST','members/folder-readers/verify',['ids'=>[$ids['registrar']]],$this->principal)->assertUnprocessable();
        DB::table('memberships')->where('id',$ids['viewer'])->update(['active'=>false]);
        $this->internal('POST','members/folder-readers/verify',['ids'=>[$ids['viewer']]],$this->principal)->assertUnprocessable();
        $this->internal('GET','members/folder-readers',[],array_replace($this->principal,['role'=>'viewer']))->assertForbidden();
    }

    public function test_contacts_are_minimal_scoped_paginated_and_literal_search(): void
    {
        for ($i = 0; $i < 29; $i++) {
            $id = (string) Str::uuid();
            DB::table('users')->insert(['id' => $id, 'name' => $i === 0 ? 'Equipo 50%_!' : 'Contacto '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => 'contact-'.$i.'@example.test', 'password' => 'unused-test-hash', 'active' => $i !== 28, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'organization_id' => $i === 26 ? $this->other : $this->org, 'role' => 'viewer', 'active' => $i !== 27]);
        }
        foreach (\Srd\Access::ROLES as $role) {
            $this->internal('GET', 'contacts', ['organization_id' => $this->other], array_replace($this->principal, ['role' => $role]))->assertOk()->assertJsonPath('data.total', 26)->assertJsonCount(25, 'data.items')->assertJsonMissingPath('data.items.0.email')->assertJsonMissingPath('data.items.0.password')->assertJsonMissingPath('data.items.0.preference');
        }
        $first = $this->internal('GET', 'contacts', [], $this->principal)->json('data.items');
        $last = $this->internal('GET', 'contacts', ['page' => 2], $this->principal)->assertOk()->assertJsonCount(1, 'data.items')->json('data.items');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($last, 'id')));
        $this->internal('GET', 'contacts', ['q' => '50%_!'], $this->principal)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.name', 'Equipo 50%_!');
        $contactId = DB::table('users')->where('name', 'Equipo 50%_!')->value('id');
        DB::table('member_profile_titles')->insert(['organization_id' => $this->org,
            'user_id' => $contactId, 'title' => 'Secretaria', 'title_en' => 'Secretary']);
        $this->internal('GET', 'contacts', ['q' => '50%_!'], $this->principal)->assertOk()
            ->assertJsonPath('data.items.0.title', 'Secretaria')->assertJsonPath('data.items.0.title_en', 'Secretary');
        $this->internal('GET', 'contacts/'.$contactId, [], $this->principal)->assertOk()
            ->assertJsonPath('data.title', 'Secretaria')->assertJsonPath('data.title_en', 'Secretary')
            ->assertJsonPath('data.name', 'Equipo 50%_!')->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.presence', 'offline')->assertJsonPath('data.email', 'contact-0@example.test');
        $this->internal('GET', 'contacts/'.$contactId, [], array_replace($this->principal,
            ['organization_id' => $this->other]))->assertNotFound();
        $this->internal('GET', 'contacts/'.$this->admin, [], $this->principal)->assertNotFound();
        DB::table('memberships')->where('organization_id', $this->org)->where('user_id', $contactId)
            ->update(['active' => false]);
        $this->internal('GET', 'contacts/'.$contactId, [], $this->principal)->assertNotFound();
        DB::table('memberships')->where('organization_id', $this->org)->where('user_id', $contactId)
            ->update(['active' => true]);
        $this->internal('GET', 'contacts', ['q' => 'missing'], $this->principal)->assertOk()->assertJsonPath('data.total', 0);
        $this->internal('GET', 'contacts', ['q' => str_repeat('x', 121)], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'contacts', ['page' => 0], $this->principal)->assertUnprocessable();
        $this->getJson('/internal/v1/contacts')->assertUnauthorized();
    }

    public function test_calendar_resolves_only_active_members_of_the_claimed_junta(): void
    {
        $member = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        foreach ([$member => $this->org, $foreign => $this->other] as $id => $organization) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Integrante '.substr($id, 0, 5), 'email' => $id.'@example.test', 'password' => 'unused-test-hash', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'organization_id' => $organization, 'role' => 'viewer']);
        }
        $path = 'calendar-participants/resolve';
        $this->internal('POST', $path, ['user_ids' => [$this->admin, $member]], $this->principal, 'calendar')
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonMissingPath('data.items.0.email');
        $this->internal('POST', $path, ['user_ids' => []], $this->principal, 'calendar')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->internal('POST', $path, ['user_ids' => [$foreign]], $this->principal, 'calendar')->assertUnprocessable();
        DB::table('memberships')->where('user_id', $member)->update(['active' => false]);
        $this->internal('POST', $path, ['user_ids' => [$member]], $this->principal, 'calendar')->assertUnprocessable();
        $this->internal('POST', $path, ['user_ids' => [$this->admin]], $this->principal, 'gateway')->assertForbidden();
        $this->internal('GET', 'contacts', [], $this->principal, 'calendar')->assertUnauthorized();
    }

    public function test_chat_resolves_only_another_active_member_with_minimal_fields(): void
    {
        $member = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        foreach ([$member => $this->org, $foreign => $this->other] as $id => $organization) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Contacto de prueba', 'email' => $id.'@example.test',
                'password' => 'unused-test-hash', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $id,
                'organization_id' => $organization, 'role' => 'viewer']);
        }
        $path = 'chat-contacts/resolve';
        $item = $this->internal('POST', $path, ['user_id' => $member], $this->principal, 'chat')
            ->assertOk()->json('data');
        $this->assertSame(['id', 'name', 'role'], array_keys($item));
        $this->internal('POST', $path, ['user_id' => $foreign], $this->principal, 'chat')->assertUnprocessable();
        $this->internal('POST', $path, ['user_id' => $this->admin], $this->principal, 'chat')->assertUnprocessable();
        DB::table('users')->where('id', $member)->update(['active' => false]);
        $this->internal('POST', $path, ['user_id' => $member], $this->principal, 'chat')->assertUnprocessable();
        $this->internal('POST', $path, ['user_id' => $foreign], $this->principal, 'gateway')->assertForbidden();
        $this->internal('GET', 'contacts', [], $this->principal, 'chat')->assertUnauthorized();
    }

    public function test_contacts_never_reveal_invisible_preference_or_last_seen(): void
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'name' => 'Contacto privado', 'email' => 'hidden@example.test', 'password' => 'unused-test-hash', 'presence' => 'invisible', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'organization_id' => $this->org, 'role' => 'viewer']);
        app(\App\Application\SessionService::class)->create(DB::table('users')->where('id', $id)->first(), $this->org, $this->terms);
        DB::table('auth_sessions')->where('user_id', $id)->update(['presence_seen_at' => now()]);
        $item = $this->internal('GET', 'contacts', [], $this->principal)->assertOk()->assertJsonPath('data.items.0.presence', 'offline')->json('data.items.0');
        $this->assertSame(['id', 'name', 'role', 'presence', 'title', 'title_en'], array_keys($item));
        $this->assertNull($item['title']);
        $this->assertNull($item['title_en']);
        DB::table('users')->where('id', $id)->update(['presence' => 'away']);
        $this->internal('GET', 'contacts', [], $this->principal)->assertOk()->assertJsonPath('data.items.0.presence', 'away');
        $this->travel(90)->seconds();
        $this->internal('GET', 'contacts', [], $this->principal)->assertOk()->assertJsonPath('data.items.0.presence', 'offline');
    }

    public function test_only_real_superadmin_can_invite_administrator_for_another_junta(): void
    {
        $path = 'platform/organizations/'.$this->other.'/administrators';
        $data = ['email' => 'initial@example.test', 'role' => 'superadmin', 'organization_id' => $this->org];
        $this->internal('POST', $path, $data, $this->principal)->assertForbidden();
        $p = array_replace($this->principal, ['role' => 'superadmin']);
        $this->internal('POST', $path, $data, $p)->assertForbidden();
        DB::table('users')->where('id', $this->admin)->update(['superadmin' => true]);
        $this->internal('POST', $path, $data, $p)->assertOk();
        $this->assertDatabaseHas('invitations', ['organization_id' => $this->other, 'email' => 'initial@example.test', 'role' => 'admin']);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('auth_sessions', 0);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['error' => ['message' => 'Suspendida']], 403));
        $this->internal('POST', $path, ['email' => 'second@example.test'], $p)->assertForbidden();
        $this->assertDatabaseCount('invitations', 1);
    }

    private function acceptance(array $credential): array
    {
        return $credential + ['name' => 'Nueva cuenta sintética', 'password' => 'Clave-de-prueba-123', 'password_confirmation' => 'Clave-de-prueba-123', 'accepted' => true, 'terms_version_id' => $this->terms];
    }

    public function test_invitation_is_private_delivered_once_and_acceptance_does_not_create_session(): void
    {
        $id = $this->internal('POST', 'invitations', ['email' => 'nuevo@example.test', 'role' => 'registrar'], $this->principal)->assertOk()->json('data.id');
        $row = DB::table('invitations')->first();
        $secret = Crypt::decryptString($row->secret_encrypted);
        $credential = ['invitation_id' => $id, 'secret' => $secret];
        $this->internal('POST', 'invitations/inspect', $credential)->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->artisan('srd:invitations')->assertExitCode(0);
        $this->artisan('srd:invitations')->assertExitCode(0);
        $this->assertCount(1, Mail::getSymfonyTransport()->messages());
        $invitationMail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Invitación privada a SRD', $invitationMail->getSubject());
        $this->assertStringContainsString('<html lang="es">', $invitationMail->getHtmlBody());
        $this->assertNull(DB::table('invitations')->value('secret_encrypted'));
        $this->internal('POST', 'invitations/inspect', $credential)->assertOk()->assertJsonPath('data.existing_account', false);
        $this->internal('GET', 'invitations', [], $this->principal)->assertOk()->assertJsonMissingPath('data.items.0.secret_hash')->assertJsonMissingPath('data.items.0.secret_encrypted');
        $this->internal('POST', 'invitations/accept', array_replace($this->acceptance($credential), ['accepted' => false]))->assertUnprocessable();
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential) + ['role' => 'superadmin'])->assertOk();
        $user = DB::table('users')->where('email', 'nuevo@example.test')->first();
        $this->assertFalse((bool) $user->superadmin);
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'organization_id' => $this->org, 'role' => 'registrar']);
        $this->assertDatabaseCount('auth_sessions', 0);
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        $this->assertDatabaseHas('outbox_events', ['action' => 'invitation.accepted']);
    }

    public function test_existing_account_requires_its_password_and_is_linked_without_overwrite(): void
    {
        $userId = (string) Str::uuid();
        $hash = Hash::make('Clave-de-prueba-123');
        DB::table('users')->insert(['id' => $userId, 'email' => 'existente@example.test', 'name' => 'Nombre original', 'password' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId, 'organization_id' => $this->other, 'role' => 'viewer']);
        $credential = $this->invite('existente@example.test');
        $this->internal('POST', 'invitations/inspect', $credential)->assertOk()->assertJsonPath('data.existing_account', true);
        for ($i = 0; $i < 5; $i++) $this->internal('POST', 'invitations/accept', array_replace($this->acceptance($credential), ['password' => 'Otra-clave-falsa-123', 'password_confirmation' => 'Otra-clave-falsa-123']))->assertUnprocessable();
        $this->assertSame(5, DB::table('invitations')->value('accept_attempts'));
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        $this->travel(61)->seconds();
        $fresh = $this->invite('existente@example.test');
        $this->internal('POST', 'invitations/accept', $this->acceptance($fresh))->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['id' => $userId, 'name' => 'Nombre original', 'password' => $hash]);
        $this->assertSame(2, DB::table('memberships')->where('user_id', $userId)->count());
    }

    public function test_roles_tenant_boundaries_and_self_changes_are_enforced(): void
    {
        $this->internal('POST', 'invitations', ['email' => 'nuevo@example.test', 'role' => 'superadmin'], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'members', [], array_replace($this->principal, ['role' => 'registrar']))->assertForbidden();
        $this->internal('POST', 'invitations', ['email' => 'nuevo@example.test', 'role' => 'viewer'], array_replace($this->principal, ['organization_id' => $this->other]))->assertForbidden();
        $own = DB::table('memberships')->value('id');
        $this->internal('PATCH', 'members/'.$own, ['role' => 'viewer', 'active' => false, 'version' => 1], $this->principal)->assertForbidden();
        $foreign = (string) Str::uuid();
        DB::table('memberships')->insert(['id' => $foreign, 'user_id' => $this->admin, 'organization_id' => $this->other, 'role' => 'admin']);
        $this->internal('PATCH', 'members/'.$foreign, ['role' => 'viewer', 'active' => false, 'version' => 1], $this->principal)->assertNotFound();
        $this->internal('GET', 'members', [], $this->principal)->assertOk()->assertJsonPath('data.total', 1);
        $credential = $this->invite();
        $foreignPrincipal = array_replace($this->principal, ['organization_id' => $this->other]);
        $this->internal('DELETE', 'invitations/'.$credential['invitation_id'], [], $foreignPrincipal)->assertNotFound();
    }

    public function test_expired_revoked_and_orphaned_invitations_are_rejected(): void
    {
        $credential = $this->invite();
        $this->travel(25)->hours();
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        $credential = $this->invite();
        $this->internal('DELETE', 'invitations/'.$credential['invitation_id'], [], $this->principal)->assertOk();
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        $this->travel(61)->seconds();
        $credential = $this->invite();
        DB::table('memberships')->where('user_id', $this->admin)->update(['active' => false]);
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_member_change_revokes_only_selected_organization_sessions_and_checks_version(): void
    {
        $credential = $this->invite();
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertOk();
        $user = DB::table('users')->where('email', 'nuevo@example.test')->first();
        $member = DB::table('memberships')->where('user_id', $user->id)->first();
        foreach ([$this->org, $this->other] as $org) DB::table('auth_sessions')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'organization_id' => $org, 'token_hash' => hash('sha256', $org), 'last_activity_at' => now(), 'expires_at' => now()->addHours(8), 'created_at' => now()]);
        $revokedId = DB::table('auth_sessions')->where('organization_id', $this->org)->value('id');
        $this->internal('PATCH', 'members/'.$member->id, ['role' => 'superadmin', 'active' => true, 'version' => 1], $this->principal)->assertUnprocessable();
        $data = ['role' => 'viewer', 'active' => false, 'version' => 1];
        $this->internal('PATCH', 'members/'.$member->id, $data, $this->principal)->assertOk()
            ->assertJsonPath('data.revoked_session_ids', [$revokedId]);
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $revokedId, 'confirmed_at' => null]);
        $this->internal('PATCH', 'members/'.$member->id, $data, $this->principal)->assertConflict();
        $this->assertNotNull(DB::table('auth_sessions')->where('organization_id', $this->org)->value('revoked_at'));
        $this->assertNull(DB::table('auth_sessions')->where('organization_id', $this->other)->value('revoked_at'));
        $this->assertDatabaseHas('memberships', ['id' => $member->id, 'active' => false, 'role' => 'viewer', 'version' => 2]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'active' => true]);
    }

    public function test_platform_accounts_are_protected_and_last_junta_admin_cannot_be_removed(): void
    {
        $super = (string) Str::uuid();
        DB::table('users')->insert(['id' => $super, 'email' => 'plataforma@example.test', 'name' => 'Plataforma', 'password' => DB::table('users')->value('password'), 'superadmin' => true, 'created_at' => now(), 'updated_at' => now()]);
        $superMember = (string) Str::uuid();
        DB::table('memberships')->insert(['id' => $superMember, 'organization_id' => $this->org, 'user_id' => $super, 'role' => 'superadmin']);
        $this->internal('PATCH', 'members/'.$superMember, ['role' => 'viewer', 'active' => false, 'version' => 1], $this->principal)->assertForbidden();
        $principal = array_replace($this->principal, ['user_id' => $super, 'role' => 'superadmin']);
        $adminMember = DB::table('memberships')->where('user_id', $this->admin)->value('id');
        $this->internal('PATCH', 'members/'.$adminMember, ['role' => 'viewer', 'active' => false, 'version' => 1], $principal)->assertConflict();
        $this->assertDatabaseHas('memberships', ['id' => $adminMember, 'active' => true, 'role' => 'admin']);
        $this->internal('POST', 'invitations', ['email' => 'plataforma@example.test', 'role' => 'viewer'], $this->principal)->assertConflict();
    }

    public function test_delivery_failure_is_durable_bounded_and_resend_invalidates_old_link(): void
    {
        $credential = $this->invite();
        $this->internal('POST', 'invitations', ['email' => 'nuevo@example.test', 'role' => 'viewer'], $this->principal)->assertTooManyRequests();
        $this->travel(61)->seconds();
        $id = $this->internal('POST', 'invitations', ['email' => 'nuevo@example.test', 'role' => 'viewer'], $this->principal)->assertOk()->json('data.id');
        $this->internal('POST', 'invitations/accept', $this->acceptance($credential))->assertUnprocessable();
        Mail::shouldReceive('send')->times(4)->andThrow(new \RuntimeException('Synthetic SMTP failure'));
        foreach ([0, 1, 5, 15] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->artisan('srd:invitations')->assertExitCode(0);
            $this->artisan('srd:invitations')->assertExitCode(0);
        }
        $this->travel(1)->hours();
        $this->artisan('srd:invitations')->assertExitCode(0);
        $row = DB::table('invitations')->where('id', $id)->first();
        $this->assertSame(4, $row->delivery_attempts);
        $this->assertNull($row->sent_at);
        $this->assertNotNull($row->secret_encrypted);
    }
}
