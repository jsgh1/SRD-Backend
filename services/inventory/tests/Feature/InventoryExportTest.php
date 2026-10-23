<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class InventoryExportTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'treasurer', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222',
            'role' => $role, 'name' => 'Tesorera de prueba'];
    }

    private function asset(string $code, string $name, string $key): array
    {
        return ['code' => $code, 'name' => $name, 'type' => 'movable', 'category' => 'Muebles',
            'unit' => 'unidad', 'location' => 'Salón', 'condition' => 'Bueno',
            'quantity' => 2, 'idempotency_key' => $key];
    }

    public function test_bilingual_asset_and_movement_text_persist_and_export_in_selected_language(): void
    {
        $p = $this->principal();
        $input = $this->asset('BILINGUAL-1', 'Sillas comunitarias', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa') + [
            'name_en' => 'Community chairs', 'category_en' => 'Furniture', 'unit_en' => 'chair',
            'description_en' => 'For shared events', 'location_en' => 'Community hall', 'condition_en' => 'Good',
        ];
        $input['description'] = 'Para eventos comunitarios';
        $asset = $this->internal('POST', 'assets', $input, $p)->assertOk()
            ->assertJsonPath('data.asset.name_en', 'Community chairs')->json('data.asset');
        $id = $asset['id'];
        $this->internal('GET', 'assets', ['q' => 'Community chairs'], $p)->assertOk()->assertJsonPath('data.total', 1);
        $this->internal('POST', "assets/$id/movements", [
            'type' => 'in', 'quantity' => 1, 'reason' => 'Ingreso por donación',
            'reason_en' => 'Donation received', 'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ], $p)->assertOk()->assertJsonPath('data.movement.reason_en', 'Donation received');
        $this->internal('GET', "assets/$id", ['movement_q' => 'Donation received'], $p)
            ->assertOk()->assertJsonPath('data.movement_total', 1);

        $en = $this->internal('GET', 'assets/export-pdf', ['q' => 'Community chairs', 'lang' => 'en'], $p)->assertOk();
        $en->assertJsonPath('data.rows.0.2', 'Community chairs')
            ->assertJsonPath('data.rows.0.3', 'Furniture')
            ->assertJsonPath('data.rows.0.4', 'chair')
            ->assertJsonPath('data.rows.0.6', 'Community hall')
            ->assertJsonPath('data.rows.0.7', 'Good')
            ->assertJsonPath('data.rows.0.10', 'For shared events');
        $es = $this->internal('GET', 'assets/export-pdf', ['q' => 'Community chairs', 'lang' => 'es'], $p)->assertOk();
        $es->assertJsonPath('data.rows.0.2', 'Sillas comunitarias')
            ->assertJsonPath('data.rows.0.10', 'Para eventos comunitarios');
        $history = $this->internal('GET', "assets/$id/movements/export-pdf", ['lang' => 'en', 'movement_type' => 'in'], $p)->assertOk();
        $history->assertJsonPath('data.rows.0.1', 'Community chairs')
            ->assertJsonPath('data.rows.0.8', 'Donation received');
        $opening = $this->internal('GET', "assets/$id/movements/export-pdf", ['lang' => 'en', 'movement_type' => 'opening'], $p)->assertOk();
        $opening->assertJsonPath('data.rows.0.8', 'Initial record');
        $xlsx = $this->internal('GET', 'assets/export', ['lang' => 'en'], $p)->assertOk();
        $this->assertStringContainsString('Community chairs', base64_decode($xlsx->json('data.content'), true));
        $this->assertStringNotContainsString('Sillas comunitarias', base64_decode($xlsx->json('data.content'), true));
    }

    public function test_export_filters_tenant_and_roles_and_keeps_cells_as_text(): void
    {
        $p = $this->principal();
        $wanted = $this->internal('POST', 'assets', $this->asset('COD-001', '=2+2 & < 50%_!',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $p)->assertOk()->json('data.asset');
        $this->internal('POST', 'assets', $this->asset('COD-002', 'Otro 50ABC!',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $p)->assertOk();
        $this->internal('POST', 'assets', $this->asset('COD-003', '=2+2 & < 50%_!',
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc'), $this->principal(org: self::OTHER))->assertOk();
        $filters = ['type' => 'movable', 'status' => 'active', 'q' => '50%_!'];
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $response = $this->internal('GET', 'assets/export', $filters, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.mime', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->assertSame('inventario_'.now('America/Bogota')->format('Y-m-d').'.xlsx', $response->json('data.filename'));
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringContainsString($wanted['id'], $bytes);
            $this->assertStringContainsString('=2+2 &amp; &lt; 50%_!', $bytes);
            $this->assertStringContainsString('t="inlineStr"', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
            $this->assertStringNotContainsString('COD-002', $bytes);
            $this->assertStringNotContainsString('COD-003', $bytes);
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) {
            $this->internal('GET', 'assets/export', $filters, $this->principal($role))->assertForbidden();
        }
        $pdf = $this->internal('GET', 'assets/export-pdf', $filters + ['lang' => 'en'], $p)->assertOk();
        $this->assertSame('Quantity on hand', $pdf->json('data.headers.5'));
        $this->assertSame('Movable asset', $pdf->json('data.rows.0.1'));
        $this->assertSame('=2+2 & < 50%_!', $pdf->json('data.rows.0.2'));
        $this->internal('GET', 'assets/export-pdf', $filters + ['lang' => 'fr'], $p)->assertUnprocessable();
        $english = $this->internal('GET', 'assets/export', $filters + ['lang' => 'en'], $p)->assertOk();
        $englishBytes = base64_decode($english->json('data.content'), true);
        $this->assertStringContainsString('Quantity on hand', $englishBytes);
        $this->assertStringContainsString('Movable asset', $englishBytes);
        $this->assertStringContainsString('name="Inventory"', $englishBytes);
        $this->assertStringContainsString('=2+2 &amp; &lt; 50%_!', $englishBytes);
        $this->assertStringNotContainsString('Existencia', $englishBytes);
        $this->internal('GET', 'assets/export', $filters + ['lang' => 'fr'], $p)->assertUnprocessable();
        $this->internal('GET', 'assets/export', $filters, $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', 'assets/export', [], $this->principal(org: self::OTHER))->assertJsonPath('data.count', 1);
        $this->internal('GET', 'assets/export', ['type' => 'unknown'], $p)->assertUnprocessable();
        $this->internal('GET', 'assets/export', ['q' => str_repeat('x', 121)], $p)->assertUnprocessable();
    }

    public function test_movement_export_reuses_filters_preserves_stock_and_enforces_access(): void
    {
        $p = $this->principal();
        $asset = $this->internal('POST', 'assets', $this->asset('MOV-001', '=Bien & <privado>',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $p)->assertOk()->json('data.asset');
        $id = $asset['id'];
        $this->internal('POST', "assets/$id/movements", ['type' => 'in', 'quantity' => 3,
            'reason' => '=SUM(1) & <50%_!>', 'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], $p)->assertOk();
        DB::table('asset_movements')->where('asset_id', $id)->where('type', 'in')->update(['created_at' => '2026-09-26 05:00:00']);
        $filters = ['movement_type' => 'in', 'movement_q' => '50%_!', 'movement_from' => '2026-09-26', 'movement_to' => '2026-09-26'];
        $before = DB::table('assets')->where('id', $id)->first();
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $response = $this->internal('GET', "assets/$id/movements/export", $filters, $this->principal($role))->assertOk()->assertJsonPath('data.count', 1);
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringContainsString('=SUM(1) &amp; &lt;50%_!&gt;', $bytes);
            $this->assertStringContainsString('Existencia anterior', $bytes);
            $this->assertStringContainsString('t="inlineStr"', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
            $this->assertStringNotContainsString('Registro inicial', $bytes);
            $this->assertSame('movimientos_inventario_'.now('America/Bogota')->format('Y-m-d').'.xlsx', $response->json('data.filename'));
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) $this->internal('GET', "assets/$id/movements/export", [], $this->principal($role))->assertForbidden();
        $english = $this->internal('GET', "assets/$id/movements/export", $filters + ['lang' => 'en'], $p)->assertOk();
        $englishBytes = base64_decode($english->json('data.content'), true);
        $this->assertStringContainsString('Previous quantity', $englishBytes);
        $this->assertStringContainsString('Stock in', $englishBytes);
        $this->assertStringContainsString('name="Inventory"', $englishBytes);
        $this->assertStringContainsString('=SUM(1) &amp; &lt;50%_!&gt;', $englishBytes);
        $this->assertStringNotContainsString('Existencia anterior', $englishBytes);
        $this->internal('GET', "assets/$id/movements/export", $filters + ['lang' => 'fr'], $p)->assertUnprocessable();
        $englishPdf = $this->internal('GET', "assets/$id/movements/export-pdf", $filters + ['lang' => 'en'], $p)->assertOk();
        $this->assertSame('Previous quantity', $englishPdf->json('data.headers.6'));
        $this->assertSame('Stock in', $englishPdf->json('data.rows.0.4'));
        $this->assertSame('=SUM(1) & <50%_!>', $englishPdf->json('data.rows.0.8'));
        $this->internal('GET', "assets/$id/movements/export-pdf", $filters + ['lang' => 'fr'], $p)->assertUnprocessable();
        $this->internal('GET', "assets/$id/movements/export", [], $this->principal(org: self::OTHER))->assertNotFound();
        $this->internal('GET', "assets/$id/movements/export", [], $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', "assets/$id/movements/export", ['filename' => 'Historia'], $p)->assertUnprocessable();
        $this->internal('GET', "assets/$id/movements/export", $filters + ['filename' => '../Historia?.xlsx', 'confirm_filename' => 1], $p)->assertOk()->assertJsonPath('data.filename', 'Historia.xlsx');
        $this->internal('GET', "assets/$id/movements/export", ['movement_q' => 'ausente'], $p)->assertOk()->assertJsonPath('data.count', 0);
        $this->internal('GET', "assets/$id/movements/export", ['movement_from' => '2026-09-27', 'movement_to' => '2026-09-26'], $p)->assertUnprocessable();
        $this->internal('GET', "assets/$id/movements/export", ['movement_q' => str_repeat('x', 121)], $p)->assertUnprocessable();
        $this->assertEquals($before, DB::table('assets')->where('id', $id)->first());
        $this->assertSame(2, DB::table('asset_movements')->where('asset_id', $id)->count());
        $audit = DB::table('outbox_events')->where('action', 'inventory.export')->get();
        $this->assertCount(7, $audit);
        $this->assertStringNotContainsString('SUM', json_encode($audit));
    }

    public function test_movement_export_includes_all_pages_and_refuses_truncation(): void
    {
        $p = $this->principal();
        $id = $this->internal('POST', 'assets', $this->asset('MOV-001', 'Bien de prueba',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $p)->assertOk()->json('data.asset.id');
        $template = (array) DB::table('asset_movements')->where('asset_id', $id)->first();
        $rows = [];
        for ($seq = 2; $seq <= 2001; $seq++) $rows[] = array_replace($template, [
            'id' => (string) \Illuminate\Support\Str::uuid(), 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'sequence' => $seq, 'type' => 'in', 'reason' => "Fila $seq",
        ]);
        foreach (array_chunk($rows, 100) as $chunk) DB::table('asset_movements')->insert($chunk);
        $this->internal('GET', "assets/$id/movements/export", [], $p)->assertUnprocessable();
        $this->assertSame(0, DB::table('outbox_events')->where('action', 'inventory.export')->count());
        $response = $this->internal('GET', "assets/$id/movements/export", ['movement_type' => 'in', 'movement_page' => 2], $p)->assertOk()->assertJsonPath('data.count', 2000);
        $bytes = base64_decode($response->json('data.content'), true);
        $this->assertStringContainsString('Fila 2', $bytes);
        $this->assertStringContainsString('Fila 2001', $bytes);
        $this->assertSame(2001, substr_count($bytes, '<row r='));
        $this->assertLessThan(strpos($bytes, 'Fila 2<'), strpos($bytes, 'Fila 2001<'));
        $this->internal('GET', "assets/$id/movements/export-pdf", [], $p)->assertUnprocessable();
        $pdf = $this->internal('GET', "assets/$id/movements/export-pdf", ['movement_type' => 'in', 'movement_page' => 2], $p)->assertOk()->assertJsonPath('data.count', 2000)->assertJsonCount(2000, 'data.rows');
        $this->assertSame('2001', $pdf->json('data.rows.0.2'));
        $this->assertSame('2', $pdf->json('data.rows.1999.2'));
    }

    public function test_movement_pdf_filters_names_permissions_and_minimal_audit(): void
    {
        $p = $this->principal();
        $id = $this->internal('POST', 'assets', $this->asset('PDF-HIST', 'Bien & <prueba>',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $p)->assertOk()->json('data.asset.id');
        $this->internal('POST', "assets/$id/movements", ['type' => 'in', 'quantity' => 3,
            'reason' => 'Motivo literal 50%_! <privado>', 'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], $p)->assertOk();
        $before = DB::table('assets')->where('id', $id)->first();
        $filters = ['movement_type' => 'in', 'movement_q' => '50%_!'];
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $response = $this->internal('GET', "assets/$id/movements/export-pdf", $filters, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)->assertJsonCount(11, 'data.headers')->assertJsonCount(11, 'data.rows.0')
                ->assertJsonPath('data.rows.0.6', '2')->assertJsonPath('data.rows.0.7', '5')
                ->assertJsonPath('data.rows.0.8', 'Motivo literal 50%_! <privado>');
            $this->assertSame('movimientos_inventario_'.now('America/Bogota')->format('Y-m-d').'.pdf', $response->json('data.filename'));
            $this->assertSame(now('America/Bogota')->toDateString(), $response->json('data.date'));
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) $this->internal('GET', "assets/$id/movements/export-pdf", [], $this->principal($role))->assertForbidden();
        $this->internal('GET', "assets/$id/movements/export-pdf", [], $this->principal(org: self::OTHER))->assertNotFound();
        $this->internal('GET', "assets/$id/movements/export-pdf", [], $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', "assets/$id/movements/export-pdf", ['filename' => 'Historia'], $p)->assertUnprocessable();
        $this->internal('GET', "assets/$id/movements/export-pdf", $filters + ['filename' => '../Historia?.xlsx.pdf', 'confirm_filename' => 1], $p)->assertOk()->assertJsonPath('data.filename', 'Historia.pdf');
        $this->internal('GET', "assets/$id/movements/export-pdf", ['movement_q' => 'sin coincidencias'], $p)->assertOk()->assertJsonPath('data.count', 0)->assertJsonCount(0, 'data.rows');
        $this->internal('GET', "assets/$id/movements/export-pdf", ['movement_from' => '2026-09-27', 'movement_to' => '2026-09-26'], $p)->assertUnprocessable();
        $this->internal('GET', "assets/$id/movements/export-pdf", ['movement_type' => 'unknown'], $p)->assertUnprocessable();
        $this->assertEquals($before, DB::table('assets')->where('id', $id)->first());
        $this->assertSame(2, DB::table('asset_movements')->where('asset_id', $id)->count());
        $audit = DB::table('outbox_events')->where('action', 'inventory.export')->get();
        $this->assertCount(5, $audit);
        $this->assertStringNotContainsString('privado', json_encode($audit));
    }

    public function test_pdf_reuses_filters_roles_safe_names_and_minimal_audit(): void
    {
        $p = $this->principal();
        $input = $this->asset('000001', '=2+2 & < 50%_!', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $input['description'] = 'DESCRIPCION-PRIVADA-PDF';
        $wanted = $this->internal('POST', 'assets', $input, $p)->assertOk()->json('data.asset');
        $this->internal('POST', 'assets', $this->asset('000002', 'Otro 50ABC!', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $p)->assertOk();
        $this->internal('POST', 'assets', $this->asset('000003', '=2+2 & < 50%_!', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'), $this->principal(org:self::OTHER))->assertOk();
        foreach (['superadmin','admin','treasurer'] as $role) {
            $response = $this->internal('GET', 'assets/export-pdf', ['q'=>'50%_!','type'=>'movable','status'=>'active','page'=>2], $this->principal($role))
                ->assertOk()->assertJsonPath('data.count',1)->assertJsonCount(14,'data.headers')->assertJsonCount(1,'data.rows')
                ->assertJsonPath('data.filename','inventario_'.now('America/Bogota')->toDateString().'.pdf')
                ->assertJsonPath('data.rows.0.0','000001')->assertJsonPath('data.rows.0.2','=2+2 & < 50%_!')
                ->assertJsonPath('data.rows.0.10','DESCRIPCION-PRIVADA-PDF')->assertJsonPath('data.rows.0.13',$wanted['id']);
            $this->assertStringNotContainsString('000002',$response->getContent());
            $this->assertStringNotContainsString('000003',$response->getContent());
        }
        foreach (['viewer','registrar','auditor'] as $role) $this->internal('GET','assets/export-pdf',[],$this->principal($role))->assertForbidden();
        $this->internal('GET','assets/export-pdf',[],$p,'calendar')->assertUnauthorized();
        $this->internal('GET','assets/export-pdf',['filename'=>'Lista'], $p)->assertUnprocessable();
        $this->internal('GET','assets/export-pdf',['filename'=>'../Lista?.xlsx.pdf','confirm_filename'=>1],$p)->assertOk()->assertJsonPath('data.filename','Lista.pdf');
        $this->internal('GET','assets/export-pdf',['filename'=>'///','confirm_filename'=>1],$p)->assertUnprocessable();
        $this->internal('GET','assets/export-pdf',['type'=>'unknown'],$p)->assertUnprocessable();
        $this->internal('GET','assets/export-pdf',['q'=>'no-match'],$p)->assertOk()->assertJsonPath('data.count',0)->assertJsonCount(0,'data.rows');
        $events = DB::table('outbox_events')->where('action','inventory.export')->get();
        $this->assertCount(5,$events);
        $this->assertStringNotContainsString('DESCRIPCION-PRIVADA-PDF',json_encode($events));
        $this->assertStringNotContainsString('50%_!',json_encode($events));
    }

    public function test_export_filename_requires_confirmation_and_is_sanitized(): void
    {
        $p = $this->principal();
        $this->internal('GET', 'assets/export', ['filename' => '../Lista?.xlsx'], $p)->assertUnprocessable();
        $this->internal('GET', 'assets/export', ['filename' => '../Lista?.xlsx', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Lista.xlsx');
        $this->internal('GET', 'assets/export', ['filename' => '...', 'confirm_filename' => 1], $p)->assertUnprocessable();
    }

    public function test_export_rejects_more_than_2000_assets_without_truncation(): void
    {
        $p = $this->principal();
        for ($start = 1; $start <= 2001; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, 2002); $i++) {
                $rows[] = [
                    'id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i), 'organization_id' => self::ORG,
                    'code' => sprintf('ITEM-%04d', $i), 'type' => 'movable', 'name' => 'Bien de prueba',
                    'category' => 'Muebles', 'unit' => 'unidad', 'location' => 'Salón',
                    'condition' => 'Bueno', 'quantity' => 1, 'status' => 'active',
                    'version' => 1, 'created_by' => $p['user_id'], 'updated_by' => $p['user_id'],
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('assets')->insert($rows);
        }
        $this->internal('GET', 'assets/export', [], $p)->assertUnprocessable();
        $this->internal('GET', 'assets/export-pdf', [], $p)->assertUnprocessable();
        DB::table('assets')->where('code', 'ITEM-2001')->delete();
        $this->internal('GET', 'assets/export', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
        $this->internal('GET', 'assets/export-pdf', [], $p)->assertOk()->assertJsonPath('data.count', 2000)->assertJsonCount(2000, 'data.rows');
    }
}
