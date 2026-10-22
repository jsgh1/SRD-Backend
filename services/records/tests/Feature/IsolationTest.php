<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class IsolationTest extends TestCase
{
    use RefreshDatabase,SignedRequests;

    private array $a = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '33333333-3333-4333-8333-333333333333', 'role' => 'registrar'];

    private array $b = ['organization_id' => '22222222-2222-4222-8222-222222222222', 'user_id' => '44444444-4444-4444-8444-444444444444', 'role' => 'admin'];

    private function person(array $changes = []): array
    {
        return array_merge(['document_type' => 'CC', 'document_number' => '000001', 'first_names' => 'Persona ficticia', 'status' => 'pending', 'note' => 'Nota privada', 'authorization_basis' => 'Soporte de prueba', 'authorization_purpose' => 'Validación automatizada'], $changes);
    }

    private function create(array $p): string
    {
        return $this->internal('POST', 'persons', $this->person(), $p)->assertOk()->json('data.id');
    }

    public function test_pending_record_can_wait_for_authorization_without_losing_identity(): void
    {
        $draft = ['document_type' => 'CC', 'document_number' => 'PENDING-1', 'first_names' => 'Borrador', 'status' => 'pending'];
        $id = $this->internal('POST', 'persons', $draft, $this->a)->assertOk()->json('data.id');
        $this->assertDatabaseMissing('data_authorizations', ['person_id' => $id]);
        $this->internal('GET', 'persons/'.$id, [], $this->a)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->internal('PATCH', 'persons/'.$id, $draft + ['version' => 1, 'authorization_basis' => 'Soporte'], $this->a)->assertUnprocessable();
        $this->internal('PATCH', 'persons/'.$id, $draft + ['version' => 1, 'authorization_basis' => 'Consentimiento escrito', 'authorization_purpose' => 'Registro comunitario'], $this->a)->assertOk();
        $this->assertDatabaseHas('data_authorizations', ['person_id' => $id, 'basis' => 'Consentimiento escrito']);
    }

    public function test_two_juntas_cannot_share_reads_updates_deletes_or_statistics(): void
    {
        $id = $this->create($this->a);
        $other = $this->create($this->b);
        $this->internal('GET', 'persons', [], $this->a)->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $id);
        $this->internal('GET', 'persons/'.$other, [], $this->a)->assertNotFound();
        $this->internal('PATCH', 'persons/'.$other, $this->person(['version' => 1]), $this->a)->assertNotFound();
        $this->internal('DELETE', 'persons/'.$id, ['version' => 1, 'confirmed' => true], $this->b)->assertNotFound();
        $this->internal('GET', 'dashboard', [], $this->a)->assertJsonPath('data.total', 1);
    }

    public function test_all_six_roles_have_server_side_write_and_note_permissions(): void
    {
        $id = $this->create($this->a);
        foreach (Access::ROLES as $role) {
            $p = array_merge($this->a, ['role' => $role]);
            $view = $this->internal('GET', 'persons/'.$id, [], $p)->assertOk();
            if (in_array($role, ['superadmin', 'admin', 'registrar'])) {
                $view->assertJsonPath('data.note', 'Nota privada');
            } else {
                $view->assertJsonMissingPath('data.note');
                $this->internal('POST', 'persons', $this->person(['document_number' => 'x-'.$role]), $p)->assertForbidden();
            }
        }
        $this->internal('DELETE', 'persons/'.$id, ['version' => 1, 'confirmed' => true], $this->a)->assertForbidden();
    }

    public function test_document_uniqueness_and_optimistic_concurrency(): void
    {
        $id = $this->create($this->a);
        $this->internal('POST', 'persons', $this->person(), $this->a)->assertConflict();
        $this->internal('PATCH', 'persons/'.$id, $this->person(['version' => 1, 'first_names' => 'Cambio uno']), $this->a)->assertOk()->assertJsonPath('data.version', 2);
        $this->internal('PATCH', 'persons/'.$id, $this->person(['version' => 1, 'first_names' => 'Cambio dos']), $this->a)->assertConflict();
        $this->assertDatabaseHas('persons', ['id' => $id, 'first_names' => 'Cambio uno', 'document_number' => '000001']);
    }

    public function test_completed_requires_applicable_fields_but_not_note(): void
    {
        $this->internal('POST', 'persons', $this->person(['status' => 'complete']), $this->a)->assertUnprocessable();
        $complete = $this->person(['status' => 'complete', 'last_names' => 'Prueba', 'affiliated' => true, 'zone' => 'urban', 'gender' => 'other', 'birth_date' => '1990-01-01', 'phone' => '3000000000', 'email' => 'persona@example.test', 'position_code' => 'other', 'descriptive_role' => 'viewer', 'note' => null]);
        $this->internal('POST', 'persons', $complete, $this->a)->assertUnprocessable();
        $this->internal('POST', 'persons', $complete + ['address' => 'Calle ficticia', 'neighborhood' => 'Barrio de prueba'], $this->a)->assertOk();
    }

    public function test_pagination_filters_deletion_and_minimal_audit(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->internal('POST', 'persons', $this->person(['document_number' => 'TEST'.$i]), $this->a)->assertOk();
        }
        $r = $this->internal('GET', 'persons', ['page' => 2, 'page_size' => 10, 'status' => 'pending'], $this->a)->assertOk()->assertJsonPath('data.total', 12);
        $this->assertCount(2, $r->json('data.items'));
        $id = $r->json('data.items.0.id');
        $admin = array_merge($this->a, ['role' => 'admin']);
        $this->internal('DELETE', 'persons/'.$id, ['version' => 1, 'confirmed' => false], $admin)->assertUnprocessable();
        $this->internal('DELETE', 'persons/'.$id, ['version' => 1, 'confirmed' => true], $admin)->assertOk();
        $this->internal('GET','persons/'.$id,[],$admin)->assertNotFound();
        $this->assertDatabaseMissing('person_notes',['person_id' => $id]);
        $this->assertStringNotContainsString('Nota privada',json_encode(DB::table('outbox_events')->get()));
    }
}
