<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class TreasuryExportTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'treasurer', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222',
            'role' => $role, 'name' => 'Tesorera de prueba'];
    }

    private function opening(array $principal): void
    {
        $this->internal('POST', 'treasury/opening', [
            'amount' => '100.00', 'effective_date' => '2026-01-01', 'concept' => 'Apertura de prueba',
            'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ], $principal)->assertOk();
    }

    public function test_export_uses_history_filters_tenant_permissions_and_text_cells(): void
    {
        $p = $this->principal();
        $this->opening($p);
        $wanted = $this->internal('POST', 'treasury/movements', [
            'kind' => 'income', 'amount' => '12.34', 'effective_date' => '2026-01-02',
            'concept' => '=2+2 & < 50%_!', 'support_note' => 'Referencia <A>',
            'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ], $p)->assertOk()->json('data');
        $this->internal('POST', 'treasury/movements', [
            'kind' => 'expense', 'amount' => '1.00', 'effective_date' => '2026-01-03',
            'concept' => 'Otro 50ABC!', 'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ], $p)->assertOk();
        $this->opening($this->principal(org: self::OTHER));
        $filters = ['from' => '2026-01-02', 'to' => '2026-01-03', 'q' => '50%_!'];
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $response = $this->internal('GET', 'treasury/export', $filters, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.mime', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->assertSame('tesoreria_'.now('America/Bogota')->format('Y-m-d').'.xlsx', $response->json('data.filename'));
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringContainsString($wanted['receipt'], $bytes);
            $this->assertStringContainsString('=2+2 &amp; &lt; 50%_!', $bytes);
            $this->assertStringContainsString('Referencia &lt;A&gt;', $bytes);
            $this->assertStringContainsString('12.34', $bytes);
            $this->assertStringContainsString('t="inlineStr"', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
            $this->assertStringNotContainsString('Otro 50ABC!', $bytes);
            $this->assertStringNotContainsString('Apertura de prueba', $bytes);
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) {
            $this->internal('GET', 'treasury/export', $filters, $this->principal($role))->assertForbidden();
        }
        $english = $this->internal('GET', 'treasury/export', $filters + ['lang' => 'en'], $p)->assertOk();
        $englishBytes = base64_decode($english->json('data.content'), true);
        $this->assertStringContainsString('Effective date', $englishBytes);
        $this->assertStringContainsString('Income', $englishBytes);
        $this->assertStringContainsString('name="Treasury"', $englishBytes);
        $this->assertStringContainsString('=2+2 &amp; &lt; 50%_!', $englishBytes);
        $this->assertStringNotContainsString('Fecha efectiva', $englishBytes);
        $this->internal('GET', 'treasury/export', $filters + ['lang' => 'fr'], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export', $filters, $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', 'treasury/export', [], $this->principal(org: self::OTHER))->assertJsonPath('data.count', 1);
        $this->internal('GET', 'treasury/export', ['from' => '2026-01-04', 'to' => '2026-01-03'], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export', ['q' => str_repeat('x', 121)], $p)->assertUnprocessable();
    }

    public function test_export_filename_requires_confirmation_and_is_sanitized(): void
    {
        $p = $this->principal();
        $this->internal('GET', 'treasury/export', ['filename' => 'Informe/Comunal?.xlsx'], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export', ['filename' => 'Informe/Comunal?.xlsx', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Informe-Comunal.xlsx');
        $this->internal('GET', 'treasury/export', ['filename' => 'CON', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'archivo-CON.xlsx');
        $this->internal('GET', 'treasury/export', ['filename' => '///', 'confirm_filename' => 1], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export', ['filename' => str_repeat('a', 101), 'confirm_filename' => 1], $p)->assertUnprocessable();
    }

    public function test_pdf_reuses_filtered_text_rows_permissions_names_and_minimal_audit(): void
    {
        $p = $this->principal();
        $this->opening($p);
        $wanted = $this->internal('POST', 'treasury/movements', [
            'kind' => 'income', 'amount' => '12.34', 'effective_date' => '2026-01-02',
            'concept' => '=2+2 & < 50%_!', 'support_note' => 'Soporte privado',
            'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ], $p)->assertOk()->json('data');
        $this->internal('POST', 'treasury/movements', [
            'kind' => 'expense', 'amount' => '1.00', 'effective_date' => '2026-01-03',
            'concept' => 'Otro 50ABC!', 'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ], $p)->assertOk();
        $this->opening($this->principal(org: self::OTHER));
        $filters = ['from' => '2026-01-02', 'to' => '2026-01-03', 'q' => '50%_!', 'page' => 2];
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $data = $this->internal('GET', 'treasury/export-pdf', $filters, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)->json('data');
            $this->assertSame('tesoreria_'.now('America/Bogota')->format('Y-m-d').'.pdf', $data['filename']);
            $this->assertSame(now('America/Bogota')->toDateString(), $data['date']);
            $this->assertCount(12, $data['headers']);
            $this->assertSame([$wanted['receipt'], '2026-01-02', 'Ingreso', '+', '12.34', '112.34',
                '=2+2 & < 50%_!', 'Soporte privado', $p['name'], '', $data['rows'][0][10], $wanted['id']], $data['rows'][0]);
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) {
            $this->internal('GET', 'treasury/export-pdf', [], $this->principal($role))->assertForbidden();
        }
        $this->internal('GET', 'treasury/export-pdf', [], $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', 'treasury/export-pdf', ['filename' => 'Informe.pdf'], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export-pdf', ['filename' => '../Informe?.xlsx.pdf', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Informe.pdf');
        $this->internal('GET', 'treasury/export-pdf', ['filename' => '///', 'confirm_filename' => 1], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export-pdf', ['from' => '2026-01-04', 'to' => '2026-01-03'], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export-pdf', ['q' => str_repeat('x', 121)], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export-pdf', ['q' => 'Sin coincidencias'], $p)
            ->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.count', 0);
        $events = DB::table('outbox_events')->where('action', 'treasury.export')->get();
        $this->assertCount(5, $events);
        foreach ($events as $event) {
            $serialized = json_encode($event, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Soporte privado', $serialized);
            $this->assertStringNotContainsString('50%_!', $serialized);
            $this->assertStringNotContainsString('112.34', $serialized);
            $this->assertStringNotContainsString('Informe', $serialized);
        }
    }

    public function test_export_rejects_more_than_2000_movements_without_truncation(): void
    {
        $p = $this->principal();
        DB::table('treasury_accounts')->insert([
            'organization_id' => self::ORG, 'balance_cents' => 0, 'next_number' => 2002,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($start = 1; $start <= 2001; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, 2002); $i++) {
                $rows[] = [
                    'id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i), 'organization_id' => self::ORG,
                    'number' => $i, 'kind' => 'income', 'sign' => 1, 'amount_cents' => 100,
                    'balance_after_cents' => $i * 100, 'effective_date' => '2026-01-01',
                    'concept' => 'Asiento de prueba', 'actor_id' => $p['user_id'], 'actor_name' => $p['name'],
                    'idempotency_key' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $i),
                    'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('treasury_movements')->insert($rows);
        }
        $this->internal('GET', 'treasury/export', [], $p)->assertUnprocessable();
        $this->internal('GET', 'treasury/export-pdf', [], $p)->assertUnprocessable();
        DB::table('treasury_movements')->where('number', 2001)->delete();
        $this->internal('GET', 'treasury/export', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
        $this->internal('GET', 'treasury/export-pdf', [], $p)->assertOk()->assertJsonPath('data.count', 2000)->assertJsonCount(2000, 'data.rows');
    }
}
