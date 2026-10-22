<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class GatewayTest extends TestCase
{
    use SignedRequests;

    public function test_public_council_config_includes_its_logo_without_requiring_login(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $logo = 'data:image/png;base64,abc=';
        Http::fake(function ($request) use ($id, $logo) {
            if (str_ends_with($request->url(), '/organizations/code/junta-a'))
                return Http::response(['data' => ['id' => $id, 'code' => 'junta-a', 'name' => 'Junta A']]);
            if (str_ends_with($request->url(), '/public-logo/'.$id))
                return Http::response(['data' => ['logo_data' => $logo]]);
            return Http::response([], 404);
        });
        $this->getJson('/api/v1/organizations/junta-a')->assertOk()
            ->assertJsonPath('data.logo_data', $logo)->assertJsonPath('data.name', 'Junta A');
        Http::assertSentCount(2);
        $this->getJson('/api/v1/organizations/INVALID')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_login_configuration_remains_available_when_records_is_unavailable(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/organizations/code/junta-a')
            ? Http::response(['data' => ['id' => '11111111-1111-4111-8111-111111111111', 'code' => 'junta-a']])
            : Http::response([], 503));
        $this->getJson('/api/v1/organizations/junta-a')->assertOk()
            ->assertJsonPath('data.logo_data', null)->assertJsonPath('data.code', 'junta-a');
    }

    public function test_global_limit_separates_sessions_on_one_ip_and_keeps_anonymous_limit(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->withSession(['credential' => 'first-session'])->getJson('/api/v1/csrf')->assertOk();
        }
        $this->withSession(['credential' => 'first-session'])->getJson('/api/v1/csrf')->assertStatus(429);
        $this->withSession(['credential' => 'second-session'])->getJson('/api/v1/csrf')->assertOk();
        for ($i = 0; $i < 120; $i++) {
            $this->withSession(['credential' => null])->getJson('/api/v1/csrf')->assertOk();
        }
        $this->withSession(['credential' => null])->getJson('/api/v1/csrf')->assertStatus(429);
    }

    public function test_export_limit_separates_authenticated_sessions_on_one_ip(): void
    {
        $limiter = RateLimiter::limiter('srd-export');
        $request = \Illuminate\Http\Request::create('/api/v1/persons/export', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setLaravelSession($this->app['session']->driver());

        $request->session()->put('credential', 'first-session');
        $first = $limiter($request);
        $request->session()->put('credential', 'second-session');
        $second = $limiter($request);
        $request->session()->forget('credential');
        $guest = $limiter($request);

        $this->assertSame(5, $first->maxAttempts);
        $this->assertSame(60, $first->decaySeconds);
        $this->assertNotSame($first->key, $second->key);
        $this->assertNotSame($first->key, $guest->key);
        $this->assertSame('guest:127.0.0.1', $guest->key);
    }

    public function test_service_status_requires_administrator_and_reports_only_fixed_health_booleans(): void
    {
        $role = 'viewer';
        Http::fake(function ($request) use (&$role) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => $role,
                'terms_required' => false,
            ]]);
            return Http::response([], str_contains($request->url(), 'files:8000/up') ? 503 : 200);
        });
        $this->getJson('/api/v1/system/services')->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/services')->assertForbidden();
        Http::assertSentCount(1);
        $role = 'admin';
        $response = $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/services')->assertOk()
            ->assertJsonPath('data.items.0.service', 'identity')
            ->assertJsonPath('data.items.3.service', 'files')
            ->assertJsonPath('data.items.3.available', false);
        $this->assertCount(10, $response->json('data.items'));
        $response->assertJsonPath('data.antivirus_available', false);
        $this->assertArrayHasKey('checked_at', $response->json('data'));
        $this->assertStringNotContainsString('http://', $response->getContent());
        Http::assertSentCount(12);
        $this->assertSame(10, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/up'))->count());
    }
    public function test_service_status_reports_antivirus_readiness_separately_from_files_http(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/antivirus-status'))
                return Http::response(['data' => ['available' => false]]);
            return Http::response([], 200);
        });
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/services')->assertOk()
            ->assertJsonPath('data.items.3.available', true)
            ->assertJsonPath('data.antivirus_available', false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/antivirus-status')
            && $request->hasHeader('X-SRD-Context'));
    }
    public function test_service_status_keeps_other_results_when_one_probe_cannot_connect(): void
    {
        $failedProbes = 0;
        Http::fake(function ($request) use (&$failedProbes) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'superadmin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), 'chat:8000/up')) {
                $failedProbes++;
                return (Http::failedConnection('Synthetic unavailable service'))($request);
            }
            if (str_ends_with($request->url(), '/antivirus-status'))
                return Http::response(['data' => ['available' => true]]);

            return Http::response([], 200);
        });
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/services')->assertOk()
            ->assertJsonPath('data.items.0.available', true)
            ->assertJsonPath('data.items.9.available', false)
            ->assertJsonPath('data.antivirus_available', true);
        $this->assertSame(1, $failedProbes);
        $this->assertSame(9, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/up'))->count());
    }

    public function test_scheduler_status_is_admin_only_parallel_and_keeps_degraded_results(): void
    {
        $role = 'viewer';
        Http::fake(function ($request) use (&$role) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => $role,
                'terms_required' => false,
            ]]);
            if (str_contains($request->url(), 'chat:8000/internal/v1/scheduler-status')) return Http::response([], 503);
            if (str_ends_with($request->url(), '/scheduler-status')) return Http::response(['data' => [
                'cycle_recent' => true,
                'outbox_recent' => ! str_contains($request->url(), 'files:8000/'),
            ]]);

            return Http::response([], 404);
        });
        $this->getJson('/api/v1/system/schedulers')->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/schedulers')->assertForbidden();
        $role = 'admin';
        $response = $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/schedulers')->assertOk()
            ->assertJsonPath('data.items.0.service', 'identity')
            ->assertJsonPath('data.items.0.available', true)
            ->assertJsonPath('data.items.3.cycle_recent', true)
            ->assertJsonPath('data.items.3.outbox_recent', false)
            ->assertJsonPath('data.items.3.available', false)
            ->assertJsonPath('data.items.8.available', false);
        $this->assertCount(9, $response->json('data.items'));
        $this->assertStringNotContainsString('http://', $response->getContent());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/me')
            && $request['touch_activity'] === false);
        $this->assertSame(9, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/scheduler-status'))->count());
        Http::assertSent(function ($request) {
            if ($request->url() !== config('srd.urls.identity').'/internal/v1/scheduler-status') return false;
            [$encoded, $signature] = explode('.', $request->header('X-SRD-Context')[0] ?? '', 2);
            $claims = json_decode(base64_decode($encoded, true), true);

            return hash_equals(hash_hmac('sha256', $encoded, config('srd.internal_key')), $signature)
                && ($claims['iss'] ?? null) === 'gateway' && ($claims['aud'] ?? null) === 'identity'
                && ($claims['path'] ?? null) === '/internal/v1/scheduler-status'
                && ($claims['hash'] ?? null) === hash('sha256', $request->body());
        });
    }

    public function test_audit_delivery_overview_is_passive_admin_only_and_keeps_partial_results(): void
    {
        $role = 'viewer';
        Http::fake(function ($request) use (&$role) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => $role,
                'terms_required' => false,
            ]]);
            if (str_contains($request->url(), 'chat:8000/internal/v1/outbox-status')) return Http::response([], 503);
            if (str_ends_with($request->url(), '/outbox-status')) return Http::response(['data' => [
                'exhausted' => str_contains($request->url(), 'files:8000/') ? 2 : 0,
            ]]);
            return Http::response([], 404);
        });
        $this->getJson('/api/v1/system/audit-deliveries')->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/audit-deliveries')->assertForbidden();
        $role = 'admin';
        $response = $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/audit-deliveries?organization_id=forged')
            ->assertOk()->assertJsonPath('data.items.3.service', 'files')
            ->assertJsonPath('data.items.3.exhausted', 2)
            ->assertJsonPath('data.items.8.available', false)
            ->assertJsonPath('data.items.8.exhausted', null);
        $this->assertCount(9, $response->json('data.items'));
        $this->assertStringNotContainsString('http://', $response->getContent());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/me')
            && $request['touch_activity'] === false);
        $this->assertSame(9, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/outbox-status'))->count());
        Http::assertSent(function ($request) {
            if ($request->url() !== config('srd.urls.files').'/internal/v1/outbox-status') return false;
            [$encoded, $signature] = explode('.', $request->header('X-SRD-Context')[0] ?? '', 2);
            $claims = json_decode(base64_decode($encoded, true), true);
            return hash_equals(hash_hmac('sha256', $encoded, config('srd.internal_key')), $signature)
                && ($claims['iss'] ?? null) === 'gateway' && ($claims['aud'] ?? null) === 'files'
                && ($claims['path'] ?? null) === '/internal/v1/outbox-status'
                && ($claims['context']['organization_id'] ?? null) === 'trusted-org'
                && ($claims['context']['user_id'] ?? null) === 'trusted-user'
                && ($claims['context']['role'] ?? null) === 'admin'
                && ($claims['hash'] ?? null) === hash('sha256', $request->body());
        });
    }
    public function test_calendar_delivery_status_uses_verified_junta_context(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/delivery-status')) return Http::response(['data' => [
                'delivered' => 1, 'pending' => 0, 'due' => 0, 'deferred' => 0,
                'exhausted' => 0, 'exhausted_jobs' => [],
            ]]);
            return Http::response([], 404);
        });
        $this->getJson('/api/v1/system/calendar-deliveries')->assertUnauthorized();
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/calendar-deliveries?organization_id=forged')
            ->assertOk()->assertJsonPath('data.delivered', 1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/me')
            && $request['touch_activity'] === false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/delivery-status')
            && $request->hasHeader('X-SRD-Context') && ! str_contains($request->url(), 'forged'));
    }
    public function test_mail_delivery_status_uses_verified_junta_context(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/mail-delivery-status')) return Http::response(['data' => [
                'invitations' => ['pending' => 0, 'due' => 0, 'deferred' => 0, 'exhausted' => 0, 'expired' => 0],
                'security_notices' => ['pending' => 0, 'due' => 0, 'deferred' => 0, 'exhausted' => 0],
            ]]);
            return Http::response([], 404);
        });
        $this->getJson('/api/v1/system/mail-deliveries')->assertUnauthorized();
        $this->withSession(['credential' => 'opaque'])->getJson('/api/v1/system/mail-deliveries?organization_id=forged')
            ->assertOk()->assertJsonPath('data.invitations.pending', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/me')
            && $request['touch_activity'] === false);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/mail-delivery-status')
            && $request->hasHeader('X-SRD-Context') && ! str_contains($request->url(), 'forged'));
    }
    public function test_mail_delivery_retry_uses_verified_junta_and_fixed_type(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        Http::fake(function ($request) use ($id) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/mail-delivery-status/invitations/'.$id.'/retry'))
                return Http::response(['data' => ['queued' => true]]);
            return Http::response([], 404);
        });
        $this->postJson('/api/v1/system/mail-deliveries/invitations/'.$id.'/retry')->assertUnauthorized();
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/system/mail-deliveries/invitations/'.$id.'/retry', [
            'organization_id' => 'forged', 'role' => 'superadmin',
        ])->assertOk()->assertJsonPath('data.queued', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/mail-delivery-status/invitations/'.$id.'/retry')
            && $request->hasHeader('X-SRD-Context')
            && ! isset($request['organization_id']) && ! isset($request['role']));
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/system/mail-deliveries/other/'.$id.'/retry')->assertNotFound();
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/system/mail-deliveries/invitations/not-a-uuid/retry')->assertNotFound();
    }
    public function test_calendar_delivery_retry_uses_verified_junta_and_uuid_route(): void
    {
        $job = '11111111-1111-4111-8111-111111111111';
        Http::fake(function ($request) use ($job) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/delivery-status/'.$job.'/retry'))
                return Http::response(['data' => ['queued' => true]]);
            return Http::response([], 404);
        });
        $this->postJson('/api/v1/system/calendar-deliveries/'.$job.'/retry')->assertUnauthorized();
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/system/calendar-deliveries/'.$job.'/retry', [
            'organization_id' => 'forged', 'role' => 'superadmin',
        ])->assertOk()->assertJsonPath('data.queued', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/delivery-status/'.$job.'/retry')
            && $request->hasHeader('X-SRD-Context')
            && ! isset($request['organization_id']) && ! isset($request['role']));
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/system/calendar-deliveries/not-a-uuid/retry')->assertNotFound();
    }
    public function test_audit_retry_forwards_only_a_fixed_service_and_verified_junta(): void
    {
        $event = '11111111-1111-4111-8111-111111111111';
        $org = '22222222-2222-4222-8222-222222222222';
        $actor = '33333333-3333-4333-8333-333333333333';
        Http::fake(function ($request) use ($org, $actor, $event) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data' => [
                'user_id' => $actor, 'organization_id' => $org, 'role' => 'admin',
                'terms_required' => false,
            ]]);
            if (str_ends_with($request->url(), '/outbox-status/'.$event.'/retry'))
                return Http::response(['data' => ['queued' => true]]);

            return Http::response([], 404);
        });
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/audit-delivery/identity/'.$event.'/retry', [
            'organization_id' => 'forged', 'role' => 'superadmin',
        ])->assertOk()->assertJsonPath('data.queued', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/outbox-status/'.$event.'/retry')
            && $request->hasHeader('X-SRD-Context')
            && ! isset($request['organization_id']) && ! isset($request['role']));
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/audit-delivery/audit/'.$event.'/retry')->assertNotFound();
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/audit-delivery/identity/not-a-uuid/retry')->assertNotFound();
    }
    public function test_individual_folder_grant_uses_identity_verified_membership_version(): void
    {
        $membership='11111111-1111-4111-8111-111111111111';
        Http::fake(function ($request) use ($membership) {
            if (str_ends_with($request->url(), '/auth/me')) return Http::response(['data'=>[
                'user_id'=>'22222222-2222-4222-8222-222222222222',
                'organization_id'=>'33333333-3333-4333-8333-333333333333',
                'session_id'=>'44444444-4444-4444-8444-444444444444',
                'role'=>'admin','terms_required'=>false]]);
            if (str_ends_with($request->url(), '/members/folder-readers/verify'))
                return Http::response(['data'=>['items'=>[['id'=>$membership,'version'=>7]]]]);
            return Http::response(['data'=>['version'=>1,'reader_roles'=>[],
                'reader_memberships'=>[['id'=>$membership,'version'=>7]]]]);
        });
        $this->withSession(['credential'=>'opaque'])->putJson('/api/v1/folder-access',[
            'version'=>0,'reader_roles'=>[],'reader_memberships'=>[$membership],
            'membership_version'=>999,'organization_id'=>'forged'])->assertOk()
            ->assertJsonPath('data.reader_memberships.0.version',7);
        Http::assertSent(fn ($request) => str_ends_with($request->url(),'/folder-access')
            && $request['reader_memberships']===[['id'=>$membership,'version'=>7]]
            && !isset($request['organization_id']) && !isset($request['membership_version']));
    }
    public function test_filter_delegation_uses_verified_caller_and_records_authorization(): void
    {
        Http::fake(fn ($r) => str_ends_with($r->url(), '/auth/me')
            ? Http::response(['data'=>['user_id'=>'trusted-user','organization_id'=>'trusted-org','role'=>'viewer','terms_required'=>false]])
            : Http::response([],403));
        $this->getJson('/api/v1/person-filter-settings')->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential'=>'opaque'])->putJson('/api/v1/person-filter-settings',[
            'version'=>0,'base'=>[],'custom'=>[],'delegated_roles'=>['viewer'],'role'=>'admin','organization_id'=>'forged',
        ])->assertForbidden();
        Http::assertSent(function ($r) {
            if (!str_ends_with($r->url(),'/person-filter-settings')) return false;
            $claims=json_decode(base64_decode(explode('.',$r->header('X-SRD-Context')[0])[0]),true);
            return $claims['aud']==='records' && $claims['context']['role']==='viewer' && $claims['context']['organization_id']==='trusted-org'
                && !isset($r['role']) && !isset($r['organization_id']) && $r['delegated_roles']===['viewer'];
        });
    }
    public function test_photos_use_verified_session_and_preserve_file_quota_errors(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $path = '/api/v1/persons/'.$id.'/photos/person';
        Http::fake(fn ($r) => str_ends_with($r->url(), '/auth/me')
            ? Http::response(['data' => ['user_id' => 'trusted-user', 'organization_id' => 'trusted-org', 'session_id' => 'trusted-session', 'role' => 'admin', 'terms_required' => false]])
            : Http::response([], 507));
        $this->getJson($path)->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential' => 'opaque'])->putJson($path, ['name' => 'a.png', 'content' => 'YQ==', 'version' => 0, 'role' => 'superadmin', 'organization_id' => 'forged'])->assertStatus(507);
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), '/photos/person')) return false;
            $claims = json_decode(base64_decode(explode('.', $r->header('X-SRD-Context')[0])[0]), true);
            return $claims['aud'] === 'files' && $claims['context']['role'] === 'admin' && $claims['context']['organization_id'] === 'trusted-org'
                && !isset($r['role']) && !isset($r['organization_id']) && $r['version'] === 0 && $r['content'] === 'YQ==';
        });
        $this->getJson('/api/v1/persons/'.$id.'/photos/unknown')->assertNotFound();
    }
    public function test_heartbeat_only_uses_server_credential_and_never_calls_me(): void
    {
        Http::fake(fn () => Http::response(['data' => ['effective' => 'online']]));
        $this->postJson('/api/v1/presence/heartbeat')->assertUnauthorized();
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/presence/heartbeat', ['token' => 'forged', 'user_id' => 'forged'])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/presence') && $r->data() === ['token' => 'server-token']);
    }
    public function test_contacts_always_resolve_passively_despite_browser_input(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me')
            ? ['user_id' => 'trusted-user', 'organization_id' => 'trusted-organization', 'session_id' => 'trusted-session', 'role' => 'viewer', 'terms_required' => false]
            : ['items' => [], 'total' => 0]]));
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/contacts?touch_activity=1&token=forged')->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/me') && $r->data() === ['token' => 'server-token', 'touch_activity' => false]);
    }

    public function test_notification_badge_poll_does_not_renew_idle_session(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me')
            ? ['user_id' => 'trusted-user', 'organization_id' => 'trusted-organization', 'session_id' => 'trusted-session', 'role' => 'viewer', 'terms_required' => false]
            : ['items' => [], 'total' => 0, 'unread' => 0]]));
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/notifications?page=1&touch_activity=1')->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/me')
            && $r->data() === ['token' => 'server-token', 'touch_activity' => false]);
    }

    public function test_chat_background_reads_do_not_renew_idle_session_but_send_does(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me')
            ? ['user_id' => 'trusted-user', 'organization_id' => 'trusted-organization',
                'session_id' => 'trusted-session', 'role' => 'viewer', 'user' => ['name' => 'Miembro'],
                'terms_required' => false]
            : ['items' => [], 'total' => 0]]));
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/conversations')->assertOk();
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/conversations/'.$id.'/messages?after=1&touch_activity=1')->assertOk();
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/conversations/'.$id.'/messages', [
            'client_id' => '22222222-2222-4222-8222-222222222222', 'body' => 'Texto',
        ])->assertOk();
        $me = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/auth/me'))
            ->values()->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame([
            ['token' => 'server-token', 'touch_activity' => false],
            ['token' => 'server-token', 'touch_activity' => false],
            ['token' => 'server-token', 'touch_activity' => true],
        ], $me);
    }

    public function test_voice_call_poll_is_passive_and_start_uses_verified_principal(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $me = ['user_id' => '22222222-2222-4222-8222-222222222222',
            'organization_id' => '33333333-3333-4333-8333-333333333333',
            'session_id' => '44444444-4444-4444-8444-444444444444',
            'role' => 'viewer', 'user' => ['name' => 'Miembro'], 'terms_required' => false];
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me') ? $me : []]));
        $this->getJson('/api/v1/voice-calls')->assertUnauthorized();
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/voice-calls')->assertOk();
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/conversations/'.$id.'/calls/'.$id.'/signals?after=4')->assertOk();
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/conversations/'.$id.'/calls', [
            'organization_id' => 'forged', 'user_id' => 'forged', 'role' => 'superadmin',
        ])->assertOk();
        $auth = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/auth/me'))
            ->values()->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame([
            ['token' => 'server-token', 'touch_activity' => false],
            ['token' => 'server-token', 'touch_activity' => false],
            ['token' => 'server-token', 'touch_activity' => true],
        ], $auth);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/conversations/'.$id.'/calls')
            && $request->hasHeader('X-SRD-Context') && !str_contains($request->body(), 'forged'));
    }

    public function test_voice_ice_credentials_are_temporary_and_only_returned_to_authenticated_user(): void
    {
        config()->set('srd.webrtc_stun_url', 'stun:localhost:3478');
        config()->set('srd.webrtc_turn_url', 'turn:localhost:3478?transport=udp');
        config()->set('srd.webrtc_turn_secret', str_repeat('s', 32));
        $user = '22222222-2222-4222-8222-222222222222';
        Http::fake(fn () => Http::response(['data' => ['user_id' => $user,
            'organization_id' => '33333333-3333-4333-8333-333333333333',
            'role' => 'viewer', 'user' => ['name' => 'Miembro'], 'terms_required' => false]]));
        $this->getJson('/api/v1/voice-calls/ice-config')->assertUnauthorized();
        $servers = $this->withSession(['credential' => 'server-token'])
            ->getJson('/api/v1/voice-calls/ice-config')->assertOk()->json('data.iceServers');
        $this->assertSame('stun:localhost:3478', $servers[0]['urls']);
        $this->assertSame('turn:localhost:3478?transport=udp', $servers[1]['urls']);
        $this->assertStringEndsWith(':'.$user, $servers[1]['username']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $servers[1]['username'], str_repeat('s', 32), true)), $servers[1]['credential']);
        $this->assertStringNotContainsString(str_repeat('s', 32), json_encode($servers));
    }

    public function test_chat_socket_authorization_is_bound_to_session_user_and_organization(): void
    {
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $org = '11111111-1111-4111-8111-111111111111';
        $user = '22222222-2222-4222-8222-222222222222';
        $session = '44444444-4444-4444-8444-444444444444';
        $channel = 'presence-chat.'.$org.'.'.$user;
        $channelData = json_encode(['user_id' => $session]);
        Http::fake(fn ($request) => Http::response(['data' => [
            'user_id' => $user, 'organization_id' => $org, 'session_id' => $session,
            'role' => 'viewer', 'terms_required' => false,
        ]]));
        $this->getJson('/api/v1/chat/broadcast-config')->assertUnauthorized();
        Http::assertNothingSent();
        $this->withSession(['credential' => 'server-token'])->getJson('/api/v1/chat/broadcast-config')
            ->assertOk()->assertJsonPath('data.key', 'public-key')->assertJsonPath('data.channel', $channel);
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/chat/broadcast-auth', [
            'socket_id' => '123.456', 'channel_name' => 'presence-chat.'.$org.'.33333333-3333-4333-8333-333333333333',
        ])->assertForbidden();
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/chat/broadcast-auth', [
            'socket_id' => 'bad', 'channel_name' => $channel,
        ])->assertUnprocessable();
        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/chat/broadcast-auth', [
            'socket_id' => '123.456', 'channel_name' => $channel,
        ])->assertOk()->assertJsonPath('data.channel_data', $channelData)
            ->assertJsonPath('data.auth', 'public-key:'.hash_hmac('sha256',
                '123.456:'.$channel.':'.$channelData, 'private-secret'));
        $me = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/auth/me'));
        $this->assertCount(4, $me);
        foreach ($me as $pair) $this->assertSame(['token' => 'server-token', 'touch_activity' => false], $pair[0]->data());
    }

    public function test_logout_requests_server_side_chat_socket_termination_after_identity_revocation(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $session = '44444444-4444-4444-8444-444444444444';
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
            ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                'organization_id' => '11111111-1111-4111-8111-111111111111', 'session_id' => $session,
                'role' => 'viewer', 'terms_required' => false]
            : []]));

        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/chat/broadcast-config')->assertUnauthorized();
        $terminate = collect(Http::recorded())->first(fn ($pair) => str_contains($pair[0]->url(), '/terminate_connections'));
        $this->assertNotNull($terminate);
        $url = $terminate[0]->url();
        $path = "/apps/srd-chat-local/users/{$session}/terminate_connections";
        $this->assertSame($path, parse_url($url, PHP_URL_PATH));
        $this->assertSame('{}', $terminate[0]->body());
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $signature = $query['auth_signature'];
        unset($query['auth_signature']);
        ksort($query);
        $this->assertSame('public-key', $query['auth_key']);
        $this->assertSame(md5('{}'), $query['body_md5']);
        $this->assertSame(hash_hmac('sha256', "POST\n{$path}\n".http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'private-secret'), $signature);
    }

    public function test_switch_replaces_server_credential_and_closes_only_the_old_socket(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $old = '44444444-4444-4444-8444-444444444444';
        $newToken = str_repeat('a', 64);
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
                ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                    'organization_id' => '11111111-1111-4111-8111-111111111111',
                    'session_id' => $old, 'role' => 'viewer', 'terms_required' => false]
                : (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/switchOrganization')
                    ? ['token' => $newToken] : [])]));

        $this->withSession(['credential' => 'old-token'])->postJson('/api/v1/auth/switchOrganization', [
            'organization_code' => 'segunda', 'terms_version_id' => '33333333-3333-4333-8333-333333333333',
            'accepted' => true,
        ])->assertOk()->assertJsonPath('data', [])->assertDontSee($newToken)
            ->assertSessionHas('credential', $newToken);
        $switch = collect(Http::recorded())->first(fn ($pair) => str_ends_with($pair[0]->url(), '/auth/switchOrganization'));
        $this->assertSame('old-token', $switch[0]['token']);
        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains("/apps/srd-chat-local/users/{$old}/terminate_connections", $paths);
    }

    public function test_revoke_others_closes_only_returned_sockets_without_exposing_session_ids(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $current = '44444444-4444-4444-8444-444444444444';
        $other = '55555555-5555-4555-8555-555555555555';
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
                ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                    'organization_id' => '11111111-1111-4111-8111-111111111111',
                    'session_id' => $current, 'role' => 'viewer', 'terms_required' => false]
                : (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/revokeOthers')
                    ? ['revoked_session_ids' => [$other]] : [])]));

        $this->withSession(['credential' => 'server-token'])->postJson('/api/v1/auth/revokeOthers')
            ->assertOk()->assertJsonPath('data', [])->assertDontSee($other);
        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains("/apps/srd-chat-local/users/{$other}/terminate_connections", $paths);
        $this->assertNotContains("/apps/srd-chat-local/users/{$current}/terminate_connections", $paths);
    }

    public function test_platform_account_suspension_closes_returned_sockets_without_exposing_ids(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $account = '11111111-1111-4111-8111-111111111111';
        $revoked = '55555555-5555-4555-8555-555555555555';
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
                ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                    'organization_id' => '33333333-3333-4333-8333-333333333333',
                    'session_id' => '44444444-4444-4444-8444-444444444444',
                    'role' => 'superadmin', 'terms_required' => false]
                : (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/platform/accounts/'.$account)
                    ? ['active' => false, 'revoked_session_ids' => [$revoked]] : [])]));

        $this->withSession(['credential' => 'server-token'])
            ->patchJson('/api/v1/platform/accounts/'.$account, ['active' => false, 'expected_active' => true,
                'role' => 'viewer', 'user_id' => 'forged'])
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonMissingPath('data.revoked_session_ids')
            ->assertDontSee($revoked);
        $forwarded = collect(Http::recorded())->first(fn ($pair) => str_ends_with($pair[0]->url(), '/platform/accounts/'.$account));
        $this->assertSame(['active' => false, 'expected_active' => true], $forwarded[0]->data());
        Http::assertSent(fn ($request) => str_contains($request->url(), "/users/{$revoked}/terminate_connections"));
    }

    public function test_chat_start_keeps_contact_id_but_never_accepts_forged_sender_context(): void
    {
        $contact = '33333333-3333-4333-8333-333333333333';
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me')
            ? ['user_id' => '22222222-2222-4222-8222-222222222222', 'organization_id' => '11111111-1111-4111-8111-111111111111',
                'session_id' => '44444444-4444-4444-8444-444444444444', 'role' => 'viewer',
                'user' => ['name' => 'Remitente'], 'terms_required' => false]
            : ['id' => '55555555-5555-4555-8555-555555555555']]));
        $this->withSession(['credential' => 'opaque'])->postJson('/api/v1/conversations', [
            'user_id' => $contact, 'organization_id' => 'forged', 'role' => 'superadmin',
            'name' => 'Falso', 'token' => 'forged',
        ])->assertOk();
        Http::assertSent(function ($request) use ($contact) {
            if (!str_ends_with($request->url(), '/conversations')) return false;
            $claims = json_decode(base64_decode(explode('.', $request->header('X-SRD-Context')[0])[0]), true);
            return $claims['aud'] === 'chat' && $claims['context']['user_id'] === '22222222-2222-4222-8222-222222222222'
                && $claims['context']['organization_id'] === '11111111-1111-4111-8111-111111111111'
                && $claims['context']['role'] === 'viewer' && $claims['context']['name'] === 'Remitente'
                && $request['user_id'] === $contact && !isset($request['organization_id'])
                && !isset($request['role']) && !isset($request['token']);
        });
    }

    public function test_forged_browser_identity_headers_cannot_authorize(): void
    {
        Http::fake();
        $this->withHeaders(['X-Organization-ID' => 'forged', 'X-Role' => 'superadmin', 'X-SRD-Context' => 'forged'])->getJson('/api/v1/persons')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_public_signup_and_unimplemented_routes_are_not_exposed(): void
    {
        $this->postJson('/api/v1/register')->assertNotFound();
        $this->postJson('/api/v1/auth/signup')->assertNotFound();
    }

    public function test_browsing_does_not_consume_auth_limit_but_auth_requests_do(): void
    {
        for ($i = 0; $i < 25; $i++) $this->getJson('/api/v1/csrf')->assertOk();
        for ($i = 0; $i < 20; $i++) $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertTooManyRequests();
        $this->getJson('/api/v1/csrf')->assertOk();
        $this->postJson('/api/v1/profile/email-change', [])->assertUnauthorized();
    }

    public function test_target_membership_role_does_not_replace_signed_caller_role(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with($request->url(), '/auth/me')
            ? ['user_id' => 'trusted-user', 'organization_id' => 'trusted-organization', 'session_id' => 'trusted-session', 'role' => 'admin', 'terms_required' => false]
            : ['id' => 'synthetic-invitation']]));
        $this->withSession(['credential' => 'opaque-server-credential'])->postJson('/api/v1/invitations', ['email' => 'nuevo@example.test', 'role' => 'viewer', 'organization_id' => 'forged', 'user_id' => 'forged'])->assertOk();
        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/invitations')) return false;
            $encoded = explode('.', $request->header('X-SRD-Context')[0])[0];
            $context = json_decode(base64_decode($encoded), true)['context'];
            return $request['role'] === 'viewer' && !isset($request['organization_id']) && !isset($request['user_id'])
                && $context['role'] === 'admin' && $context['organization_id'] === 'trusted-organization';
        });
    }

    public function test_membership_change_closes_revoked_chat_socket_without_exposing_session_id(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $member = '11111111-1111-4111-8111-111111111111';
        $revoked = '55555555-5555-4555-8555-555555555555';
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
                ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                    'organization_id' => '33333333-3333-4333-8333-333333333333',
                    'session_id' => '44444444-4444-4444-8444-444444444444',
                    'role' => 'admin', 'terms_required' => false]
                : (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/members/'.$member)
                    ? ['version' => 2, 'revoked_session_ids' => [$revoked]] : [])]));

        $this->withSession(['credential' => 'server-token'])->patchJson('/api/v1/members/'.$member,
            ['role' => 'viewer', 'active' => false, 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonMissingPath('data.revoked_session_ids');
        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains("/apps/srd-chat-local/users/{$revoked}/terminate_connections", $paths);
    }

    public function test_password_reset_closes_revoked_chat_socket_without_exposing_session_id(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $revoked = '55555555-5555-4555-8555-555555555555';
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/reset')
                ? ['revoked_session_ids' => [$revoked]] : []]));

        $this->postJson('/api/v1/auth/reset', ['challenge_id' => 'challenge', 'secret' => 'secret',
            'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertOk()->assertJsonPath('data', [])->assertDontSee($revoked);
        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains("/apps/srd-chat-local/users/{$revoked}/terminate_connections", $paths);
    }

    public function test_email_change_closes_other_chat_socket_and_preserves_public_message(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $current = '44444444-4444-4444-8444-444444444444';
        $revoked = '55555555-5555-4555-8555-555555555555';
        Http::fake(fn ($request) => Http::response(['data' =>
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/auth/me')
                ? ['user_id' => '22222222-2222-4222-8222-222222222222',
                    'organization_id' => '33333333-3333-4333-8333-333333333333',
                    'session_id' => $current, 'role' => 'viewer', 'terms_required' => false]
                : (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/profile/email-change/confirm')
                    ? ['message' => 'Correo actualizado.', 'revoked_session_ids' => [$revoked]] : [])]));

        $this->withSession(['credential' => 'server-token'])
            ->postJson('/api/v1/profile/email-change/confirm', ['challenge_id' => 'challenge', 'code' => '123456'])
            ->assertOk()->assertJsonPath('data.message', 'Correo actualizado.')
            ->assertJsonMissingPath('data.revoked_session_ids');
        $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains("/apps/srd-chat-local/users/{$revoked}/terminate_connections", $paths);
        $this->assertNotContains("/apps/srd-chat-local/users/{$current}/terminate_connections", $paths);
    }

    public function test_only_identity_can_request_confirmed_expired_socket_closure(): void
    {
        config()->set('srd.chat_broadcast_app_id', 'srd-chat-local');
        config()->set('srd.chat_broadcast_key', 'public-key');
        config()->set('srd.chat_broadcast_secret', 'private-secret');
        $id = '55555555-5555-4555-8555-555555555555';
        $available = true;
        Http::fake(function () use (&$available) { return Http::response([], $available ? 200 : 503); });
        $this->postJson('/internal/v1/chat/terminate-session', ['session_id' => $id])->assertUnauthorized();
        $this->internal('POST', 'chat/terminate-session', ['session_id' => $id], [], 'gateway')
            ->assertForbidden();
        $this->internal('POST', 'chat/terminate-session', ['session_id' => 'invalid'], [], 'identity')
            ->assertUnprocessable();
        $this->internal('POST', 'chat/terminate-session', ['session_id' => $id], [], 'identity')
            ->assertOk();
        $available = false;
        $this->internal('POST', 'chat/terminate-session', ['session_id' => $id], [], 'identity')
            ->assertStatus(503);
        Http::assertSent(fn ($request) => str_contains($request->url(), "/users/{$id}/terminate_connections"));
    }
}
