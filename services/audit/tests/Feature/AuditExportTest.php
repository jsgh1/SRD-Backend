<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class AuditExportTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $principal = [
        'organization_id' => '11111111-1111-4111-8111-111111111111',
        'user_id' => '22222222-2222-4222-8222-222222222222',
        'role' => 'auditor',
    ];

    private function event(int $number, array $changes = []): array
    {
        return array_replace([
            'id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number),
            'organization_id' => $this->principal['organization_id'],
            'actor_id' => $this->principal['user_id'],
            'service' => 'records', 'action' => '=2+2&<', 'resource_id' => null,
            'result' => 'success', 'correlation_id' => '33333333-3333-4333-8333-333333333333',
            'occurred_at' => '2026-09-10 00:00:00', 'event_version' => 1,
        ], $changes);
    }

    public function test_english_exports_translate_presentation_but_keep_action_and_identifiers(): void
    {
        $event = $this->event(1);
        DB::table('audit_events')->insert($event);
        $pdf = $this->internal('GET', 'events/export-pdf', ['lang' => 'en'], $this->principal)
            ->assertOk()->assertJsonPath('data.headers.0', 'Date UTC')
            ->assertJsonPath('data.headers.1', 'Module')
            ->assertJsonPath('data.rows.0.1', 'Records')
            ->assertJsonPath('data.rows.0.2', '=2+2&<')
            ->assertJsonPath('data.rows.0.3', 'Success');
        $this->assertSame($event['id'], $pdf->json('data.rows.0.6'));
        $xlsx = $this->internal('GET', 'events/export', ['lang' => 'en'], $this->principal)->assertOk();
        $bytes = base64_decode($xlsx->json('data.content'), true);
        $this->assertStringContainsString('name="Audit"', $bytes);
        $this->assertStringContainsString('Date UTC', $bytes);
        $this->assertStringContainsString('Records', $bytes);
        $this->assertStringContainsString('Success', $bytes);
        $this->assertStringContainsString('=2+2&amp;&lt;', $bytes);
        $this->assertStringNotContainsString('<f>', $bytes);
        $this->internal('GET', 'events/export', ['lang' => 'fr'], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'events/export-pdf', ['lang' => 'fr'], $this->principal)->assertUnprocessable();
        $spanish = $this->internal('GET', 'events/export-pdf', ['lang' => 'es'], $this->principal)->assertOk();
        $spanish->assertJsonPath('data.headers.0', 'Fecha UTC')->assertJsonPath('data.rows.0.1', 'Registros')
            ->assertJsonPath('data.rows.0.3', 'Correcto');
    }

    public function test_export_respects_filters_scope_roles_and_keeps_actions_as_text(): void
    {
        $first = $this->event(1);
        $last = $this->event(2, ['occurred_at' => '2026-09-10 23:59:59.999999']);
        $otherDay = $this->event(3, ['occurred_at' => '2026-09-11 00:00:00']);
        $otherService = $this->event(4, ['service' => 'files']);
        $otherOrg = $this->event(5, ['organization_id' => '44444444-4444-4444-8444-444444444444']);
        DB::table('audit_events')->insert([$first, $last, $otherDay, $otherService, $otherOrg]);

        $filters = ['date_from' => '2026-09-10', 'date_to' => '2026-09-10', 'service' => 'records', 'action' => '=2+2&<', 'result' => 'success', 'actor_id' => $this->principal['user_id'], 'organization_id' => $otherOrg['organization_id']];
        foreach (['superadmin', 'admin', 'auditor'] as $role) {
            $response = $this->internal('GET', 'events/export', $filters, array_replace($this->principal, ['role' => $role]))
                ->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.mime', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringContainsString('<row r="2">', $bytes);
            $this->assertStringContainsString('<row r="3">', $bytes);
            $this->assertStringNotContainsString('<row r="4">', $bytes);
            $this->assertStringContainsString($first['id'], $bytes);
            $this->assertStringContainsString($last['id'], $bytes);
            $this->assertStringNotContainsString($otherDay['id'], $bytes);
            $this->assertStringNotContainsString($otherOrg['id'], $bytes);
            $this->assertStringContainsString('=2+2&amp;&lt;', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
            $pdf = $this->internal('GET', 'events/export-pdf', $filters + ['page' => 2], array_replace($this->principal, ['role' => $role]))
                ->assertOk()->assertJsonPath('data.count', 2)->json('data');
            $this->assertSame('auditoria_'.now('America/Bogota')->format('Y-m-d').'.pdf', $pdf['filename']);
            $this->assertSame(now('America/Bogota')->toDateString(), $pdf['date']);
            $this->assertCount(8, $pdf['headers']);
            $this->assertSame([$last['id'], $first['id']], array_column($pdf['rows'], 6));
            $this->assertSame('=2+2&<', $pdf['rows'][0][2]);
            $this->assertSame('', $pdf['rows'][0][5]);
            $this->assertStringNotContainsString($otherOrg['id'], json_encode($pdf));
        }
        foreach (['registrar', 'treasurer', 'viewer'] as $role) {
            $this->internal('GET', 'events/export', $filters, array_replace($this->principal, ['role' => $role]))->assertForbidden();
            $this->internal('GET', 'events/export-pdf', $filters, array_replace($this->principal, ['role' => $role]))->assertForbidden();
        }
        $this->internal('GET', 'events/export', $filters, $this->principal, 'identity')->assertForbidden();
        $this->internal('GET', 'events/export-pdf', $filters, $this->principal, 'identity')->assertForbidden();
        $this->internal('GET', 'events/export', [], array_replace($this->principal, ['organization_id' => $otherOrg['organization_id']]))
            ->assertOk()->assertJsonPath('data.count', 1);
        foreach ([['date_from' => '2026-02-30'], ['date_from' => '2026-09-11', 'date_to' => '2026-09-10'], ['service' => 'unknown'], ['actor_id' => 'bad']] as $invalid) {
            $this->internal('GET', 'events/export', $invalid, $this->principal)->assertUnprocessable();
            $this->internal('GET', 'events/export-pdf', $invalid, $this->principal)->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_events', 5);
    }

    public function test_export_has_an_explicit_2000_row_limit_without_truncation(): void
    {
        for ($start = 1; $start <= 2001; $start += 100) {
            $rows = [];
            for ($number = $start; $number < min($start + 100, 2002); $number++) $rows[] = $this->event($number);
            DB::table('audit_events')->insert($rows);
        }
        $this->internal('GET', 'events/export', [], $this->principal)->assertUnprocessable()->assertJsonPath('error.code', 'HTTP_422');
        $this->internal('GET', 'events/export-pdf', [], $this->principal)->assertUnprocessable();
        DB::table('audit_events')->where('id', $this->event(2001)['id'])->delete();
        $this->internal('GET', 'events/export', [], $this->principal)->assertOk()->assertJsonPath('data.count', 2000);
        $this->internal('GET', 'events/export-pdf', [], $this->principal)->assertOk()->assertJsonPath('data.count', 2000)->assertJsonCount(2000, 'data.rows');
    }

    public function test_export_filename_requires_confirmation_and_is_sanitized(): void
    {
        $this->internal('GET', 'events/export', [], $this->principal)
            ->assertOk()->assertJsonPath('data.filename', 'auditoria_'.now('America/Bogota')->format('Y-m-d').'.xlsx');
        $this->internal('GET', 'events/export', ['filename' => 'Acta/Prueba?.xlsx'], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'events/export', ['filename' => 'Acta/Prueba?.xlsx', 'confirm_filename' => 1], $this->principal)
            ->assertOk()->assertJsonPath('data.filename', 'Acta-Prueba.xlsx');
        $this->internal('GET', 'events/export', ['filename' => '///', 'confirm_filename' => 1], $this->principal)->assertUnprocessable();
    }

    public function test_pdf_name_validation_and_empty_results_do_not_write_audit_events(): void
    {
        $this->internal('GET', 'events/export-pdf', ['filename' => 'Acta/Prueba?.xlsx.pdf'], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'events/export-pdf', ['filename' => 'Acta/Prueba?.xlsx.pdf', 'confirm_filename' => 1], $this->principal)
            ->assertOk()->assertJsonPath('data.filename', 'Acta-Prueba.pdf')->assertJsonPath('data.rows', [])->assertJsonPath('data.count', 0);
        $this->internal('GET', 'events/export-pdf', ['filename' => '///', 'confirm_filename' => 1], $this->principal)->assertUnprocessable();
        $this->internal('GET', 'events/export-pdf', ['filename' => 'CON', 'confirm_filename' => 1], $this->principal)
            ->assertOk()->assertJsonPath('data.filename', 'archivo-CON.pdf');
        $this->assertDatabaseCount('audit_events', 0);
    }
}
