<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class UserPhotoAccessTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const ACTOR = '22222222-2222-4222-8222-222222222222';
    private const PEER = '33333333-3333-4333-8333-333333333333';
    private const OUTSIDER = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::ACTOR, self::PEER, self::OUTSIDER] as $index => $id) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Cuenta '.$index, 'email' => "photo-$index@example.test",
                'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([self::ACTOR, self::PEER] as $index => $id) {
            DB::table('memberships')->insert(['id' => "55555555-5555-4555-8555-55555555555$index", 'user_id' => $id,
                'organization_id' => self::ORG, 'role' => 'viewer']);
        }
    }

    public function test_global_superadmin_photo_access_is_characterized_without_membership_policy_changes(): void
    {
        $otherOrganization = '77777777-7777-4777-8777-777777777777';
        $superadmin = (string) \Illuminate\Support\Str::uuid();
        $regularActor = (string) \Illuminate\Support\Str::uuid();

        DB::table('users')->insert([
            ['id' => $superadmin, 'name' => 'Superadministrador global', 'email' => 'superadmin-p33@example.test',
                'password' => 'not-used', 'active' => true, 'superadmin' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $regularActor, 'name' => 'Cuenta de otra junta', 'email' => 'outsider-p33@example.test',
                'password' => 'not-used', 'active' => true, 'superadmin' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('memberships')->insert([
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => self::PEER,
                'organization_id' => $otherOrganization, 'role' => 'viewer'],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $regularActor,
                'organization_id' => self::ORG, 'role' => 'viewer'],
        ]);

        $path = 'users/'.self::PEER.'/photo-access';
        $globalPrincipal = ['organization_id' => $otherOrganization, 'user_id' => $superadmin, 'role' => 'superadmin'];
        $this->assertDatabaseMissing('memberships', [
            'organization_id' => $otherOrganization, 'user_id' => $superadmin,
        ]);

        $this->internal('POST', $path, ['action' => 'contacts.read'], $globalPrincipal, 'files')
            ->assertOk()->assertJsonPath('data.authorized', true);
        $this->internal('POST', $path, ['action' => 'profile.write'], $globalPrincipal, 'files')
            ->assertForbidden();

        DB::table('users')->where('id', $superadmin)->update(['active' => false]);
        $this->internal('POST', $path, ['action' => 'contacts.read'], $globalPrincipal, 'files')
            ->assertNotFound();

        $regularPrincipal = ['organization_id' => $otherOrganization, 'user_id' => $regularActor, 'role' => 'viewer'];
        $this->internal('POST', $path, ['action' => 'contacts.read'], $regularPrincipal, 'files')
            ->assertNotFound();
    }
    public function test_only_active_members_of_same_council_can_be_read_and_only_self_can_write(): void
    {
        $p = ['organization_id' => self::ORG, 'user_id' => self::ACTOR,
            'session_id' => '66666666-6666-4666-8666-666666666666', 'role' => 'viewer'];
        $path = 'users/'.self::PEER.'/photo-access';
        $this->internal('POST', $path, ['action' => 'contacts.read'], $p, 'files')->assertOk()->assertJsonPath('data.authorized', true);
        $this->internal('POST', $path, ['action' => 'profile.write'], $p, 'files')->assertForbidden();
        $this->internal('POST', 'users/'.self::ACTOR.'/photo-access', ['action' => 'profile.write'], $p, 'files')->assertOk();
        $this->internal('POST', 'users/'.self::OUTSIDER.'/photo-access', ['action' => 'contacts.read'], $p, 'files')->assertNotFound();
        $this->internal('POST', $path, ['action' => 'contacts.read'], $p, 'gateway')->assertForbidden();
        $this->internal('POST', $path, ['action' => 'persons.read'], $p, 'files')->assertUnprocessable();
        DB::table('memberships')->where('user_id', self::PEER)->update(['active' => false]);
        $this->internal('POST', $path, ['action' => 'contacts.read'], $p, 'files')->assertNotFound();
        DB::table('memberships')->where('user_id', self::ACTOR)->update(['active' => false]);
        $this->internal('POST', 'users/'.self::ACTOR.'/photo-access', ['action' => 'profile.write'], $p, 'files')->assertNotFound();
    }
}
