<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PersonLinkErasureTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    public function test_only_records_can_detach_a_person_and_the_action_is_idempotent(): void
    {
        $org = '11111111-1111-4111-8111-111111111111';
        $actor = '22222222-2222-4222-8222-222222222222';
        $person = '33333333-3333-4333-8333-333333333333';
        $other = '44444444-4444-4444-8444-444444444444';
        DB::table('users')->insert(['id' => $actor, 'email' => 'linked@example.test',
            'name' => 'Ficticia', 'password' => Hash::make('Test-123'),
            'created_at' => now(), 'updated_at' => now()]);
        $membership = (string) Str::uuid();
        DB::table('memberships')->insert(['id' => $membership, 'organization_id' => $org,
            'user_id' => $actor, 'role' => 'viewer', 'person_id' => $person]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'organization_id' => $other,
            'user_id' => $actor, 'role' => 'viewer', 'person_id' => $person]);
        $invitation = (string) Str::uuid();
        DB::table('invitations')->insert(['id' => $invitation, 'organization_id' => $org,
            'invited_by' => $actor, 'email' => 'invite@example.test', 'role' => 'viewer',
            'person_id' => $person, 'secret_hash' => str_repeat('a', 64),
            'secret_encrypted' => 'test', 'expires_at' => now()->addDay(),
            'next_attempt_at' => now(), 'created_at' => now()]);
        $session = (string) Str::uuid();
        DB::table('auth_sessions')->insert(['id' => $session, 'user_id' => $actor,
            'organization_id' => $org, 'token_hash' => str_repeat('b', 64),
            'last_activity_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $principal = ['organization_id' => $org, 'user_id' => $actor];
        $path = 'persons/'.$person.'/detach-for-erasure';
        $this->internal('POST', $path, [], $principal)->assertForbidden();
        $this->internal('POST', $path, [], $principal, 'records')->assertOk()->assertJsonPath('data.detached', true);
        $this->assertDatabaseHas('memberships', ['id' => $membership, 'person_id' => null, 'version' => 2]);
        $this->assertDatabaseHas('memberships', ['organization_id' => $other, 'person_id' => $person]);
        $this->assertDatabaseHas('invitations', ['id' => $invitation, 'person_id' => null,
            'secret_encrypted' => null, 'email' => 'erased+'.$invitation.'@invalid.local']);
        $this->assertNotNull(DB::table('auth_sessions')->where('id', $session)->value('revoked_at'));
        $this->internal('POST', $path, [], $principal, 'records')->assertOk();
        $this->assertDatabaseHas('memberships', ['id' => $membership, 'version' => 2]);
    }
}
