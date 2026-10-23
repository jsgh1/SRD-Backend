<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PersonExportTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'admin', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => $role];
    }

    private function create(string $number, string $name, array $principal): string
    {
        return $this->internal('POST', 'persons', [
            'document_type' => 'CC', 'document_number' => $number,
            'first_names' => $name, 'status' => 'pending', 'note' => 'NOTA-PRIVADA-EXPORT',
            'authorization_basis' => 'Prueba local', 'authorization_purpose' => 'Verificar exportación',
        ], $principal)->assertOk()->json('data.id');
    }

    public function test_export_reuses_list_filters_and_isolates_juntas_without_private_notes(): void
    {
        $p = $this->principal();
        $wanted = $this->create('000001', '=2+2 & < 50%_!', $p);
        $this->create('000002', 'Otro 50ABC!', $p);
        $this->create('000003', '=2+2 & < 50%_!', $this->principal(org: self::OTHER));
        $day = now('America/Bogota')->toDateString();
        $filters = ['q' => '50%_!', 'status' => 'pending', 'registered_from' => $day,
            'registered_to' => $day, 'page' => 2, 'page_size' => 10];
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/export', $filters, $this->principal($role))
                ->assertOk()->assertJsonPath('data.count', 1)
                ->assertJsonPath('data.filename', 'personas_'.$day.'.xlsx');
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);
            $this->assertStringNotContainsString($wanted, $bytes);
            $this->assertStringContainsString('000001', $bytes);
            $this->assertStringContainsString('=2+2 &amp; &lt; 50%_!', $bytes);
            $this->assertStringContainsString('t="inlineStr"', $bytes);
            $this->assertStringNotContainsString('<f>', $bytes);
            $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', $bytes);
            $this->assertStringNotContainsString('Otro 50ABC!', $bytes);
            $this->assertStringNotContainsString('000003', $bytes);
        }
        $this->internal('GET', 'persons/export', [], $this->principal(org: self::OTHER))
            ->assertOk()->assertJsonPath('data.count', 1);
        $this->internal('GET', 'persons/export', $filters, $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', 'persons/export', ['registered_from' => $day], $p)->assertUnprocessable();
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG, 'action' => 'person.export']);
        $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_individual_export_preserves_base_and_historical_fields_without_sensitive_extras(): void
    {
        $p = $this->principal();
        $wanted = $this->create('000009', '=2+2 & < Persona', $p);
        $other = $this->create('000010', 'OTRA-PERSONA-EXCLUIDA', $p);
        $foreign = $this->create('000011', 'OTRA-JUNTA-EXCLUIDA', $this->principal(org: self::OTHER));
        DB::table('person_field_values')->where('organization_id', self::ORG)->where('person_id', $wanted)->update([
            'snapshots' => json_encode(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => ['label' => 'Etiqueta histórica', 'display' => '0001.2300', 'value' => '0001.2300', 'type' => 'number']])]);
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/'.$wanted.'/xlsx', [], $this->principal($role))->assertOk()
                ->assertJsonPath('data.count', 1)->assertJsonPath('data.filename', 'ficha_persona_'.now('America/Bogota')->toDateString().'.xlsx');
            $bytes = base64_decode($response->json('data.content'), true);
            $this->assertStringStartsWith('PK', $bytes);
            foreach (['000009', '=2+2 &amp; &lt; Persona', 'Etiqueta histórica', '0001.2300'] as $text) $this->assertStringContainsString($text, $bytes);
            $this->assertStringNotContainsString($wanted, $bytes);
            foreach ([$other, $foreign, 'NOTA-PRIVADA-EXPORT', 'Verificar exportación', 'OTRA-PERSONA-EXCLUIDA', '<f>'] as $text) $this->assertStringNotContainsString($text, $bytes);
        }
        $path = 'persons/'.$wanted.'/xlsx';
        $this->internal('GET', $path, ['filename' => '../Ficha?.xlsx', 'confirm_filename' => 1], $p)->assertOk()->assertJsonPath('data.filename', 'Ficha.xlsx');
        $this->internal('GET', $path, ['filename' => 'Ficha'], $p)->assertUnprocessable();
        $this->internal('GET', $path, ['filename' => '///', 'confirm_filename' => 1], $p)->assertUnprocessable();
        $this->internal('GET', $path, [], $this->principal(org: self::OTHER))->assertNotFound();
        $this->internal('GET', $path, [], $p, 'calendar')->assertUnauthorized();
        $events = DB::table('outbox_events')->where('action', 'person.individual_export')->get();
        $this->assertCount(count(Access::ROLES) + 1, $events);
        $this->assertSame($wanted, $events[0]->resource_id);
        $this->assertStringNotContainsString('Persona', json_encode($events));
        $this->assertStringNotContainsString('0001.2300', json_encode($events));
        $this->internal('DELETE', 'persons/'.$wanted, ['version' => 1, 'confirmed' => true], $p)->assertOk();
        $this->internal('GET', $path, [], $p)->assertNotFound();
    }

    public function test_individual_pdf_shares_historical_data_and_excludes_sensitive_extras(): void
    {
        $p = $this->principal();
        $id = $this->create('000077', 'Persona PDF', $p);
        DB::table('person_field_values')->where('person_id', $id)->update(['snapshots' => json_encode([
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => ['label' => 'Etiqueta anterior', 'display' => '=2+2 & < 0001.2300', 'value' => 'historic', 'type' => 'text'],
        ])]);
        $path = 'persons/'.$id.'/pdf';
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', $path, [], $this->principal($role))->assertOk()
                ->assertJsonPath('data.filename', 'ficha_persona_'.now('America/Bogota')->toDateString().'.pdf')
                ->assertJsonPath('data.count', 1)->assertJsonCount(2, 'data.headers')->assertJsonCount(19, 'data.rows')
                ->assertJsonPath('data.rows.3.1', '000077')->assertJsonPath('data.rows.18.0', 'Campo adicional: Etiqueta anterior')
                ->assertJsonPath('data.rows.18.1', '=2+2 & < 0001.2300');
            $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', $response->getContent());
            $this->assertStringNotContainsString('Verificar exportación', $response->getContent());
        }
        $this->internal('GET', $path, ['filename' => '../Ficha?.xlsx.pdf', 'confirm_filename' => 1], $p)->assertOk()->assertJsonPath('data.filename', 'Ficha.pdf');
        $this->internal('GET', $path, ['filename' => 'Ficha'], $p)->assertUnprocessable();
        $this->internal('GET', $path, ['filename' => '///', 'confirm_filename' => 1], $p)->assertUnprocessable();
        $this->internal('GET', $path, [], $this->principal(org: self::OTHER))->assertNotFound();
        $this->internal('GET', $path, [], $p, 'calendar')->assertUnauthorized();
        $events = DB::table('outbox_events')->where('action', 'person.individual_export')->get();
        $this->assertCount(count(Access::ROLES) + 1, $events);
        $this->assertSame($id, $events[0]->resource_id);
        $this->assertStringNotContainsString('historic', json_encode($events));
        $this->internal('DELETE', 'persons/'.$id, ['version' => 1, 'confirmed' => true], $p)->assertOk();
        $this->internal('GET', $path, [], $p)->assertNotFound();
    }

    public function test_export_requires_confirmed_safe_filename(): void
    {
        $p = $this->principal();
        $this->internal('GET', 'persons/export', ['filename' => '../Personas?.xlsx'], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/export', ['filename' => '../Personas?.xlsx', 'confirm_filename' => 1], $p)
            ->assertOk()->assertJsonPath('data.filename', 'Personas.xlsx');
        $this->internal('GET', 'persons/export', ['filename' => '///', 'confirm_filename' => 1], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/export', ['filename' => str_repeat('a', 101), 'confirm_filename' => 1], $p)->assertUnprocessable();
    }

    public function test_english_exports_use_saved_council_labels_and_keep_user_text(): void
    {
        $p = $this->principal();
        $id = $this->create('EN-001', 'Nombre escrito por la junta', $p);
        DB::table('persons')->where('id', $id)->update([
            'position_label' => 'Vocal propio', 'position_label_en' => 'Council representative',
            'affiliated' => 1, 'zone' => 'urban', 'gender' => 'female',
        ]);
        DB::table('person_field_values')->where('person_id', $id)->update(['snapshots' => json_encode([
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => [
                'label' => 'Sector propio', 'label_en' => 'Own sector',
                'display' => 'Norte propio', 'display_en' => 'North sector', 'value' => 'north', 'type' => 'select',
            ],
        ])]);

        $list = $this->internal('GET', 'persons/export-pdf', ['language' => 'en'], $p)->assertOk()
            ->assertJsonPath('data.language', 'en')->assertJsonPath('data.headers.1', 'First names')
            ->assertJsonPath('data.rows.0.3', 'Citizenship Card')->assertJsonPath('data.rows.0.5', 'Pending')
            ->assertJsonPath('data.rows.0.6', 'Yes')->assertJsonPath('data.rows.0.7', 'Urban')
            ->assertJsonPath('data.rows.0.9', 'Council representative');
        $this->assertSame('Nombre escrito por la junta', $list->json('data.rows.0.1'));
        $xlsx = base64_decode($this->internal('GET', 'persons/export', ['language' => 'en'], $p)->assertOk()->json('data.content'), true);
        $this->assertStringContainsString('First names', $xlsx);
        $this->assertStringContainsString('Council representative', $xlsx);
        $this->assertStringContainsString('Nombre escrito por la junta', $xlsx);

        $path = 'persons/'.$id;
        $individual = $this->internal('GET', $path.'/pdf', ['language' => 'en'], $p)->assertOk()
            ->assertJsonPath('data.language', 'en')->assertJsonPath('data.headers.0', 'Field')
            ->assertJsonPath('data.rows.11.0', 'Position')->assertJsonPath('data.rows.11.1', 'Council representative')
            ->assertJsonPath('data.rows.18.0', 'Additional field: Own sector')
            ->assertJsonPath('data.rows.18.1', 'North sector');
        $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', $individual->getContent());
        $individualXlsx = base64_decode($this->internal('GET', $path.'/xlsx', ['language' => 'en'], $p)->assertOk()->json('data.content'), true);
        $this->assertStringContainsString('Additional field: Own sector', $individualXlsx);
        $this->assertStringContainsString('North sector', $individualXlsx);
        $this->internal('GET', 'persons/export-pdf', ['language' => 'fr'], $p)->assertUnprocessable();
    }

    public function test_pdf_export_reuses_safe_columns_filters_and_junta_scope(): void
    {
        $p = $this->principal();
        $wanted = $this->create('000041', '=SUM(A1) 50%_!', $p);
        $this->create('000042', 'Otro 50ABC!', $p);
        $this->create('000043', '=SUM(A1) 50%_!', $this->principal(org: self::OTHER));
        $day = now('America/Bogota')->toDateString();
        foreach (Access::ROLES as $role) {
            $response = $this->internal('GET', 'persons/export-pdf', [
                'q' => '50%_!', 'page' => 2, 'filename' => '../Personas?.xlsx', 'confirm_filename' => 1,
            ], $this->principal($role))->assertOk()
                ->assertJsonPath('data.filename', 'Personas.pdf')
                ->assertJsonPath('data.date', $day)
                ->assertJsonPath('data.count', 1)
                ->assertJsonCount(11, 'data.headers')
                ->assertJsonCount(1, 'data.rows');
            $this->assertNotContains($wanted, $response->json('data.rows.0'));
            $this->assertSame('000041', $response->json('data.rows.0.4'));
            $this->assertSame('=SUM(A1) 50%_!', $response->json('data.rows.0.1'));
            $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', $response->getContent());
            $this->assertStringNotContainsString('000043', $response->getContent());
        }
        $this->internal('GET', 'persons/export-pdf', [], $p, 'calendar')->assertUnauthorized();
        $this->internal('GET', 'persons/export-pdf', ['filename' => '../Personas?.pdf'], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/export-pdf', [], $p)->assertOk()
            ->assertJsonPath('data.filename', 'personas_'.$day.'.pdf');
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG, 'action' => 'person.export']);
        $this->assertStringNotContainsString('NOTA-PRIVADA-EXPORT', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_person_pdf_payload_uses_only_the_current_council_logo(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZkAAAAASUVORK5CYII=';
        $settings = ['version' => 0, 'allowed_columns' => ['zone', 'phone'],
            'h1' => 'JUNTA COMUNAL', 'h2' => 'Sector norte', 'h3' => 'ASISTENCIA',
            'h1_en' => 'COMMUNITY BOARD', 'h2_en' => 'North area', 'h3_en' => 'ATTENDANCE', 'logo_data' => $logo];
        $this->internal('PUT', 'planilla-settings', $settings, $this->principal())->assertOk();
        $own = $this->create('LOGO-OWN', 'Persona propia', $this->principal());
        $foreign = $this->create('LOGO-OTHER', 'Persona ajena', $this->principal(org: self::OTHER));

        $this->internal('GET', 'persons/'.$own.'/pdf', [], $this->principal())
            ->assertOk()->assertJsonPath('data.logo_data', $logo);
        $this->internal('GET', 'persons/export-pdf', [], $this->principal())
            ->assertOk()->assertJsonPath('data.logo_data', $logo);
        $this->internal('GET', 'persons/'.$foreign.'/pdf', [], $this->principal(org: self::OTHER))
            ->assertOk()->assertJsonPath('data.logo_data', null);
        $this->internal('GET', 'persons/export-pdf', [], $this->principal(org: self::OTHER))
            ->assertOk()->assertJsonPath('data.logo_data', null);
        $this->internal('GET', 'persons/'.$foreign.'/pdf', [], $this->principal())->assertNotFound();
    }

    public function test_export_rejects_more_than_2000_and_routes_large_excel_to_the_queue(): void
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
        $this->internal('GET', 'persons/export', [], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/export-pdf', [], $p)->assertUnprocessable();
        $this->assertDatabaseMissing('outbox_events', ['action' => 'person.export']);
        DB::table('persons')->where('document_number', 'DOC-2001')->delete();
        $this->internal('GET', 'persons/export', [], $p)->assertUnprocessable();
        $this->internal('GET', 'persons/export-pdf', [], $p)->assertOk()->assertJsonPath('data.count', 2000);
    }
}
