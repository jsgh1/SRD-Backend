<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class InventoryTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'treasurer', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222',
            'role' => $role, 'name' => 'Tesorera de prueba'];
    }

    private function movable(string $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'): array
    {
        return ['code' => 'SILLAS-1', 'type' => 'movable', 'name' => 'Sillas', 'category' => 'Mobiliario',
            'unit' => 'unidad', 'description' => 'Sillas comunitarias', 'location' => 'Salón',
            'condition' => 'Bueno', 'responsible_name' => 'Junta', 'quantity' => 34, 'idempotency_key' => $key];
    }

    public function test_movable_stock_rejects_overspend_and_keeps_history(): void
    {
        $p = $this->principal();
        $created = $this->internal('POST', 'assets', $this->movable(), $p)->assertOk()->json('data');
        $id = $created['asset']['id'];
        $this->assertSame(34, $created['asset']['quantity']);
        $this->internal('POST', 'assets', $this->movable(), $p)->assertJsonPath('data.asset.id', $id);
        $this->internal('POST', 'assets', $this->movable('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $p)->assertStatus(409);
        $out = ['type' => 'out', 'quantity' => 5, 'reason' => 'Entrega para actividad',
            'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'];
        $this->internal('POST', 'assets/'.$id.'/movements', $out, $p)->assertJsonPath('data.asset.quantity', 29)
            ->assertJsonPath('data.movement.delta', -5);
        $this->internal('POST', 'assets/'.$id.'/movements', $out, $p)->assertJsonPath('data.asset.quantity', 29);
        $this->internal('POST', 'assets/'.$id.'/movements', array_replace($out, ['quantity' => 6]), $p)->assertStatus(409);
        $this->internal('POST', 'assets/'.$id.'/movements', array_replace($out, [
            'quantity' => 30, 'idempotency_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']), $p)->assertUnprocessable();
        $this->internal('GET', 'assets/'.$id, [], $p)->assertJsonPath('data.asset.quantity', 29)
            ->assertJsonCount(2, 'data.movements');
        $this->assertDatabaseHas('outbox_events', ['action' => 'inventory.movement_rejected', 'result' => 'rejected']);
        $this->internal('POST', 'assets/'.$id.'/movements', [
            'type' => 'adjust', 'quantity' => 20, 'reason' => 'Conteo físico documentado',
            'idempotency_key' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'], $p)
            ->assertJsonPath('data.asset.quantity', 20)->assertJsonPath('data.movement.delta', -9);
        $this->assertSame(20, (int) DB::table('asset_movements')->where('asset_id', $id)->sum('delta'));
    }

    public function test_real_estate_version_retirement_and_roles(): void
    {
        $p = $this->principal();
        $data = array_replace($this->movable(), ['code' => 'CASETA-1', 'type' => 'real_estate',
            'name' => 'Caseta comunal', 'quantity' => 1, 'unit' => null, 'category' => null]);
        $asset = $this->internal('POST', 'assets', $data, $p)->assertOk()->json('data.asset');
        $id = $asset['id'];
        $this->internal('POST', 'assets', array_replace($data, ['code' => 'CASETA-2', 'quantity' => 2,
            'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']), $p)->assertUnprocessable();
        $update = ['version' => 1, 'name' => 'Caseta renovada', 'location' => 'Parque principal',
            'condition' => 'Bueno'];
        $this->internal('PATCH', 'assets/'.$id, $update, $p)->assertJsonPath('data.version', 2);
        $this->internal('PATCH', 'assets/'.$id, $update, $p)->assertStatus(409);
        $this->internal('POST', 'assets/'.$id.'/movements', [
            'type' => 'out', 'quantity' => 1, 'reason' => 'No aplica a inmuebles',
            'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'], $p)->assertStatus(409);
        $retire = ['version' => 2, 'reason' => 'Baja documentada de inmueble',
            'idempotency_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'];
        $this->internal('POST', 'assets/'.$id.'/retire', $retire, $p)->assertJsonPath('data.asset.status', 'retired')
            ->assertJsonPath('data.asset.quantity', 0);
        $this->internal('POST', 'assets/'.$id.'/retire', $retire, $p)->assertJsonPath('data.asset.status', 'retired');
        $this->internal('GET', 'assets/'.$id, [], $p)->assertJsonCount(2, 'data.movements');
        foreach (['viewer', 'registrar', 'auditor'] as $role) $this->internal('GET', 'assets', [], $this->principal($role))->assertForbidden();
        $this->internal('GET', 'assets/'.$id, [], $this->principal(org: self::OTHER))->assertNotFound();
        $this->internal('GET', 'assets', [], $p, 'calendar')->assertUnauthorized();
    }

    public function test_files_photo_probe_is_minimal_and_respects_tenant_role_and_retirement(): void
    {
        $p = $this->principal();
        $asset = $this->internal('POST', 'assets', $this->movable(), $p)->assertOk()->json('data.asset');
        $path = 'assets/'.$asset['id'].'/photo-access';
        $this->internal('POST', $path, ['action' => 'inventory.read'], $p, 'gateway')->assertForbidden();
        $this->internal('POST', $path, ['action' => 'inventory.read'], $p, 'files')
            ->assertOk()->assertJsonPath('data.authorized', true)->assertJsonMissingPath('data.asset');
        $this->internal('POST', $path, ['action' => 'inventory.write'], $this->principal('viewer'), 'files')->assertForbidden();
        $this->internal('POST', $path, ['action' => 'inventory.read'], $this->principal(org: self::OTHER), 'files')->assertNotFound();
        $this->internal('POST', $path, ['action' => 'persons.read'], $p, 'files')->assertUnprocessable();
        $this->internal('POST', $path, ['action' => 'inventory.read'], $this->principal('registrar'), 'files')->assertForbidden();
        $this->internal('POST', 'assets/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/photo-access',
            ['action' => 'inventory.read'], $p, 'files')->assertNotFound();
        $this->internal('POST', $path, ['action' => 'inventory.write'], $p, 'files')->assertOk();
        $this->internal('POST', 'assets/'.$asset['id'].'/movements', [
            'type' => 'adjust', 'quantity' => 0, 'reason' => 'Conteo para baja',
            'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], $p)->assertOk();
        $this->internal('POST', 'assets/'.$asset['id'].'/retire', [
            'version' => 2, 'reason' => 'Baja documentada',
            'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'], $p)->assertOk();
        $this->internal('POST', $path, ['action' => 'inventory.read'], $p, 'files')->assertOk();
        $this->internal('POST', $path, ['action' => 'inventory.write'], $p, 'files')->assertForbidden();
    }

    public function test_search_matches_literal_wildcards_and_only_its_organization(): void
    {
        $p = $this->principal();
        $first = array_replace($this->movable(), [
            'code' => 'SPECIAL-50', 'name' => 'Equipo 50%_! comunitario',
        ]);
        $second = array_replace($this->movable('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), [
            'code' => 'SPECIAL-51', 'name' => 'Equipo 50abc comunitario',
        ]);
        $this->internal('POST', 'assets', $first, $p)->assertOk();
        $this->internal('POST', 'assets', $second, $p)->assertOk();
        $this->internal('POST', 'assets', array_replace($first, [
            'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ]), $this->principal(org: self::OTHER))->assertOk();
        $inventory = app(\App\Application\InventoryService::class);
        $result = $inventory->index($p, ['q' => '50%_!'])['data'];
        $this->assertSame(1, $result['total']);
        $this->assertSame('SPECIAL-50', $result['items'][0]['code']);
        $this->assertSame(0, $inventory->index($p, ['q' => '50%_!missing'])['data']['total']);
        $this->assertSame(2, $inventory->index($p, ['q' => 'Equipo'])['data']['total']);
    }

    public function test_history_filters_combine_colombian_days_literal_reason_and_permissions(): void
    {
        $p = $this->principal();
        $asset = $this->internal('POST', 'assets', $this->movable(), $p)->assertOk()->json('data.asset');
        $path = 'assets/'.$asset['id'];
        foreach ([['2026-09-25 04:59:59', 'in', 'Entrega 50%_! previa'],
            ['2026-09-25 05:00:00', 'in', 'Entrega 50%_! inicio'],
            ['2026-09-26 04:59:59', 'in', 'Entrega 50%_! final'],
            ['2026-09-26 05:00:00', 'in', 'Entrega 50%_! posterior'],
            ['2026-09-25 12:00:00', 'out', 'Entrega 50%_! salida'],
            ['2026-09-25 12:00:01', 'in', 'Entrega 50ABC! señuelo']] as [$date, $type, $reason]) {
            $movement = $this->internal('POST', $path.'/movements', ['type' => $type, 'quantity' => 1,
                'reason' => $reason, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()], $p)->assertOk()->json('data.movement');
            DB::table('asset_movements')->where('id', $movement['id'])->update(['created_at' => $date]);
        }
        $filters = ['movement_type' => 'in', 'movement_q' => ' 50%_! ', 'movement_from' => '2026-09-25', 'movement_to' => '2026-09-25'];
        $outboxCount = DB::table('outbox_events')->count();
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $this->internal('GET', $path, [], $this->principal($role), 'gateway', $filters)->assertOk()
                ->assertJsonPath('data.asset.quantity', 38)->assertJsonPath('data.movement_total', 2)
                ->assertJsonPath('data.movements.0.reason', 'Entrega 50%_! final')
                ->assertJsonPath('data.movements.1.reason', 'Entrega 50%_! inicio')->assertJsonCount(2, 'data.movements');
        }
        $this->internal('GET', $path, [], $p, 'gateway', ['movement_to' => '2026-09-25', 'movement_type' => 'in', 'movement_q' => '50%_!'])
            ->assertJsonPath('data.movement_total', 3);
        $this->internal('GET', $path, [], $p, 'gateway', ['movement_from' => '2026-09-26', 'movement_type' => 'in', 'movement_q' => '50%_!'])
            ->assertJsonPath('data.movement_total', 1);
        $this->internal('GET', $path, [], $p, 'gateway', ['movement_q' => 'inexistente'])->assertJsonPath('data.movement_total', 0)->assertJsonCount(0, 'data.movements');
        $this->internal('GET', $path, [], $this->principal(org: self::OTHER), 'gateway', $filters)->assertNotFound();
        foreach (['viewer', 'registrar', 'auditor'] as $role) $this->internal('GET', $path, [], $this->principal($role), 'gateway', $filters)->assertForbidden();
        $this->internal('GET', $path, [], $p, 'calendar', $filters)->assertUnauthorized();
        foreach ([['movement_type' => 'unknown'], ['movement_q' => str_repeat('a', 121)],
            ['movement_from' => '2026-02-30'], ['movement_to' => '26/09/2026'],
            ['movement_from' => '2026-09-26', 'movement_to' => '2026-09-25']] as $invalid) {
            $this->internal('GET', $path, [], $p, 'gateway', $invalid)->assertUnprocessable();
        }
        $this->assertSame($outboxCount, DB::table('outbox_events')->count());
        $this->assertSame(7, DB::table('asset_movements')->where('asset_id', $asset['id'])->count());
    }

    public function test_asset_history_pages_preserve_oldest_movements_and_permissions(): void
    {
        $principal = $this->principal();
        $asset = $this->internal('POST', 'assets', $this->movable(), $principal)->assertOk()->json('data.asset');
        for ($i = 1; $i <= 101; $i++) {
            $this->internal('POST', 'assets/'.$asset['id'].'/movements', [
                'type' => 'in', 'quantity' => 1, 'reason' => 'Entrada de prueba '.$i,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            ], $principal)->assertOk();
        }
        $path = 'assets/'.$asset['id'];
        $this->internal('GET', $path, [], $principal)
            ->assertJsonPath('data.movement_total', 102)->assertJsonPath('data.movement_page', 1)
            ->assertJsonPath('data.movements.0.sequence', 102)->assertJsonCount(25, 'data.movements');
        $this->internal('GET', $path, [], $principal, 'gateway', ['movement_page' => 2])
            ->assertJsonPath('data.movement_total', 102)->assertJsonPath('data.movement_page', 2)
            ->assertJsonPath('data.movements.0.sequence', 77)->assertJsonCount(25, 'data.movements');
        $this->internal('GET', $path, [], $principal, 'gateway', ['movement_page' => 5])
            ->assertJsonPath('data.movement_total', 102)->assertJsonPath('data.movement_page', 5)
            ->assertJsonPath('data.movements.0.sequence', 2)->assertJsonPath('data.movements.1.sequence', 1)
            ->assertJsonCount(2, 'data.movements');
        $this->internal('GET', $path, [], $principal, 'gateway', ['movement_type' => 'in', 'movement_page' => 5])
            ->assertJsonPath('data.movement_total', 101)->assertJsonPath('data.movements.0.sequence', 2)
            ->assertJsonCount(1, 'data.movements');
        $this->internal('GET', $path, [], $principal, 'gateway', ['movement_type' => 'opening'])
            ->assertJsonPath('data.movement_total', 1)->assertJsonPath('data.movements.0.sequence', 1);
        $this->internal('GET', $path, [], $principal, 'gateway', ['movement_page' => 0])->assertUnprocessable();
        $this->internal('GET', $path, [], $this->principal('viewer'), 'gateway', ['movement_page' => 2])->assertForbidden();
        $this->internal('GET', $path, [], $this->principal(org: self::OTHER), 'gateway', ['movement_page' => 2])->assertNotFound();
    }
}
