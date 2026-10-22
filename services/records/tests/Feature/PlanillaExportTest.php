<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PlanillaExportTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'admin', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => $role];
    }

    private function create(string $document, string $name, array $p): void
    {
        $this->internal('POST', 'persons', [
            'document_type' => 'CC', 'document_number' => $document, 'first_names' => $name,
            'status' => 'pending', 'note' => 'NOTA-PRIVADA-PLANILLA',
            'authorization_basis' => 'Prueba local', 'authorization_purpose' => 'Planilla',
        ], $p)->assertOk();
    }

    private function zipFiles(string $bytes): array
    {
        $files = [];
        $offset = 0;
        while (substr($bytes, $offset, 4) === "PK\x03\x04") {
            $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($bytes, $offset, 30));
            $this->assertSame(0, $header['method']);
            $name = substr($bytes, $offset + 30, $header['name_length']);
            $files[$name] = substr($bytes, $offset + 30 + $header['name_length'] + $header['extra_length'], $header['compressed']);
            $offset += 30 + $header['name_length'] + $header['extra_length'] + $header['compressed'];
        }
        return $files;
    }

    public function test_english_sheet_preview_and_pdf_data_use_bilingual_headings_and_position(): void
    {
        $p = $this->principal();
        $this->internal('PUT', 'planilla-settings', [
            'version' => 0, 'allowed_columns' => ['position_label', 'status'],
            'h1' => 'JUNTA DE PRUEBA', 'h2' => 'Sector norte', 'h3' => 'FIRMAS',
            'h1_en' => 'TEST COUNCIL', 'h2_en' => 'North area', 'h3_en' => 'SIGNATURES',
        ], $p)->assertOk();
        $this->internal('POST', 'persons', [
            'document_type' => 'CC', 'document_number' => 'ENGLISH-SHEET-1',
            'first_names' => 'Synthetic person', 'status' => 'pending', 'position_code' => 'president',
            'authorization_basis' => 'Local test', 'authorization_purpose' => 'English sheet',
        ], $p)->assertOk();
        $params = ['language' => 'en', 'columns' => ['position_label', 'status']];
        $preview = $this->internal('GET', 'persons/planilla-preview', $params, $p)->assertOk();
        $preview->assertJsonPath('data.language', 'en')->assertJsonPath('data.headings.h1', 'TEST COUNCIL')
            ->assertJsonPath('data.headings.h2', 'North area')->assertJsonPath('data.headers.4', 'Position')
            ->assertJsonPath('data.headers.5', 'Record status')->assertJsonPath('data.headers.6', 'Signature')
            ->assertJsonPath('data.rows.0.4', 'President')->assertJsonPath('data.rows.0.5', 'Pending');
        $this->internal('GET', 'persons/planilla-pdf', $params, $p)->assertOk()
            ->assertJsonPath('data.language', 'en')->assertJsonPath('data.rows.0.4', 'President');
        $bytes = base64_decode($this->internal('GET', 'persons/planilla', $params, $p)->assertOk()->json('data.content'), true);
        $this->assertIsString($bytes);
        foreach (['TEST COUNCIL', 'North area', 'SIGNATURES', 'MONTH:', 'Full name', 'Position', 'President', 'Pending', 'PRESIDENT', 'SECRETARY'] as $text) {
            $this->assertStringContainsString($text, $bytes);
        }
        $this->assertStringNotContainsString('JUNTA DE PRUEBA', $bytes);
        $this->internal('GET', 'persons/planilla', ['language' => 'fr'], $p)->assertUnprocessable();
    }

    public function test_planilla_has_five_fixed_columns_and_empty_last_signature(): void
    {
        $this->create('00000012', '=1+1 & < prueba', $this->principal());
        $this->create('00000013', 'Excluida', $this->principal());
        $this->create('00000014', '=1+1 & < prueba', $this->principal(org: self::OTHER));
        $params = ['q' => '=1+1', 'columns' => ['email', 'zone', 'status', 'phone', 'position_label'],
            'h1' => 'JUNTA & COMUNIDAD', 'h2' => 'Sector <norte>', 'h3' => 'ASISTENCIA',
            'filename' => 'Mi/Planilla.xlsx', 'confirm_filename' => 1];
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/planilla', $params, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.filename', 'Mi-Planilla.xlsx');
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);
            if ($role === Access::ROLES[0]) {
                $files = $this->zipFiles($bytes);
                $this->assertCount(5, $files);
                foreach ($files as $contents) $this->assertNotFalse(simplexml_load_string($contents));
            }
            $this->assertStringContainsString('JUNTA &amp; COMUNIDAD', $bytes);
            $this->assertStringContainsString('Sector &lt;norte&gt;', $bytes);
            $this->assertStringContainsString('MES: '.now('America/Bogota')->format('m'), $bytes);
            $this->assertStringContainsString('ASISTENCIA', $bytes);
            $this->assertStringContainsString('00000012', $bytes);
            $this->assertStringContainsString('=1+1 &amp; &lt; prueba', $bytes);
            $this->assertStringContainsString('Correo electrónico', $bytes);
            $this->assertStringContainsString('PRESIDENTE', $bytes);
            $this->assertStringContainsString('SECRETARIO', $bytes);
            $this->assertStringContainsString('<c r="J6" t="inlineStr"><is><t xml:space="preserve">Firma</t>', $bytes);
            $this->assertStringContainsString('<c r="J7" t="inlineStr"><is><t xml:space="preserve"></t>', $bytes);
            $this->assertStringNotContainsString('NOTA-PRIVADA-PLANILLA', $bytes);
            $this->assertStringNotContainsString('00000013', $bytes);
            $this->assertStringNotContainsString('00000014', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
        }
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG, 'action' => 'person.planilla_export']);
        $this->assertStringNotContainsString('NOTA-PRIVADA-PLANILLA', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_columns_and_name_are_checked_on_server(): void
    {
        $p = $this->principal();
        $this->internal('GET', 'persons/planilla', ['columns' => ['email','phone','property_name','zone','status','affiliated']], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', ['columns' => ['email','email']], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', ['columns' => ['note']], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', ['filename' => 'Planilla.xlsx'], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', ['registered_from' => '2026-09-25'], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', [], $p, 'calendar')->assertUnauthorized();
        $this->assertDatabaseMissing('outbox_events', ['action' => 'person.planilla_export']);
    }

    public function test_print_preview_uses_same_columns_filters_and_private_scope(): void
    {
        $this->create('00000991', '=SUMA(1) & persona', $this->principal());
        $this->create('00000992', 'Otra persona', $this->principal());
        $this->create('00000993', '=SUMA(1) & persona', $this->principal(org: self::OTHER));
        $params = ['q' => '=SUMA(1)', 'columns' => ['zone'], 'h1' => 'Junta <Norte>',
            'filename' => '../Asistencia?.xlsx', 'confirm_filename' => 1];
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/planilla-preview', $params, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.title', 'Asistencia')
                ->assertJsonPath('data.headings.h1', 'Junta <Norte>')
                ->assertJsonPath('data.headers.4', 'Zona')
                ->assertJsonPath('data.headers.5', 'Firma')
                ->assertJsonPath('data.rows.0.0', '1')
                ->assertJsonPath('data.rows.0.3', '00000991')
                ->assertJsonPath('data.rows.0.5', '');
            $this->assertStringNotContainsString('NOTA-PRIVADA-PLANILLA', json_encode($response->json('data')));
            $this->assertStringNotContainsString('00000992', json_encode($response->json('data')));
            $this->assertStringNotContainsString('00000993', json_encode($response->json('data')));
        }
        $this->internal('GET', 'persons/planilla-preview', ['columns' => ['note']], $this->principal())->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-preview', ['filename' => 'Sin confirmar'], $this->principal())->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-preview', [], $this->principal(), 'calendar')->assertUnauthorized();
        $this->assertDatabaseHas('outbox_events', ['action' => 'person.planilla_preview', 'organization_id' => self::ORG]);
        $this->assertStringNotContainsString('00000991', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_pdf_data_uses_filtered_scope_confirmed_filename_and_export_audit(): void
    {
        $this->create('00000771', 'Persona incluida', $this->principal());
        $this->create('00000772', 'Persona excluida', $this->principal());
        $this->create('00000773', 'Persona incluida', $this->principal(org: self::OTHER));
        $params = ['q' => 'incluida', 'columns' => ['zone'], 'h1' => 'Junta <Norte>',
            'filename' => '../Asistencia?.xlsx', 'confirm_filename' => 1];
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/planilla-pdf', $params, $this->principal($role))
                ->assertOk()->assertJsonPath('data.filename', 'Asistencia.pdf')
                ->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.headings.h1', 'Junta <Norte>')
                ->assertJsonPath('data.headers.5', 'Firma')
                ->assertJsonPath('data.rows.0.3', '00000771')
                ->assertJsonPath('data.rows.0.5', '');
            $this->assertStringNotContainsString('NOTA-PRIVADA-PLANILLA', json_encode($response->json('data')));
            $this->assertStringNotContainsString('00000772', json_encode($response->json('data')));
            $this->assertStringNotContainsString('00000773', json_encode($response->json('data')));
        }
        $this->internal('GET', 'persons/planilla-pdf', [], $this->principal())
            ->assertOk()->assertJsonPath('data.filename', 'planilla_'.now('America/Bogota')->format('Y-m-d').'.pdf');
        $this->internal('GET', 'persons/planilla-pdf', ['filename' => 'Sin confirmar'], $this->principal())->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-pdf', ['columns' => ['note']], $this->principal())->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-pdf', [], $this->principal(), 'calendar')->assertUnauthorized();
        $this->assertDatabaseHas('outbox_events', ['action' => 'person.planilla_export', 'organization_id' => self::ORG]);
        $this->assertStringNotContainsString('00000771', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_blank_pdf_template_contains_headers_without_person_data(): void
    {
        $p = $this->principal();
        $this->create('BLANK-001', 'Persona reservada', $p);
        $response = $this->internal('GET', 'persons/planilla-pdf', ['blank_rows' => 1], $p)->assertOk()
            ->assertJsonPath('data.count', 0)->assertJsonCount(0, 'data.rows');
        $this->assertSame('Firma', $response->json('data.headers.4'));
        $this->assertStringNotContainsString('Persona reservada', $response->getContent());
    }

    public function test_planilla_rejects_more_than_2000_without_partial_file(): void
    {
        $p = $this->principal();
        for ($start = 1; $start <= 2001; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, 2002); $i++) {
                $rows[] = [
                    'id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i), 'organization_id' => self::ORG,
                    'document_type' => 'CC', 'document_number' => sprintf('DOC-%04d', $i),
                    'first_names' => 'Persona de prueba', 'status' => 'pending', 'version' => 1,
                    'created_by' => $p['user_id'], 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('persons')->insert($rows);
        }
        $this->internal('GET', 'persons/planilla', [], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-preview', [], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla-pdf', [], $p)->assertUnprocessable();
        $this->assertDatabaseMissing('outbox_events', ['action' => 'person.planilla_export']);
        DB::table('persons')->where('document_number', 'DOC-2001')->delete();
        $this->internal('GET', 'persons/planilla', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
        $this->internal('GET', 'persons/planilla-preview', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
        $this->internal('GET', 'persons/planilla-pdf', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
    }
}
