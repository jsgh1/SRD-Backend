<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase,SignedRequests;

    private string $org = '11111111-1111-4111-8111-111111111111';

    private string $terms = '22222222-2222-4222-8222-222222222222';

    private string $user = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert(['id' => $this->user, 'name' => 'Cuenta de prueba', 'email' => 'operador@example.test', 'password' => Hash::make('Clave-de-prueba-123'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => '44444444-4444-4444-8444-444444444444', 'user_id' => $this->user, 'organization_id' => $this->org, 'role' => 'registrar']);
        Http::fake(fn ($request) => Http::response(['data' => ['id' => str_ends_with($request->url(), '/code/otra')
            || str_ends_with($request->url(), '/organizations/55555555-5555-4555-8555-555555555555')
                ? '55555555-5555-4555-8555-555555555555' : $this->org, 'code' => 'prueba',
            'name' => 'Junta de prueba', 'terms' => ['id' => $this->terms, 'version' => 1,
                'body' => 'Términos ficticios para pruebas automatizadas.']]]));
    }

    private function login(): array
    {
        $r = $this->internal('POST', 'auth/login', ['email' => 'operador@example.test', 'password' => 'Clave-de-prueba-123', 'organization_code' => 'prueba', 'terms_version_id' => $this->terms, 'accepted' => true]);
        $r->assertOk();
        $r->assertJsonMissingPath('data.token');
        $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        preg_match('/>\s*(\d{6})\s*</', $message->getHtmlBody(), $matches);
        $this->assertNotEmpty($matches, 'Email includes a six-digit code');

        return [$r->json('data.challenge_id'), $matches[1]];
    }

    public function test_profile_language_is_persisted_per_user_and_rejects_unknown_codes(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $token = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $this->org, $this->terms)['token'];
        $principal = $sessions->resolve($token);
        $this->assertSame('es', $principal['user']['language']);

        $this->internal('PATCH', 'profile', [
            'name' => 'Cuenta de prueba', 'theme' => 'light', 'presence' => 'online', 'language' => 'en',
        ], $principal)->assertOk()->assertJsonPath('data.language', 'en');
        $this->assertSame('en', $sessions->resolve($token)['user']['language']);
        $this->assertDatabaseHas('users', ['id' => $this->user, 'language' => 'en']);

        $this->internal('PATCH', 'profile', [
            'name' => 'Cuenta de prueba', 'theme' => 'light', 'presence' => 'online', 'language' => 'fr',
        ], $principal)->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $this->user, 'language' => 'en']);
    }

    public function test_security_code_and_recovery_email_use_the_saved_english_language(): void
    {
        DB::table('users')->where('id', $this->user)->update([
            'email' => 'language-mail@example.test', 'language' => 'en',
        ]);
        $this->internal('POST', 'auth/login', [
            'email' => 'language-mail@example.test', 'password' => 'Clave-de-prueba-123',
            'organization_code' => 'prueba', 'terms_version_id' => $this->terms, 'accepted' => true,
        ])->assertOk();
        $codeMail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Your SRD security code', $codeMail->getSubject());
        $this->assertStringContainsString('<html lang="en">', $codeMail->getHtmlBody());
        $this->assertStringContainsString('Enter this code in SRD.', $codeMail->getHtmlBody());
        $this->assertStringNotContainsString('Escribe este código', $codeMail->getHtmlBody());
        $this->assertMatchesRegularExpression('/>\s*\d{6}\s*</', $codeMail->getHtmlBody());

        $this->internal('POST', 'auth/recover', [
            'email' => 'language-mail@example.test', 'organization_code' => 'prueba',
        ])->assertOk();
        $resetMail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Reset your SRD access', $resetMail->getSubject());
        $this->assertStringContainsString('<html lang="en">', $resetMail->getHtmlBody());
        $this->assertStringContainsString('This link works once and expires in 15 minutes.', $resetMail->getHtmlBody());
        $this->assertStringContainsString('Create a new password', $resetMail->getHtmlBody());
        $this->assertMatchesRegularExpression('/\/reset#[a-f0-9-]+\.[a-f0-9]{64}/', $resetMail->getHtmlBody());

        app(\App\Application\ChallengeService::class)->issue(
            DB::table('users')->where('id', $this->user)->first(), $this->org,
            'email_change', 'new-language-mail@example.test',
        );
        $changeMail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Your SRD security code', $changeMail->getSubject());
        $this->assertStringContainsString('Enter this code in SRD to confirm your new sign-in address.', $changeMail->getHtmlBody());
        $this->assertSame('new-language-mail@example.test', $changeMail->getTo()[0]->getAddress());
    }

    public function test_security_email_keeps_spanish_for_the_default_language(): void
    {
        $this->login();
        $mail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Tu código de seguridad de SRD', $mail->getSubject());
        $this->assertStringContainsString('<html lang="es">', $mail->getHtmlBody());
        $this->assertStringContainsString('Escribe este código en SRD.', $mail->getHtmlBody());
    }

    public function test_email_change_notice_uses_the_saved_english_language(): void
    {
        DB::table('users')->where('id', $this->user)->update(['language' => 'en']);
        DB::table('security_notices')->insert([
            'id' => '77777777-7777-4777-8777-777777777777',
            'organization_id' => $this->org, 'user_id' => $this->user,
            'destination_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('previous@example.test'),
            'kind' => 'email_changed', 'next_attempt_at' => now(), 'created_at' => now(),
        ]);
        $this->artisan('srd:security-notices')->assertExitCode(0);
        $mail = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('SRD email change notice', $mail->getSubject());
        $this->assertSame('previous@example.test', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('<html lang="en">', $mail->getHtmlBody());
        $this->assertStringContainsString('Your sign-in email changed', $mail->getHtmlBody());
        $this->assertStringNotContainsString('Tu correo de acceso cambió', $mail->getHtmlBody());
        $this->assertNotNull(DB::table('security_notices')->value('sent_at'));
    }

    public function test_profile_title_requires_both_languages_and_stays_with_its_council(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $token = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $this->org, $this->terms)['token'];
        $principal = $sessions->resolve($token);
        $base = ['name' => 'Cuenta de prueba', 'theme' => 'light', 'presence' => 'online', 'language' => 'en'];
        $this->internal('PATCH', 'profile', $base + ['title' => 'Coordinadora'], $principal)->assertUnprocessable();
        $this->internal('PATCH', 'profile', $base + ['title' => 'Coordinadora', 'title_en' => 'Coordinator'], $principal)
            ->assertOk()->assertJsonPath('data.title_en', 'Coordinator');
        $this->assertSame('Coordinator', $sessions->resolve($token)['user']['title_en']);

        $other = '55555555-5555-4555-8555-555555555555';
        DB::table('memberships')->insert(['id' => '66666666-6666-4666-8666-666666666666',
            'user_id' => $this->user, 'organization_id' => $other, 'role' => 'viewer']);
        $otherToken = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $other, $this->terms)['token'];
        $this->assertNull($sessions->resolve($otherToken)['user']['title']);
        $this->internal('PATCH', 'profile', $base + ['title' => '', 'title_en' => ''], $principal)->assertOk();
        $this->assertNull($sessions->resolve($token)['user']['title']);
    }

    public function test_profile_bio_requires_both_languages_and_is_scoped_to_its_council(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $token = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $this->org, $this->terms)['token'];
        $principal = $sessions->resolve($token);
        $base = ['name' => 'Cuenta de prueba', 'theme' => 'light', 'presence' => 'online', 'language' => 'es'];
        $this->internal('PATCH', 'profile', $base + ['bio' => 'Apoyo comunitario'], $principal)->assertUnprocessable();
        $this->internal('PATCH', 'profile', $base + ['bio' => 'Apoyo comunitario', 'bio_en' => 'Community support'], $principal)->assertOk();
        $this->assertSame('Community support', $sessions->resolve($token)['user']['bio_en']);
        $other = '55555555-5555-4555-8555-555555555555';
        DB::table('memberships')->insert(['id' => '77777777-7777-4777-8777-777777777777',
            'user_id' => $this->user, 'organization_id' => $other, 'role' => 'viewer']);
        $otherToken = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $other, $this->terms)['token'];
        $this->assertNull($sessions->resolve($otherToken)['user']['bio']);
        $this->internal('PATCH', 'profile', $base + ['bio' => '', 'bio_en' => ''], $principal)->assertOk();
        $this->assertNull($sessions->resolve($token)['user']['bio']);
    }

    public function test_passive_principal_checks_do_not_extend_idle_session(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $token = $sessions->create(DB::table('users')->where('id', $this->user)->first(), $this->org, $this->terms)['token'];
        $activity = DB::table('auth_sessions')->value('last_activity_at');
        $this->travel(20)->minutes();
        $this->internal('POST', 'auth/me', ['token' => $token, 'touch_activity' => false])->assertOk();
        $this->assertSame($activity, DB::table('auth_sessions')->value('last_activity_at'));
        $this->travel(10)->minutes();
        $this->internal('POST', 'auth/me', ['token' => $token, 'touch_activity' => false])->assertUnauthorized();
        $this->assertSame($activity, DB::table('auth_sessions')->value('last_activity_at'));
    }

    public function test_expired_session_socket_closure_retries_and_leaves_active_sessions_alone(): void
    {
        $user = DB::table('users')->where('id', $this->user)->first();
        $sessions = app(\App\Application\SessionService::class);
        $expired = $sessions->create($user, $this->org, $this->terms)['token'];
        $idle = $sessions->create($user, $this->org, $this->terms)['token'];
        $active = $sessions->create($user, $this->org, $this->terms)['token'];
        $expiredId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $expired))->value('id');
        $idleId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $idle))->value('id');
        $activeId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $active))->value('id');
        DB::table('auth_sessions')->where('id', $expiredId)->update(['expires_at' => now()->subSecond()]);
        DB::table('auth_sessions')->where('id', $idleId)->update(['last_activity_at' => now()->subMinutes(31)]);
        $available = false;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function () use (&$available) {
            return Http::response(['data' => []], $available ? 200 : 503);
        });

        $closer = app(\App\Application\ExpiredSessionCloser::class);
        $this->assertSame(0, $closer->run());
        $this->assertNull(DB::table('auth_sessions')->where('id', $expiredId)->value('revoked_at'));
        $available = true;
        $this->assertSame(2, $closer->run());
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $expiredId)->value('revoked_at'));
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $idleId)->value('revoked_at'));
        $this->assertNull(DB::table('auth_sessions')->where('id', $activeId)->value('revoked_at'));
        $this->assertSame(0, $closer->run());
        $sentIds = collect(Http::recorded())->map(fn ($pair) => $pair[0]['session_id'])->all();
        $this->assertContains($expiredId, $sentIds);
        $this->assertContains($idleId, $sentIds);
        $this->assertNotContains($activeId, $sentIds);
    }

    public function test_presence_expires_without_renewing_idle_time_and_masks_invisible(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $presence = app(\App\Application\PresenceService::class);
        $user = DB::table('users')->where('id', $this->user)->first();
        $token = $sessions->create($user, $this->org, $this->terms)['token'];
        $activity = DB::table('auth_sessions')->value('last_activity_at');
        $this->assertSame('offline', $presence->effective($this->user));
        $this->internal('POST', 'auth/presence', ['token' => $token])->assertOk()->assertJsonPath('data.effective', 'online');
        $this->travel(80)->seconds();
        $this->assertSame('online', $presence->effective($this->user));
        $this->travel(10)->seconds();
        $this->assertSame('offline', $presence->effective($this->user));
        foreach (['away', 'dnd', 'invisible'] as $preference) {
            DB::table('users')->where('id', $this->user)->update(['presence' => $preference]);
            $this->internal('POST', 'auth/presence', ['token' => $token])->assertOk()->assertJsonPath('data.effective', $preference === 'invisible' ? 'offline' : $preference);
        }
        $this->assertSame($activity, DB::table('auth_sessions')->value('last_activity_at'));
        $this->travel(29)->minutes();
        $this->internal('POST', 'auth/presence', ['token' => $token])->assertUnauthorized();
        $this->assertSame($activity, DB::table('auth_sessions')->value('last_activity_at'));
    }

    public function test_presence_combines_sessions_and_revocation_removes_connection(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $presence = app(\App\Application\PresenceService::class);
        $user = DB::table('users')->where('id', $this->user)->first();
        $first = $sessions->create($user, $this->org, $this->terms)['token'];
        $second = $sessions->create($user, $this->org, $this->terms)['token'];
        $this->internal('POST', 'auth/presence', ['token' => $first])->assertOk();
        $this->travel(60)->seconds();
        $this->internal('POST', 'auth/presence', ['token' => $second])->assertOk();
        $this->travel(31)->seconds();
        $this->assertSame('online', $presence->effective($this->user));
        $this->internal('POST', 'auth/logout', ['token' => $second])->assertOk();
        $this->assertSame('offline', $presence->effective($this->user));
        $this->internal('POST', 'auth/presence', ['token' => $second])->assertUnauthorized();
        $this->internal('POST', 'auth/presence', ['token' => $first])->assertOk();
        DB::table('users')->where('id', $this->user)->update(['active' => false]);
        $this->assertSame('offline', $presence->effective($this->user));
        $this->internal('POST', 'auth/presence', ['token' => $first])->assertUnauthorized();
    }

    public function test_revoke_others_returns_only_sessions_revoked_by_this_call(): void
    {
        $sessions = app(\App\Application\SessionService::class);
        $user = DB::table('users')->where('id', $this->user)->first();
        $current = $sessions->create($user, $this->org, $this->terms)['token'];
        $other = $sessions->create($user, $this->org, $this->terms)['token'];
        $alreadyRevoked = $sessions->create($user, $this->org, $this->terms)['token'];
        $currentId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $current))->value('id');
        $otherId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $other))->value('id');
        $oldId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $alreadyRevoked))->value('id');
        DB::table('auth_sessions')->where('id', $oldId)->update(['revoked_at' => now()]);

        $this->internal('POST', 'auth/revokeOthers', ['token' => $current])->assertOk()
            ->assertJsonPath('data.revoked_session_ids', [$otherId]);
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $otherId, 'confirmed_at' => null]);
        $this->assertDatabaseMissing('chat_socket_closures', ['session_id' => $currentId]);
        $this->assertNull(DB::table('auth_sessions')->where('id', $currentId)->value('revoked_at'));
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->internal('POST', 'auth/me', ['token' => $other])->assertUnauthorized();
        $this->internal('POST', 'auth/revokeOthers', ['token' => $current])->assertOk()
            ->assertJsonPath('data.revoked_session_ids', []);
    }

    public function test_revoked_socket_closure_queue_retries_until_gateway_confirms(): void
    {
        $user = DB::table('users')->where('id', $this->user)->first();
        $sessions = app(\App\Application\SessionService::class);
        $current = $sessions->create($user, $this->org, $this->terms)['token'];
        $other = $sessions->create($user, $this->org, $this->terms)['token'];
        $otherId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $other))->value('id');
        $this->internal('POST', 'auth/revokeOthers', ['token' => $current])->assertOk();

        $available = false;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function () use (&$available) {
            return Http::response(['data' => []], $available ? 200 : 503);
        });
        $queue = app(\App\Application\ChatSocketClosureQueue::class);
        $this->assertSame(0, $queue->deliver());
        $this->assertNull(DB::table('chat_socket_closures')->where('session_id', $otherId)->value('confirmed_at'));
        $available = true;
        $this->assertSame(1, $queue->deliver());
        $this->assertNotNull(DB::table('chat_socket_closures')->where('session_id', $otherId)->value('confirmed_at'));
        $this->assertSame(0, $queue->deliver());
        Http::assertSent(fn ($request) => $request['session_id'] === $otherId);
    }

    public function test_login_requires_terms_and_internal_signature(): void
    {
        $this->postJson('/internal/v1/auth/login', [])->assertUnauthorized();
        $this->internal('POST', 'auth/login', ['email' => 'operador@example.test', 'password' => 'Clave-de-prueba-123', 'organization_code' => 'prueba', 'terms_version_id' => $this->terms, 'accepted' => false])->assertUnprocessable();
        $this->assertDatabaseCount('auth_sessions', 0);
    }

    public function test_code_creates_one_session_and_cannot_be_reused(): void
    {
        [$id,$code] = $this->login();
        $this->assertDatabaseCount('auth_sessions', 0);
        $stored = DB::table('auth_challenges')->first();
        $this->assertNotEquals($code, $stored->digest);
        $response = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertOk();
        $token = $response->json('data.token');
        $this->assertDatabaseCount('auth_sessions', 1);
        $this->assertNotEquals($token, DB::table('auth_sessions')->first()->token_hash);
        $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
        $this->internal('POST', 'auth/me', ['token' => $token])->assertOk()->assertJsonPath('data.organization_id', $this->org)->assertJsonPath('data.role', 'registrar');
        $this->internal('POST', 'auth/logout', ['token' => $token])->assertOk();
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => DB::table('auth_sessions')->value('id'),
            'confirmed_at' => null]);
        $this->internal('POST', 'auth/me', ['token' => $token])->assertUnauthorized();
    }

    public function test_five_wrong_attempts_lock_the_challenge_and_are_durable(): void
    {
        [$id,$code] = $this->login();
        $bad = $code === '000000' ? '999999' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $bad])->assertUnprocessable();
        }
        $this->assertSame(5, DB::table('auth_challenges')->where('id', $id)->value('attempts'));
        $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseCount('auth_sessions', 0);
    }

    public function test_expired_code_is_rejected(): void
    {
        [$id,$code] = $this->login();
        $this->travel(6)->minutes();
        $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
    }

    public function test_resend_wait_and_old_code_invalidation(): void
    {
        [$id,$code] = $this->login();
        $this->internal('POST', 'auth/resend', ['challenge_id' => $id])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $r = $this->internal('POST', 'auth/resend', ['challenge_id' => $id])->assertOk();
        $this->assertNotEquals($id, $r->json('data.challenge_id'));
        $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertUnprocessable();
    }

    public function test_sixth_hourly_send_is_rejected(): void
    {
        [$id] = $this->login();
        for ($i = 1; $i < 5; $i++) {
            $this->travel(61)->seconds();
            $id = $this->internal('POST', 'auth/resend', ['challenge_id' => $id])->assertOk()->json('data.challenge_id');
        }
        $this->travel(61)->seconds();
        $this->internal('POST', 'auth/resend', ['challenge_id' => $id])->assertTooManyRequests();
    }

    public function test_membership_revocation_and_idle_session_fail_closed(): void
    {
        [$id,$code] = $this->login();
        $token = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->json('data.token');
        DB::table('memberships')->update(['active' => false]);
        $this->internal('POST', 'auth/me', ['token' => $token])->assertUnauthorized();
        DB::table('memberships')->update(['active' => true]);
        $this->travel(31)->minutes();
        $this->internal('POST', 'auth/me', ['token' => $token])->assertUnauthorized();
    }

    public function test_cannot_switch_to_unrelated_organization(): void
    {
        [$id,$code] = $this->login();
        $token = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->json('data.token');
        $sessionId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->value('id');
        $this->internal('POST', 'auth/switchOrganization', ['token' => $token, 'organization_code' => 'otra', 'accepted' => true, 'terms_version_id' => $this->terms])->assertForbidden();
        $this->assertNull(DB::table('auth_sessions')->where('id', $sessionId)->value('revoked_at'));
        $this->assertDatabaseMissing('chat_socket_closures', ['session_id' => $sessionId]);
        $this->internal('POST', 'auth/me', ['token' => $token])->assertOk();
    }

    public function test_switch_rotates_session_and_queues_only_old_socket_without_extending_absolute_expiry(): void
    {
        [$id, $code] = $this->login();
        $token = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->json('data.token');
        $old = DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->first();
        $destination = '55555555-5555-4555-8555-555555555555';
        DB::table('memberships')->insert(['id' => '66666666-6666-4666-8666-666666666666',
            'user_id' => $this->user, 'organization_id' => $destination, 'role' => 'viewer']);

        $newToken = $this->internal('POST', 'auth/switchOrganization', ['token' => $token,
            'organization_code' => 'otra', 'accepted' => true, 'terms_version_id' => $this->terms])
            ->assertOk()->json('data.token');
        $this->assertNotSame($token, $newToken);
        $new = DB::table('auth_sessions')->where('token_hash', hash('sha256', $newToken))->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($destination, $new->organization_id);
        $this->assertSame($old->expires_at, $new->expires_at);
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $old->id)->value('revoked_at'));
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $old->id, 'confirmed_at' => null]);
        $this->assertDatabaseMissing('chat_socket_closures', ['session_id' => $new->id]);
        $this->internal('POST', 'auth/me', ['token' => $token])->assertUnauthorized();
        $this->internal('POST', 'auth/me', ['token' => $newToken])->assertOk()
            ->assertJsonPath('data.organization_id', $destination);
    }

    public function test_recovery_is_generic_and_reset_revokes_sessions(): void
    {
        [$id,$code] = $this->login();
        $token = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->json('data.token');
        $sessionId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->value('id');
        $a = $this->internal('POST', 'auth/recover', ['email' => 'operador@example.test', 'organization_code' => 'prueba'])->assertOk()->json('data');
        $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        preg_match('/\/reset#([a-f0-9-]+)\.([a-f0-9]{64})/', $message, $m);
        $this->assertCount(3, $m);
        $b = $this->internal('POST', 'auth/recover', ['email' => 'inexistente@example.test', 'organization_code' => 'prueba'])->assertOk()->json('data');
        $this->assertEquals($a, $b);
        $this->internal('POST', 'auth/verify', ['challenge_id' => $m[1], 'code' => '123456'])->assertUnprocessable();
        $payload = ['challenge_id' => $m[1], 'secret' => $m[2], 'password' => 'Nueva-clave-segura-123', 'password_confirmation' => 'Nueva-clave-segura-123'];
        $this->internal('POST', 'auth/reset', $payload)->assertOk()
            ->assertJsonPath('data.revoked_session_ids', [$sessionId]);
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $sessionId, 'confirmed_at' => null]);
        $this->internal('POST', 'auth/reset', $payload)->assertUnprocessable();
        $this->internal('POST', 'auth/me', ['token' => $token])->assertUnauthorized();
        $this->assertTrue(Hash::check($payload['password'], DB::table('users')->value('password')));
    }

    public function test_smtp_failure_never_reports_sent_or_creates_session(): void
    {
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->internal('POST', 'auth/login', ['email' => 'operador@example.test', 'password' => 'Clave-de-prueba-123', 'organization_code' => 'prueba', 'terms_version_id' => $this->terms, 'accepted' => true])->assertStatus(503);
        $this->assertDatabaseCount('auth_sessions', 0);
        $this->assertNotNull(DB::table('auth_challenges')->value('consumed_at'));
        $this->assertDatabaseHas('outbox_events', ['action' => 'auth.delivery_failed']);
    }

    public function test_email_change_requires_password_and_code_then_revokes_other_sessions_and_notifies_old_address(): void
    {
        [$id, $code] = $this->login();
        $token = $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->json('data.token');
        $principal = ['user_id' => $this->user, 'organization_id' => $this->org, 'role' => 'registrar', 'session_id' => DB::table('auth_sessions')->value('id')];
        $this->travel(61)->seconds();
        [$otherId, $otherCode] = $this->login();
        $otherToken = $this->internal('POST', 'auth/verify', ['challenge_id' => $otherId, 'code' => $otherCode])->json('data.token');
        $otherSessionId = DB::table('auth_sessions')->where('token_hash', hash('sha256', $otherToken))->value('id');
        $body = ['new_email' => 'nuevo@example.test', 'password' => 'incorrecta'];
        $this->internal('POST', 'profile/email-change', $body, $principal)->assertUnauthorized();
        $body['password'] = 'Clave-de-prueba-123';
        $changeId = $this->internal('POST', 'profile/email-change', $body, $principal)->assertOk()->json('data.challenge_id');
        preg_match('/>\s*(\d{6})\s*</', Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody(), $m);
        $this->assertDatabaseHas('users', ['id' => $this->user, 'email' => 'operador@example.test']);
        $this->internal('POST', 'auth/verify', ['challenge_id' => $changeId, 'code' => $m[1]])->assertUnprocessable();
        $this->internal('POST', 'profile/email-change/confirm', ['challenge_id' => $changeId, 'code' => $m[1] === '000000' ? '999999' : '000000'], $principal)->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $this->user, 'email' => 'operador@example.test']);
        $this->internal('POST', 'profile/email-change/confirm', ['challenge_id' => $changeId, 'code' => $m[1]], $principal)
            ->assertOk()->assertJsonPath('data.revoked_session_ids', [$otherSessionId]);
        $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $otherSessionId, 'confirmed_at' => null]);
        $this->assertDatabaseMissing('chat_socket_closures', ['session_id' => $principal['session_id']]);
        $this->assertDatabaseHas('users', ['id' => $this->user, 'email' => 'nuevo@example.test']);
        $this->internal('POST', 'auth/me', ['token' => $token])->assertOk();
        $this->internal('POST', 'auth/me', ['token' => $otherToken])->assertUnauthorized();
        $this->internal('POST', 'profile/email-change/confirm', ['challenge_id' => $changeId, 'code' => $m[1]], $principal)->assertUnprocessable();
        $notice = DB::table('security_notices')->first();
        $this->assertNotEquals('operador@example.test', $notice->destination_encrypted);
        $this->artisan('srd:security-notices')->assertExitCode(0);
        $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('operador@example.test', $message->getTo()[0]->getAddress());
        $this->assertNotNull(DB::table('security_notices')->value('sent_at'));
        $this->assertNull(DB::table('security_notices')->value('destination_encrypted'));
    }

    public function test_security_notice_failures_obey_retry_limit_and_do_not_report_delivery(): void
    {
        DB::table('security_notices')->insert(['id' => $this->terms, 'organization_id' => $this->org, 'user_id' => $this->user, 'destination_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('anterior@example.test'), 'kind' => 'email_changed', 'next_attempt_at' => now(), 'created_at' => now()]);
        Mail::shouldReceive('send')->times(4)->andThrow(new \RuntimeException('Synthetic unavailable SMTP'));
        foreach ([0, 1, 5, 15] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->artisan('srd:security-notices')->assertExitCode(0);
            $this->artisan('srd:security-notices')->assertExitCode(0);
        }
        $this->travel(1)->hours();
        $this->artisan('srd:security-notices')->assertExitCode(0);
        $this->assertSame(4, DB::table('security_notices')->value('attempts'));
        $this->assertNull(DB::table('security_notices')->value('sent_at'));
        $this->assertNotNull(DB::table('security_notices')->value('destination_encrypted'));
        $this->assertDatabaseMissing('outbox_events', ['action' => 'profile.email_notice_sent']);
    }

    public function test_email_resend_requires_recent_reauthentication_and_rejects_existing_email(): void
    {
        [$id, $code] = $this->login();
        $this->internal('POST', 'auth/verify', ['challenge_id' => $id, 'code' => $code])->assertOk();
        $principal = ['user_id' => $this->user, 'organization_id' => $this->org, 'role' => 'registrar', 'session_id' => DB::table('auth_sessions')->value('id')];
        $this->internal('POST', 'profile/email-change', ['new_email' => 'operador@example.test', 'password' => 'Clave-de-prueba-123'], $principal)->assertUnprocessable();
        $changeId = $this->internal('POST', 'profile/email-change', ['new_email' => 'nuevo@example.test', 'password' => 'Clave-de-prueba-123'], $principal)->assertOk()->json('data.challenge_id');
        $this->internal('POST', 'profile/email-change/resend', ['challenge_id' => $changeId], $principal)->assertTooManyRequests();
        $this->travel(61)->seconds();
        $newId = $this->internal('POST', 'profile/email-change/resend', ['challenge_id' => $changeId], $principal)->assertOk()->json('data.challenge_id');
        $this->assertNotSame($changeId, $newId);
        $this->travel(5)->minutes();
        $this->internal('POST', 'profile/email-change/resend', ['challenge_id' => $newId], $principal)->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $this->user, 'email' => 'operador@example.test']);
    }
}
