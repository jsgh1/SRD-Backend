<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class ConfigurationTest extends TestCase
{
    use RefreshDatabase,SignedRequests;

    public function test_terms_are_immutable_versions_and_settings_require_admin(): void
    {
        $org = '11111111-1111-4111-8111-111111111111';
        $id = '22222222-2222-4222-8222-222222222222';
        DB::table('organizations')->insert(['id' => $org, 'code' => 'prueba', 'name' => 'Prueba']);
        DB::table('terms_versions')->insert(['id' => $id, 'organization_id' => $org, 'version' => 1, 'body' => 'Primera versión de prueba', 'published_at' => now()]);
        $p = ['organization_id' => $org, 'user_id' => '33333333-3333-4333-8333-333333333333', 'role' => 'viewer'];
        $this->internal('POST', 'organization/terms', ['body' => 'Nueva versión de los términos.', 'version' => 1], $p)->assertForbidden();
        $p['role'] = 'admin';
        $this->internal('POST', 'organization/terms', ['body' => 'Nueva versión de los términos.', 'version' => 1], $p)->assertUnprocessable();
        $this->internal('POST', 'organization/terms', ['body' => 'Nueva versión de los términos.', 'body_en' => 'New version of the council terms.', 'version' => 1], $p)->assertOk();
        $this->assertDatabaseCount('terms_versions', 2);
        $this->assertDatabaseHas('terms_versions', ['organization_id' => $org, 'version' => 2, 'body_en' => 'New version of the council terms.']);
        $this->internal('POST', 'organization/terms', ['body' => 'Escritura desde versión vieja.', 'body_en' => 'Writing from an outdated version.', 'version' => 1], $p)->assertConflict();
        $this->internal('GET', 'organizations/code/prueba')->assertJsonPath('data.terms.version', 2);
        DB::table('organizations')->update(['active' => false]);
        $this->internal('GET', 'organizations/code/prueba')->assertNotFound();
    }
}
