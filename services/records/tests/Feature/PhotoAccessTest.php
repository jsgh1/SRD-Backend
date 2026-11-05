<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PhotoAccessTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $principal = [
        'organization_id' => '11111111-1111-4111-8111-111111111111',
        'user_id' => '33333333-3333-4333-8333-333333333333',
        'role' => 'admin',
    ];

    private function createPerson(): string
    {
        return $this->internal('POST', 'persons', [
            'document_type' => 'CC', 'document_number' => 'PHOTO-TEST',
            'first_names' => 'Persona ficticia', 'status' => 'pending',
            'note' => 'Nota que nunca debe salir en la autorización',
            'authorization_basis' => 'Prueba', 'authorization_purpose' => 'Prueba',
        ], $this->principal)->assertOk()->json('data.id');
    }

    public function test_all_roles_receive_only_a_decision_and_write_is_restricted(): void
    {
        $id = $this->createPerson();
        $events = DB::table('outbox_events')->count();
        foreach (Access::ROLES as $role) {
            $principal = array_replace($this->principal, ['role' => $role]);
            $response = $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], $principal, 'files')->assertOk();
            $this->assertSame(['authorized' => true], $response->json('data'));
            $this->assertSame(['data', 'meta'], array_keys($response->json()));
            $response->assertHeader('Cache-Control', 'no-store, private');
            $write = $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.write'], $principal, 'files');
            if (in_array($role, ['superadmin', 'admin', 'registrar'], true)) {
                $write->assertOk();
            } else {
                $write->assertForbidden();
            }
        }
        // Authorization probes are not person reads and must not generate duplicate read events.
        $this->assertSame($events, DB::table('outbox_events')->count());
    }

    public function test_linked_viewer_may_write_only_the_own_person_slot_after_identity_verification(): void
    {
        $id = $this->createPerson();
        $viewer = array_replace($this->principal, ['role' => 'viewer',
            'session_id' => '44444444-4444-4444-8444-444444444444']);
        Http::fake(fn () => Http::response(['data' => ['authorized' => true]]));
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.write', 'slot' => 'person'], $viewer, 'files')
            ->assertOk()->assertJsonPath('data.authorized', true);
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.write', 'slot' => 'document'], $viewer, 'files')
            ->assertForbidden();
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.write'], $viewer, 'files')
            ->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_scope_comes_from_signed_context_and_is_rechecked_after_deletion(): void
    {
        $id = $this->createPerson();
        $other = array_replace($this->principal, ['organization_id' => '22222222-2222-4222-8222-222222222222']);
        $this->internal('POST', "persons/$id/photo-access", [
            'action' => 'persons.read', 'organization_id' => $this->principal['organization_id'],
            'principal' => $this->principal, 'role' => 'superadmin',
        ], $other, 'files')->assertNotFound();
        $this->internal('POST', 'persons/99999999-9999-4999-8999-999999999999/photo-access', ['action' => 'persons.read'], $this->principal, 'files')->assertNotFound();
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], $this->principal, 'files')->assertOk();
        $this->internal('DELETE', "persons/$id", ['version' => 1, 'confirmed' => true], $this->principal)->assertOk();
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], $this->principal, 'files')->assertNotFound();
    }

    public function test_gateway_other_issuers_unsigned_and_missing_principals_are_rejected(): void
    {
        $id = $this->createPerson();
        foreach (['gateway', 'identity', 'configuration', 'records', 'audit'] as $issuer) {
            $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], $this->principal, $issuer)->assertForbidden();
        }
        $this->postJson("/internal/v1/persons/$id/photo-access", ['action' => 'persons.read'])->assertUnauthorized();
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], [], 'files')->assertForbidden();
        $invalidRole = array_replace($this->principal, ['role' => 'unknown']);
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.read'], $invalidRole, 'files')->assertForbidden();
        $this->internal('POST', "persons/$id/photo-access", ['action' => 'persons.delete'], $this->principal, 'files')->assertUnprocessable();
    }

    public function test_files_issuer_cannot_read_person_fields_or_use_other_apis(): void
    {
        $id = $this->createPerson();
        foreach (['persons', "persons/$id", 'dashboard', 'person-fields', 'person-positions', 'outbox-status'] as $path) {
            $this->internal('GET', $path, [], $this->principal, 'files')->assertUnauthorized();
        }
        $this->internal('DELETE', "persons/$id", ['version' => 1, 'confirmed' => true], $this->principal, 'files')->assertUnauthorized();
        $this->assertDatabaseHas('persons', ['id' => $id]);
    }
}
