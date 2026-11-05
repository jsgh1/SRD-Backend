<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private function principal(string $role = 'treasurer', string $org = '11111111-1111-4111-8111-111111111111'): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222',
            'role' => $role, 'name' => 'Tesorera de prueba'];
    }

    public function test_receipt_pdf_validates_name_scope_roles_and_records_only_minimal_audit(): void
    {
        $p = $this->principal();
        $receipt = $this->internal('POST', 'treasury/opening', [
            'amount' => '12.34', 'effective_date' => now('America/Bogota')->toDateString(),
            'concept' => 'CONCEPTO-PRIVADO-PDF', 'support_note' => 'SOPORTE-PRIVADO-PDF',
            'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ], $p)->assertOk()->json('data');
        $path = 'treasury/movements/'.$receipt['id'].'/pdf';
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $this->internal('GET', $path, [], $this->principal($role))->assertOk()
                ->assertJsonPath('data.filename', 'comprobante_TES-000001.pdf')
                ->assertJsonPath('data.receipt.id', $receipt['id'])
                ->assertJsonPath('data.receipt.amount', '12.34');
        }
        $this->internal('GET', $path, ['filename' => '../Reunión?.xlsx.pdf', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Reunion.pdf');
        $this->internal('GET', $path, ['filename' => 'CON.pdf', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'archivo-CON.pdf');
        foreach ([['filename' => 'Mi nombre'], ['filename' => '///', 'confirm_filename' => 1],
            ['filename' => str_repeat('a', 101), 'confirm_filename' => 1]] as $input) {
            $this->internal('GET', $path, $input, $p)->assertUnprocessable();
        }
        foreach (['viewer', 'registrar', 'auditor'] as $role) {
            $this->internal('GET', $path, [], $this->principal($role))->assertForbidden();
        }
        $this->internal('GET', $path, [], $this->principal(org: '33333333-3333-4333-8333-333333333333'))->assertNotFound();
        $this->internal('GET', $path, [], $p, 'calendar')->assertUnauthorized();
        $events = DB::table('outbox_events')->where('action', 'treasury.receipt_export')->get();
        $this->assertCount(5, $events);
        $this->assertSame($receipt['id'], $events[0]->resource_id);
        $this->assertStringNotContainsString('CONCEPTO-PRIVADO-PDF', json_encode($events));
        $this->assertStringNotContainsString('SOPORTE-PRIVADO-PDF', json_encode($events));
        $this->assertDatabaseCount('treasury_movements', 1);
    }

    public function test_individual_xlsx_keeps_exact_text_and_current_reversal_without_other_movements(): void
    {
        $p = $this->principal();
        $opening = $this->internal('POST', 'treasury/opening', [
            'amount' => '0.00', 'effective_date' => now('America/Bogota')->toDateString(),
            'concept' => 'APERTURA-EXCLUIDA', 'idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ], $p)->assertOk()->json('data');
        $movement = $this->internal('POST', 'treasury/movements', [
            'kind' => 'income', 'amount' => '123456789012.34', 'effective_date' => now('America/Bogota')->toDateString(),
            'concept' => '=SUM(A1) & < prueba', 'idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ], $p)->assertOk()->json('data');
        $reversal = $this->internal('POST', 'treasury/movements/'.$movement['id'].'/reverse', [
            'reason' => 'Corrección de prueba', 'idempotency_key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ], $p)->assertOk()->json('data');
        $path = 'treasury/movements/'.$movement['id'].'/xlsx';
        foreach (['superadmin', 'admin', 'treasurer'] as $role) {
            $response = $this->internal('GET', $path, [], $this->principal($role))->assertOk()
                ->assertJsonPath('data.filename', 'comprobante_TES-000002.xlsx')->assertJsonPath('data.count', 1);
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringContainsString('123456789012.34', $bytes);
            $this->assertStringContainsString('=SUM(A1) &amp; &lt; prueba', $bytes);
            $this->assertStringContainsString($reversal['id'], $bytes);
            $this->assertStringNotContainsString('APERTURA-EXCLUIDA', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
        }
        $this->internal('GET', $path, ['filename' => '../Reunión?.pdf.xlsx', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Reunion.xlsx');
        $english = $this->internal('GET', $path, ['lang' => 'en'], $p)->assertOk();
        $englishBytes = base64_decode($english->json('data.content'), true);
        $this->assertStringContainsString('Supporting reference', $englishBytes);
        $this->assertStringContainsString('Income', $englishBytes);
        $this->assertStringContainsString('name="Treasury"', $englishBytes);
        $this->assertStringContainsString('=SUM(A1) &amp; &lt; prueba', $englishBytes);
        $this->assertStringNotContainsString('Referencia del soporte', $englishBytes);
        $this->internal('GET', $path, ['lang' => 'fr'], $p)->assertUnprocessable();
        $this->internal('GET', $path, ['filename' => 'Mi archivo'], $p)->assertUnprocessable();
        foreach (['viewer', 'registrar', 'auditor'] as $role) $this->internal('GET', $path, [], $this->principal($role))->assertForbidden();
        $this->internal('GET', $path, [], $this->principal(org: '33333333-3333-4333-8333-333333333333'))->assertNotFound();
        $this->internal('GET', $path, [], $p, 'calendar')->assertUnauthorized();
        $this->assertDatabaseCount('treasury_movements', 3);
    }
}
