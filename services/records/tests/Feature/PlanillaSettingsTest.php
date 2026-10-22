<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PlanillaSettingsTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $admin = [
        'organization_id' => '11111111-1111-4111-8111-111111111111',
        'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'admin',
    ];

    private function data(int $version = 0): array
    {
        return ['version' => $version, 'allowed_columns' => ['zone', 'phone'],
            'h1' => 'JUNTA COMUNAL', 'h2' => 'Sector norte', 'h3' => 'ASISTENCIA',
            'h1_en' => 'COMMUNITY BOARD', 'h2_en' => 'North area', 'h3_en' => 'ATTENDANCE'];
    }

    public function test_public_logo_exposes_only_the_requested_council_image_to_gateway(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZkAAAAASUVORK5CYII=';
        $other = '33333333-3333-4333-8333-333333333333';
        $this->internal('GET', 'public-logo/'.$this->admin['organization_id'])->assertJsonPath('data.logo_data', null);
        $this->internal('PUT', 'planilla-settings', $this->data() + ['logo_data' => $logo], $this->admin)->assertOk();
        $this->internal('GET', 'public-logo/'.$this->admin['organization_id'])->assertJsonPath('data.logo_data', $logo);
        $this->internal('GET', 'public-logo/'.$other)->assertJsonPath('data.logo_data', null);
        $this->internal('GET', 'public-logo/'.$this->admin['organization_id'], [], [], 'identity')->assertForbidden();
        $this->getJson('/internal/v1/public-logo/'.$this->admin['organization_id'])->assertUnauthorized();
    }

    public function test_settings_are_scoped_validated_and_versioned(): void
    {
        foreach (Access::ROLES as $role) {
            $p = array_replace($this->admin, ['role' => $role]);
            $this->internal('GET', 'planilla-settings', [], $p)->assertOk()
                ->assertJsonPath('data.version', 0)->assertJsonPath('data.h1_en', 'COMMUNITY ACTION BOARD')->assertJsonCount(8, 'data.allowed_columns')
                ->assertJsonPath('data.can_manage', in_array($role, ['admin', 'superadmin']));
            if (!in_array($role, ['admin', 'superadmin'])) {
                $this->internal('PUT', 'planilla-settings', $this->data(), $p)->assertForbidden();
            }
        }
        $this->assertDatabaseCount('planilla_settings', 0);
        foreach ([
            ['allowed_columns' => ['note']], ['allowed_columns' => ['zone', 'zone']],
            ['h1' => ''], ['h2' => str_repeat('a', 121)], ['h1_en' => ''], ['h2_en' => str_repeat('a', 121)],
            ['delegated_roles' => ['superadmin']], ['delegated_roles' => ['viewer', 'viewer']],
        ] as $invalid) {
            $this->internal('PUT', 'planilla-settings', array_replace($this->data(), $invalid), $this->admin)->assertUnprocessable();
        }
        $this->internal('PUT', 'planilla-settings', $this->data(), $this->admin)->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.h1', 'JUNTA COMUNAL')->assertJsonPath('data.h1_en', 'COMMUNITY BOARD');
        $this->internal('PUT', 'planilla-settings', $this->data(), $this->admin)->assertConflict();
        $other = array_replace($this->admin, ['organization_id' => '33333333-3333-4333-8333-333333333333']);
        $this->internal('GET', 'planilla-settings', [], $other)->assertOk()->assertJsonPath('data.version', 0);
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'planilla_settings.updated')->count());
    }

    public function test_legacy_headings_remain_readable_and_need_english_on_write(): void
    {
        $legacy = $this->data();
        unset($legacy['h1_en'], $legacy['h2_en'], $legacy['h3_en']);
        $this->internal('PUT', 'planilla-settings', $legacy, $this->admin)->assertUnprocessable();
        DB::table('planilla_settings')->insert([
            'organization_id' => $this->admin['organization_id'], 'version' => 1,
            'allowed_columns' => json_encode(['zone']), 'h1' => 'JUNTA ANTIGUA', 'h2' => 'Sector sur', 'h3' => 'FIRMAS',
            'h1_en' => null, 'h2_en' => null, 'h3_en' => null, 'delegated_roles' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->internal('GET', 'planilla-settings', [], $this->admin)->assertOk()
            ->assertJsonPath('data.h1', 'JUNTA ANTIGUA')->assertJsonPath('data.h1_en', null);
        $this->internal('GET', 'persons/planilla-preview', ['language' => 'en'], $this->admin)->assertOk()
            ->assertJsonPath('data.headings.h1', 'JUNTA ANTIGUA');
    }

    public function test_delegation_revocation_and_export_visibility_are_enforced(): void
    {
        $version = 0;
        foreach (['registrar', 'treasurer', 'auditor', 'viewer'] as $role) {
            $delegate = array_replace($this->admin, ['role' => $role]);
            $this->internal('PUT', 'planilla-settings', $this->data($version) + ['delegated_roles' => [$role]], $this->admin)->assertOk();
            $version++;
            $this->internal('GET', 'planilla-settings', [], $delegate)->assertOk()
                ->assertJsonPath('data.can_manage', true)->assertJsonPath('data.can_delegate', false);
            $this->internal('PUT', 'planilla-settings', $this->data($version) + ['delegated_roles' => [$role]], $delegate)->assertForbidden();
            $this->internal('PUT', 'planilla-settings', $this->data($version), $delegate)->assertOk()->assertJsonPath('data.delegated_roles', [$role]);
            $version++;
            $stale = $version;
            $this->internal('PUT', 'planilla-settings', $this->data($version) + ['delegated_roles' => []], $this->admin)->assertOk();
            $version++;
            $this->internal('PUT', 'planilla-settings', $this->data($stale), $delegate)->assertForbidden();
            $this->internal('PUT', 'planilla-settings', $this->data($version), $delegate)->assertForbidden();
        }
        $this->internal('GET', 'persons/planilla', ['columns' => ['email']], $this->admin)->assertUnprocessable();
        $this->internal('GET', 'persons/planilla', ['columns' => ['zone']], $this->admin)->assertOk();
        $bytes = base64_decode($this->internal('GET', 'persons/planilla', [], $this->admin)->assertOk()->json('data.content'));
        $this->assertStringContainsString('JUNTA COMUNAL', $bytes);
        $this->assertStringContainsString('Sector norte', $bytes);
        $this->assertStringContainsString('ASISTENCIA', $bytes);
        $this->assertDatabaseMissing('outbox_events', ['action' => 'planilla_settings.updated', 'organization_id' => '33333333-3333-4333-8333-333333333333']);
    }

    public function test_png_logo_is_scoped_and_embedded_in_all_planilla_outputs(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZkAAAAASUVORK5CYII=';
        $this->internal('PUT', 'planilla-settings', $this->data() + ['logo_data' => 'data:image/svg+xml;base64,PHN2Zy8+'], $this->admin)->assertUnprocessable();
        $this->internal('PUT', 'planilla-settings', $this->data() + ['logo_data' => $logo], array_replace($this->admin, ['role' => 'viewer']))->assertForbidden();
        $this->internal('PUT', 'planilla-settings', $this->data() + ['logo_data' => $logo], $this->admin)
            ->assertOk()->assertJsonPath('data.logo_data', $logo);
        $this->internal('GET', 'persons/planilla-preview', [], $this->admin)->assertOk()->assertJsonPath('data.logo_data', $logo);
        $this->internal('GET', 'persons/planilla-pdf', [], $this->admin)->assertOk()->assertJsonPath('data.logo_data', $logo);
        $workbook = base64_decode($this->internal('GET', 'persons/planilla', [], $this->admin)->assertOk()->json('data.content'));
        $path = tempnam(sys_get_temp_dir(), 'srd-logo-');
        file_put_contents($path, $workbook);
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $this->assertSame(base64_decode(substr($logo, 22)), $zip->getFromName('xl/media/logo.png'));
            $this->assertStringContainsString('<drawing r:id="rId1"/>', $zip->getFromName('xl/worksheets/sheet1.xml'));
            foreach (['[Content_Types].xml', 'xl/worksheets/sheet1.xml',
                'xl/worksheets/_rels/sheet1.xml.rels', 'xl/drawings/drawing1.xml',
                'xl/drawings/_rels/drawing1.xml.rels'] as $part) {
                $this->assertNotFalse(simplexml_load_string($zip->getFromName($part)), $part);
            }
            $this->assertStringContainsString('Target="../media/logo.png"', $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels'));
            $zip->close();
        } finally {
            unlink($path);
        }
        $other = array_replace($this->admin, ['organization_id' => '33333333-3333-4333-8333-333333333333']);
        $this->internal('GET', 'planilla-settings', [], $other)->assertOk()->assertJsonPath('data.logo_data', null);
        $this->internal('PUT', 'planilla-settings', $this->data(1) + ['logo_data' => null], $this->admin)
            ->assertOk()->assertJsonPath('data.logo_data', null);
    }
}
