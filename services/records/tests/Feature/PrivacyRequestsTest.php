<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PrivacyRequestsTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $admin = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'admin'];

    private function person(array $p): string
    {
        return $this->internal('POST', 'persons', [
            'document_type' => 'CC', 'document_number' => 'PRIV-001', 'first_names' => 'Persona Ficticia',
            'status' => 'pending', 'authorization_basis' => 'Prueba', 'authorization_purpose' => 'Prueba automatizada',
        ], $p)->assertOk()->json('data.id');
    }

    public function test_request_lifecycle_keeps_erasure_pending_and_preserves_history(): void
    {
        $personId = $this->person($this->admin);
        $created = $this->internal('POST', 'privacy-requests', [
            'person_id' => $personId, 'kind' => 'erasure', 'summary' => 'Petición ficticia de supresión',
        ], $this->admin)->assertOk()->assertJsonPath('data.status', 'open');
        $id = $created->json('data.id');
        $this->internal('DELETE', 'persons/'.$personId, ['version' => 1, 'confirmed' => true], $this->admin)
            ->assertConflict();
        $this->internal('GET', 'privacy-requests', [], $this->admin)->assertOk()->assertJsonPath('data.items.0.id', $id);
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'approved_pending_erasure', 'decision' => 'Motivo suficiente'], $this->admin)->assertUnprocessable();
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'in_review'], $this->admin)->assertOk()->assertJsonPath('data.version', 2);
        $otherAdmin = array_replace($this->admin, ['user_id' => '44444444-4444-4444-8444-444444444444']);
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 2, 'status' => 'rejected', 'decision' => 'Motivo suficiente'], $otherAdmin)->assertForbidden();
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'rejected', 'decision' => 'Motivo suficiente'], $this->admin)->assertConflict();
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 2, 'status' => 'resolved', 'decision' => 'Motivo suficiente'], $this->admin)->assertUnprocessable();
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 2, 'status' => 'approved_pending_erasure', 'decision' => 'Se aprueba la solicitud ficticia'], $this->admin)->assertOk();
        $this->assertDatabaseHas('persons', ['id' => $personId]);
        $this->assertDatabaseCount('privacy_request_history', 3);
        $this->internal('GET', 'privacy-requests', [], $this->admin)->assertJsonCount(3, 'data.items.0.history');
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'approved_pending_erasure', 'responsible_id' => $this->admin['user_id']]);
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 3, 'status' => 'rejected', 'decision' => 'Motivo suficiente'], $this->admin)->assertUnprocessable();
    }

    public function test_roles_and_tenants_are_enforced_on_every_operation(): void
    {
        $personId = $this->person($this->admin);
        $other = array_replace($this->admin, ['organization_id' => '33333333-3333-4333-8333-333333333333']);
        $this->internal('POST', 'privacy-requests', ['person_id' => $personId, 'kind' => 'access', 'summary' => 'Solicitud de consulta'], $other)->assertNotFound();
        $id = $this->internal('POST', 'privacy-requests', ['person_id' => $personId, 'kind' => 'access', 'summary' => 'Solicitud de consulta'], $this->admin)->assertOk()->json('data.id');
        $this->internal('GET', 'privacy-requests', [], $other)->assertOk()->assertJsonCount(0, 'data.items');
        $this->internal('GET', 'privacy-erasure-status', [], $other)->assertOk()->assertJsonPath('data.approved_pending', 0);
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'in_review'], $other)->assertNotFound();
        $this->internal('POST', 'privacy-requests/'.$id.'/execute-erasure',
            ['version' => 1, 'confirmed' => true], $other)->assertNotFound();
        foreach (Access::ROLES as $role) {
            if (in_array($role, ['admin', 'superadmin'], true)) continue;
            $reader = array_replace($this->admin, ['role' => $role]);
            $this->internal('GET', 'privacy-requests', [], $reader)->assertForbidden();
            $this->internal('GET', 'privacy-erasure-status', [], $reader)->assertForbidden();
            $this->internal('POST', 'privacy-requests', ['person_id' => $personId, 'kind' => 'access', 'summary' => 'Solicitud de consulta'], $reader)->assertForbidden();
            $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'in_review'], $reader)->assertForbidden();
            $this->internal('POST', 'privacy-requests/'.$id.'/execute-erasure',
                ['version' => 1, 'confirmed' => true], $reader)->assertForbidden();
        }
        $this->assertSame(1, DB::table('privacy_requests')->count());
    }

    public function test_approved_erasure_removes_local_data_and_waits_for_photo_ack(): void
    {
        $personId = $this->person($this->admin);
        DB::table('person_notes')->insert(['person_id' => $personId,
            'organization_id' => $this->admin['organization_id'], 'body' => 'Nota privada de prueba',
            'updated_by' => $this->admin['user_id'], 'updated_at' => now()]);
        $id = $this->internal('POST', 'privacy-requests', ['person_id' => $personId,
            'kind' => 'erasure', 'summary' => 'Eliminar datos ficticios de prueba'], $this->admin)->assertOk()->json('data.id');
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 1, 'status' => 'in_review'], $this->admin)->assertOk();
        $this->internal('PATCH', 'privacy-requests/'.$id, ['version' => 2,
            'status' => 'approved_pending_erasure', 'decision' => 'Solicitud ficticia aprobada'], $this->admin)->assertOk();
        $this->internal('GET', 'privacy-erasure-status', [], $this->admin)->assertOk()
            ->assertJsonPath('data.approved_pending', 1)->assertJsonPath('data.in_progress', 0);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'detach-for-erasure')) return Http::response(['data' => ['detached' => true]]);
            return Http::response([], 503);
        });
        $this->internal('POST', 'privacy-requests/'.$id.'/execute-erasure',
            ['version' => 2, 'confirmed' => true], $this->admin)->assertConflict();
        $this->internal('POST', 'privacy-requests/'.$id.'/execute-erasure',
            ['version' => 3, 'confirmed' => true], $this->admin)
            ->assertOk()->assertJsonPath('data.status', 'erasure_in_progress');
        $this->assertDatabaseMissing('persons', ['id' => $personId]);
        $this->assertDatabaseMissing('person_notes', ['person_id' => $personId]);
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'summary' => 'Contenido eliminado tras supresión.',
            'decision' => null, 'status' => 'erasure_in_progress']);
        $job = DB::table('photo_deletions')->where('privacy_request_id', $id)->sole();
        $this->assertNull($job->delivered_at);
        $this->internal('GET', 'privacy-erasure-status', [], $this->admin)->assertOk()
            ->assertJsonPath('data.in_progress', 1)->assertJsonPath('data.photo_jobs_pending', 1);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['data' => ['accepted' => true]]));
        $this->travel(2)->minutes();
        $this->assertSame('delivered', app(\App\Application\PhotoDeletionPublisher::class)->publishOne($job->id));
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'erased', 'version' => 5]);
        $this->internal('GET', 'privacy-erasure-status', [], $this->admin)->assertOk()
            ->assertJsonPath('data.in_progress', 0)->assertJsonPath('data.photo_jobs_pending', 0);
        $this->assertDatabaseCount('privacy_request_history', 5);
    }

    public function test_requests_are_paginated_within_the_active_organization(): void
    {
        $personId = $this->person($this->admin);
        for ($i = 0; $i < 27; $i++) {
            DB::table('privacy_requests')->insert([
                'id' => sprintf('90000000-0000-4000-8000-%012d', $i),
                'organization_id' => $this->admin['organization_id'], 'person_id' => $personId,
                'kind' => 'access', 'status' => 'open', 'summary' => 'Solicitud ficticia de acceso',
                'created_by' => $this->admin['user_id'], 'version' => 1,
                'created_at' => now()->subMinutes($i), 'updated_at' => now()->subMinutes($i),
            ]);
        }
        $first = $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['page' => 1])->assertOk();
        $first->assertJsonPath('data.total', 27)->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 25)->assertJsonCount(25, 'data.items')
            ->assertJsonPath('data.items.0.id', '90000000-0000-4000-8000-000000000000');
        $second = $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['page' => 2])->assertOk();
        $second->assertJsonPath('data.total', 27)->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.id', '90000000-0000-4000-8000-000000000026');
        DB::table('privacy_requests')->where('id', '90000000-0000-4000-8000-000000000026')
            ->update(['kind' => 'erasure', 'status' => 'approved_pending_erasure']);
        $filtered = $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', [
            'page' => 1, 'kind' => 'erasure', 'status' => 'approved_pending_erasure',
        ])->assertOk();
        $filtered->assertJsonPath('data.total', 1)->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', '90000000-0000-4000-8000-000000000026');
        $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['status' => 'open'])
            ->assertOk()->assertJsonPath('data.total', 26);
        $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['kind' => 'invalid'])
            ->assertUnprocessable();
        $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['status' => 'invalid'])
            ->assertUnprocessable();
        $this->internal('GET', 'privacy-requests', [], $this->admin, 'gateway', ['page' => 0])->assertUnprocessable();
        $other = array_replace($this->admin, ['organization_id' => '33333333-3333-4333-8333-333333333333']);
        $this->internal('GET', 'privacy-requests', [], $other, 'gateway', [
            'page' => 1, 'kind' => 'erasure', 'status' => 'approved_pending_erasure',
        ])->assertOk()
            ->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.items');
    }
}
