<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PlatformTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $p = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'superadmin'];
    private array $data = ['code' => 'junta-nueva', 'name' => 'Junta nueva', 'terms' => 'Términos iniciales sintéticos de la junta.', 'terms_en' => 'Synthetic initial terms for the council.'];

    public function test_platform_permissions_and_creation_are_atomic(): void
    {
        foreach (['admin', 'registrar', 'treasurer', 'auditor', 'viewer'] as $role) {
            $p = array_replace($this->p, ['role' => $role]);
            $this->internal('GET', 'platform/organizations', [], $p)->assertForbidden();
            $this->internal('POST', 'platform/organizations', $this->data, $p)->assertForbidden();
        }
        $this->internal('POST', 'platform/organizations', array_replace($this->data, ['terms' => '']), $this->p)->assertUnprocessable();
        $this->internal('POST', 'platform/organizations', array_diff_key($this->data, ['terms_en' => true]), $this->p)->assertUnprocessable();
        $this->assertDatabaseCount('organizations', 0);
        $id = $this->internal('POST', 'platform/organizations', $this->data, $this->p)->assertOk()->json('data.id');
        $this->assertDatabaseHas('terms_versions', ['organization_id' => $id, 'version' => 1, 'body_en' => $this->data['terms_en']]);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $id, 'action' => 'organization.created']);
        $this->internal('POST', 'platform/organizations', $this->data, $this->p)->assertUnprocessable();
        $this->internal('GET', 'platform/organizations', [], $this->p)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_suspend_reactivate_conflicts_and_current_organization_protection(): void
    {
        $id = $this->internal('POST', 'platform/organizations', $this->data, $this->p)->assertOk()->json('data.id');
        $path = 'platform/organizations/'.$id;
        $this->internal('PATCH', $path, ['active' => false, 'version' => 1], array_replace($this->p, ['role' => 'admin']))->assertForbidden();
        $this->internal('PATCH', $path, ['active' => false, 'version' => 1], array_replace($this->p, ['organization_id' => $id]))->assertUnprocessable();
        $this->internal('PATCH', $path, ['active' => false, 'version' => 1], $this->p)->assertOk()->assertJsonPath('data.version', 2);
        $this->internal('GET', 'organizations/code/junta-nueva')->assertNotFound();
        $this->internal('GET', 'organizations/'.$id, [], [], 'identity')->assertForbidden();
        $this->internal('PATCH', $path, ['active' => true, 'version' => 1], $this->p)->assertConflict();
        $this->internal('PATCH', $path, ['active' => true, 'version' => 2], $this->p)->assertOk();
        $this->internal('GET', 'organizations/code/junta-nueva')->assertOk();
        $this->assertSame(1, DB::table('terms_versions')->count());
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $id, 'action' => 'organization.suspended']);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $id, 'action' => 'organization.activated']);
    }
}
