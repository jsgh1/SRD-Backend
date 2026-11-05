<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class QuickLinkTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private array $admin = ['user_id' => '11111111-1111-4111-8111-111111111111', 'organization_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'admin'];
    private string $other = '33333333-3333-4333-8333-333333333333';
    private array $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('organizations')->insert([
            ['id' => $this->admin['organization_id'], 'code' => 'junta-a', 'name' => 'Junta A'],
            ['id' => $this->other, 'code' => 'junta-b', 'name' => 'Junta B'],
        ]);
        $this->viewer = array_replace($this->admin, ['user_id' => '44444444-4444-4444-8444-444444444444', 'role' => 'viewer']);
    }

    private function shared(array $items, string $mode = 'personal', int $version = 0, ?array $principal = null)
    {
        foreach ($items as &$item) $item['label_en'] ??= 'Synthetic link';
        unset($item);
        return $this->internal('PATCH', 'quick-links/organization', compact('items', 'mode', 'version'), $principal ?? array_replace($this->admin, ['role' => 'superadmin']));
    }

    private function personal(array $items, int $version = 0, int $commonVersion = 1, bool $inherit = false, ?array $principal = null)
    {
        foreach ($items as &$item) $item['label_en'] ??= 'Synthetic link';
        unset($item);
        return $this->internal('PATCH', 'quick-links/personal', ['items' => $items, 'version' => $version, 'common_version' => $commonVersion, 'inherit' => $inherit], $principal ?? $this->viewer);
    }

    public function test_new_links_require_english_but_legacy_links_remain_readable(): void
    {
        $this->internal('PATCH', 'quick-links/organization', ['items' => [['function' => 'list', 'label' => 'Solo español']], 'version' => 0], $this->admin)
            ->assertUnprocessable();
        $this->assertDatabaseCount('organization_quick_links', 0);
        DB::table('organization_quick_links')->insert(['organization_id' => $this->admin['organization_id'], 'mode' => 'common', 'items' => json_encode([['function' => 'list', 'label' => 'Anterior']], JSON_THROW_ON_ERROR), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->internal('GET', 'quick-links', [], $this->admin)->assertOk()->assertJsonPath('data.items.0.label', 'Anterior')->assertJsonMissingPath('data.items.0.label_en');
    }

    public function test_only_superadmin_changes_policy_while_admin_keeps_editing_common_items(): void
    {
        $items = [['function'=>'list','label'=>'Lista común','label_en'=>'Common list']];
        $this->shared($items, 'personal', 0, $this->admin)->assertForbidden();
        $this->assertDatabaseCount('organization_quick_links', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->internal('PATCH', 'quick-links/organization', ['items'=>$items,'version'=>0], $this->admin)->assertOk()->assertJsonPath('data.mode', 'common');
        $this->shared($items, 'personal', 1)->assertOk();
        $this->internal('PATCH', 'quick-links/organization', ['items'=>[],'version'=>1], $this->admin)->assertConflict();
        $this->shared([], 'common', 2, $this->admin)->assertForbidden();
        $this->internal('PATCH', 'quick-links/organization', ['items'=>[],'version'=>2], $this->admin)->assertOk()->assertJsonPath('data.mode', 'personal');
        $this->personal([['function'=>'lookup','label'=>'Mi consulta']], 0, 3)->assertOk();
        $this->shared($items, 'common', 3)->assertOk();
        $this->personal([], 1, 3)->assertForbidden();
        $this->shared([], 'personal', 4, $this->admin)->assertForbidden();
        $this->shared([], 'common', 4, $this->admin)->assertOk()->assertJsonPath('data.mode', 'common');
        $this->assertDatabaseCount('outbox_events', 6);
        $this->assertDatabaseHas('personal_quick_links', ['version'=>1]);
    }

    public function test_defaults_and_catalog_are_filtered_without_writing_during_read(): void
    {
        $this->internal('GET', 'quick-links', [], $this->admin)->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.common_version', 0);
        $result = $this->internal('GET', 'quick-links', [], $this->viewer)->assertOk()->assertJsonCount(2, 'data.items')->assertJsonMissingPath('data.common_items');
        $keys = array_column($result->json('data.catalog'), 'function');
        $this->assertNotContains('register', $keys);
        $this->assertNotContains('audit', $keys);
        $this->assertDatabaseCount('organization_quick_links', 0);
        $this->assertDatabaseCount('personal_quick_links', 0);
        DB::table('organizations')->where('id', $this->admin['organization_id'])->update(['active' => false]);
        $this->internal('GET', 'quick-links', [], $this->admin)->assertForbidden();
    }

    public function test_common_mode_requires_admin_and_isolates_organizations(): void
    {
        $items = [['function' => 'audit', 'label' => 'Revisar actividad'], ['function' => 'lookup', 'label' => 'Buscar documento']];
        foreach (['viewer', 'registrar', 'treasurer', 'auditor'] as $role) {
            $this->shared($items, 'common', 0, array_replace($this->admin, ['role' => $role]))->assertForbidden();
        }
        $this->shared($items, 'common')->assertOk()->assertJsonPath('data.common_version', 1);
        $this->internal('GET', 'quick-links', [], $this->viewer)->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.label', 'Buscar documento');
        $this->personal([])->assertForbidden();
        $this->internal('GET', 'quick-links', [], array_replace($this->viewer, ['organization_id' => $this->other]))->assertJsonCount(2, 'data.items')->assertJsonPath('data.common_version', 0);
        $this->assertDatabaseHas('outbox_events', ['action' => 'quick_links.organization_updated', 'organization_id' => $this->admin['organization_id'], 'actor_id' => $this->admin['user_id']]);
    }

    public function test_personal_preferences_are_per_user_and_junta_and_survive_common_mode(): void
    {
        $this->shared([['function' => 'list', 'label' => 'Personas de la junta']])->assertOk();
        $this->personal([['function' => 'settings', 'label' => 'Mi configuración']])->assertOk()->assertJsonPath('data.items.0.label', 'Mi configuración')->assertJsonPath('data.personal_version', 1);
        $this->internal('GET', 'quick-links', [], $this->admin)->assertJsonPath('data.items.0.label', 'Personas de la junta')->assertJsonPath('data.inherited', true);
        $this->internal('GET', 'quick-links', [], array_replace($this->viewer, ['organization_id' => $this->other]))->assertJsonPath('data.personal_version', 0);
        $this->shared([['function' => 'lookup', 'label' => 'Común temporal']], 'common', 1)->assertOk();
        $this->internal('GET', 'quick-links', [], $this->viewer)->assertJsonPath('data.items.0.label', 'Común temporal');
        $this->shared([], 'personal', 2)->assertOk();
        $this->internal('GET', 'quick-links', [], $this->viewer)->assertJsonPath('data.items.0.label', 'Mi configuración');
        $this->personal([], 1, 3, true)->assertOk()->assertJsonPath('data.inherited', true)->assertJsonCount(0, 'data.items');
        $this->assertDatabaseHas('personal_quick_links', ['user_id' => $this->viewer['user_id'], 'version' => 2, 'items' => null]);
    }

    public function test_conflicting_writes_do_not_overwrite_and_an_empty_list_removes_only_shortcuts(): void
    {
        $this->shared([['function' => 'list', 'label' => 'Lista']])->assertOk();
        $this->shared([], 'personal')->assertConflict();
        $this->personal([['function' => 'lookup', 'label' => 'Consulta']])->assertOk();
        $this->personal([], 0)->assertConflict();
        $this->personal([], 1, 0)->assertConflict();
        $this->personal([], 1)->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.inherited', false);
        $this->internal('GET', 'quick-links', [], $this->viewer)->assertJsonCount(4, 'data.catalog');
        $this->assertDatabaseCount('outbox_events', 3);
    }

    public function test_routes_unknown_fields_duplicates_size_labels_and_permissions_are_validated(): void
    {
        $invalid = [
            [['function' => 'https://example.test', 'label' => 'Enlace']],
            [['function' => 'list', 'label' => 'Lista', 'url' => '/platform/organizations']],
            [['function' => 'list', 'label' => 'A'], ['function' => 'list', 'label' => 'B']],
            [['function' => 'list', 'label' => str_repeat('a', 41)]],
            [['function' => 'list', 'label' => '   ']],
            array_fill(0, 4, ['function' => 'list', 'label' => 'Lista']),
        ];
        foreach ($invalid as $items) $this->shared($items)->assertUnprocessable();
        $this->assertDatabaseCount('organization_quick_links', 0);
        $this->shared([])->assertOk();
        $this->personal([['function' => 'audit', 'label' => 'No autorizado']])->assertUnprocessable();
        $this->personal([['function' => 'register', 'label' => 'No autorizado']])->assertUnprocessable();
        $this->assertDatabaseCount('personal_quick_links', 0);
        $this->internal('PATCH', 'quick-links/arbitrary', [], $this->admin)->assertNotFound();
    }

    public function test_saved_preferences_are_filtered_again_after_role_change(): void
    {
        $this->shared([])->assertOk();
        $this->personal([['function' => 'audit', 'label' => 'Auditar'], ['function' => 'list', 'label' => 'Leer']], 0, 1, false, $this->admin)->assertOk();
        $this->internal('GET', 'quick-links', [], array_replace($this->admin, ['role' => 'viewer']))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.function', 'list');
        $this->assertCount(2, json_decode(DB::table('personal_quick_links')->value('items'), true));
    }
}
