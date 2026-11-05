<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PlatformAccountTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private string $orgA = '11111111-1111-4111-8111-111111111111';
    private string $orgB = '22222222-2222-4222-8222-222222222222';
    private string $super = '33333333-3333-4333-8333-333333333333';
    private string $target = '44444444-4444-4444-8444-444444444444';
    private string $otherAdmin = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([[$this->super, 'super@example.test', true], [$this->target, 'target@example.test', false],
            [$this->otherAdmin, 'other@example.test', false]] as [$id, $email, $superadmin]) {
            DB::table('users')->insert(['id' => $id, 'email' => $email, 'name' => $email,
                'password' => 'synthetic-unused-hash', 'superadmin' => $superadmin,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$this->super, $this->orgA, 'superadmin'], [$this->target, $this->orgA, 'admin'],
            [$this->target, $this->orgB, 'viewer'], [$this->otherAdmin, $this->orgA, 'admin']] as [$user, $org, $role]) {
            DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'user_id' => $user,
                'organization_id' => $org, 'role' => $role]);
        }
    }

    private function principal(): array
    {
        return ['user_id' => $this->super, 'organization_id' => $this->orgA, 'role' => 'superadmin'];
    }

    public function test_only_real_superadmin_can_list_and_suspend_across_organizations(): void
    {
        $this->internal('GET', 'platform/accounts', [], ['user_id' => $this->target,
            'organization_id' => $this->orgA, 'role' => 'admin'])->assertForbidden();
        $this->internal('GET', 'platform/accounts', [], ['user_id' => $this->target,
            'organization_id' => $this->orgA, 'role' => 'superadmin'])->assertForbidden();
        $this->internal('GET', 'platform/accounts', ['search' => 'target@example.test'], $this->principal())
            ->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.memberships_count', 2);

        $ids = [];
        foreach ([$this->orgA, $this->orgB] as $org) {
            $ids[] = $id = (string) Str::uuid();
            DB::table('auth_sessions')->insert(['id' => $id, 'user_id' => $this->target,
                'organization_id' => $org, 'token_hash' => hash('sha256', $id),
                'last_activity_at' => now(), 'expires_at' => now()->addHours(8), 'created_at' => now()]);
        }
        $challenge = (string) Str::uuid();
        DB::table('auth_challenges')->insert(['id' => $challenge, 'user_id' => $this->target,
            'organization_id' => $this->orgA, 'purpose' => 'login', 'destination' => 'target@example.test',
            'digest' => str_repeat('a', 64), 'attempts' => 0, 'expires_at' => now()->addMinutes(5),
            'created_at' => now()]);

        $response = $this->internal('PATCH', 'platform/accounts/'.$this->target,
            ['active' => false, 'expected_active' => true], $this->principal())->assertOk()
            ->assertJsonPath('data.active', false);
        $this->assertEqualsCanonicalizing($ids, $response->json('data.revoked_session_ids'));
        $this->assertDatabaseHas('users', ['id' => $this->target, 'active' => false]);
        foreach ($ids as $id) {
            $this->assertNotNull(DB::table('auth_sessions')->where('id', $id)->value('revoked_at'));
            $this->assertDatabaseHas('chat_socket_closures', ['session_id' => $id, 'confirmed_at' => null]);
        }
        $this->assertNotNull(DB::table('auth_challenges')->where('id', $challenge)->value('consumed_at'));
        foreach ([$this->orgA, $this->orgB] as $org) {
            $this->assertDatabaseHas('outbox_events', ['organization_id' => $org,
                'action' => 'account.suspended', 'actor_id' => $this->super]);
        }
        $this->internal('PATCH', 'platform/accounts/'.$this->target,
            ['active' => false, 'expected_active' => true], $this->principal())->assertConflict();
        $this->internal('PATCH', 'platform/accounts/'.$this->target,
            ['active' => true, 'expected_active' => false], $this->principal())->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->target, 'active' => true]);
        foreach ($ids as $id) $this->assertNotNull(DB::table('auth_sessions')->where('id', $id)->value('revoked_at'));
    }

    public function test_self_superadmin_and_last_active_junta_admin_are_protected(): void
    {
        $this->internal('PATCH', 'platform/accounts/'.$this->super,
            ['active' => false, 'expected_active' => true], $this->principal())->assertStatus(422);
        DB::table('memberships')->where('user_id', $this->otherAdmin)->update(['active' => false]);
        $this->internal('PATCH', 'platform/accounts/'.$this->target,
            ['active' => false, 'expected_active' => true], $this->principal())->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $this->target, 'active' => true]);
        $this->assertDatabaseMissing('outbox_events', ['action' => 'account.suspended']);
    }
}
