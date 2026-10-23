<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class FolderApiTest extends TestCase
{
    use SignedRequests;
    private array $p = ['organization_id' => '11111111-1111-4111-8111-111111111111',
        'user_id' => '22222222-2222-4222-8222-222222222222',
        'session_id' => '33333333-3333-4333-8333-333333333333', 'role' => 'admin'];
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_27_000001_create_internal_folders.php'))->up();
        (require database_path('migrations/2026_10_08_000001_add_folder_name_en.php'))->up();
        (require database_path('migrations/2026_09_27_000002_create_folder_documents.php'))->up();
        (require database_path('migrations/2026_09_27_000005_create_folder_access.php'))->up();
        Schema::create('file_quotas', function (Blueprint $t) { $t->uuid('organization_id')->primary(); $t->unsignedBigInteger('reserved_bytes')->default(0); });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('actor_id'); $t->string('action');
            $t->uuid('resource_id'); $t->string('result'); $t->uuid('correlation_id');
            $t->dateTime('occurred_at'); $t->integer('attempts'); $t->dateTime('next_attempt_at');
        });
    }
    private function create(string $name, ?string $parent = null, ?array $p = null): string
    {
        $id = (string) Str::uuid();
        $this->internal('POST', 'folders', ['id' => $id, 'name' => $name, 'parent_id' => $parent], $p ?? $this->p)
            ->assertOk()->assertJsonPath('data.name', $name);
        return $id;
    }
    public function test_delete_requires_confirmation_current_version_and_empty_own_folder(): void
    {
        $id = $this->create('Vacía'); $path = 'folders/'.$id; $payload = ['version'=>1,'confirm'=>true];
        $this->deleteJson('/internal/v1/'.$path,$payload)->assertUnauthorized();
        foreach (['registrar','treasurer','auditor','viewer'] as $role) $this->internal('DELETE',$path,$payload,array_replace($this->p,['role'=>$role]))->assertForbidden();
        $this->internal('DELETE',$path,$payload,$this->p,'identity')->assertForbidden();
        $this->internal('DELETE',$path,$payload,array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
        foreach ([['version'=>1],['version'=>1,'confirm'=>false],['confirm'=>true],['version'=>0,'confirm'=>true]] as $invalid) $this->internal('DELETE',$path,$invalid,$this->p)->assertUnprocessable();
        $this->internal('DELETE',$path,$payload+['padding'=>str_repeat('x',4096)],$this->p)->assertStatus(413);
        $this->internal('PATCH',$path,['name'=>'Actual','version'=>1],$this->p)->assertOk();
        $events=DB::table('outbox_events')->count();
        $this->internal('DELETE',$path,$payload,$this->p)->assertConflict();
        $child=$this->create('Hija',$id);
        $this->internal('DELETE',$path,['version'=>2,'confirm'=>true],$this->p)->assertUnprocessable();
        $this->assertSame($events+1,DB::table('outbox_events')->count());
        $this->internal('DELETE','folders/'.$child,$payload,$this->p)->assertOk()->assertJsonPath('data.deleted',true);
        $doc=(string)Str::uuid();
        DB::table('folder_documents')->insert(['id'=>$doc,'organization_id'=>$this->p['organization_id'],'folder_id'=>$id,'parent_key'=>$id,'name'=>'Pendiente.docx','name_key'=>hash('sha256','pendiente.docx'),'mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','blob_id'=>bin2hex(random_bytes(24)),'bytes'=>42,'sha256'=>str_repeat('a',64),'ready'=>false,'created_by'=>$this->p['user_id'],'created_at'=>now(),'updated_at'=>now()]);
        foreach ([false,true] as $ready) {
            DB::table('folder_documents')->where('id',$doc)->update(['ready'=>$ready]);
            $this->internal('DELETE',$path,['version'=>2,'confirm'=>true],$this->p)->assertUnprocessable();
            $this->assertTrue(DB::table('folder_documents')->where('id',$doc)->exists());
        }
        DB::table('folder_documents')->where('id',$doc)->delete();
        DB::table('file_quotas')->where('organization_id',$this->p['organization_id'])->update(['reserved_bytes'=>512]);
        $this->internal('DELETE',$path,['version'=>2,'confirm'=>true],array_replace($this->p,['role'=>'superadmin']))->assertOk()->assertJsonPath('data.id',$id);
        $this->assertFalse(DB::table('internal_folders')->where('id',$id)->exists());
        $this->assertSame(512,(int)DB::table('file_quotas')->where('organization_id',$this->p['organization_id'])->value('reserved_bytes'));
        $this->assertSame(1,DB::table('outbox_events')->where('action','folder.deleted')->where('resource_id',$id)->count());
        $this->internal('DELETE',$path,['version'=>2,'confirm'=>true],$this->p)->assertNotFound();
        $this->assertSame(1,DB::table('outbox_events')->where('action','folder.deleted')->where('resource_id',$id)->count());
    }
    public function test_signed_context_and_admin_only_permissions(): void
    {
        $this->getJson('/internal/v1/folders')->assertUnauthorized();
        $this->internal('GET', 'folders', [], $this->p, 'identity')->assertForbidden();
        foreach (['registrar', 'treasurer', 'auditor', 'viewer'] as $role) {
            $p = array_replace($this->p, ['role' => $role]);
            $this->internal('GET', 'folders', [], $p)->assertForbidden();
            $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => 'Denied'], $p)->assertForbidden();
            $this->internal('PATCH', 'folders/'.Str::uuid(), ['name' => 'Denied', 'version' => 1], $p)->assertForbidden();
            $this->internal('POST', 'folders/'.Str::uuid().'/move', ['parent_id' => null, 'version' => 1], $p)->assertForbidden();
        }
        foreach (['organization_id', 'user_id', 'session_id'] as $key) {
            $p = $this->p; unset($p[$key]);
            $this->internal('GET', 'folders', [], $p)->assertForbidden();
        }
        $this->internal('GET', 'folders', [], array_replace($this->p, ['role' => 'superadmin']))->assertOk();
        $this->assertSame(0, DB::table('internal_folders')->count());
    }
    public function test_hierarchy_isolation_pagination_and_forged_authority(): void
    {
        $root = $this->create('Actas'); $child = $this->create('2026', $root);
        $other = array_replace($this->p, ['organization_id' => (string) Str::uuid()]);
        $this->create('Privada', null, $other);
        $this->internal('GET', 'folders', ['organization_id' => $other['organization_id']], $this->p)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $root);
        $this->internal('GET', 'folders', [], $this->p, 'gateway', ['parent_id' => $root])
            ->assertOk()->assertJsonPath('data.items.0.id', $child)->assertJsonPath('data.breadcrumbs.0.name', 'Actas');
        $this->internal('GET', 'folders', [], $other, 'gateway', ['parent_id' => $root])->assertNotFound();
        $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => 'Intrusa', 'parent_id' => $root], $other)->assertNotFound();
        $this->internal('PATCH', 'folders/'.$child, ['name' => 'Intrusa', 'version' => 1], $other)->assertNotFound();
        for ($i = 0; $i < 26; $i++) $this->create(sprintf('Sub %02d', $i), $root);
        $this->internal('GET', 'folders', [], $this->p, 'gateway', ['parent_id' => $root, 'page' => 2])
            ->assertOk()->assertJsonPath('data.total', 27)->assertJsonCount(2, 'data.items');
    }
    public function test_unique_names_retry_and_optimistic_rename_preserve_audit(): void
    {
        $id = $this->create('Actas');
        $this->internal('POST', 'folders', ['id' => $id, 'name' => 'Actas'], $this->p)->assertOk();
        $this->assertSame(1, DB::table('outbox_events')->count());
        $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => ' ACTAS '], $this->p)->assertConflict();
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Reuniones', 'version' => 1], $this->p)
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Obsoleta', 'version' => 1], $this->p)->assertConflict();
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Reuniones', 'version' => 2], $this->p)->assertOk();
        $this->create('Informes');
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'informes', 'version' => 2], $this->p)->assertConflict();
        $this->assertSame('Reuniones', DB::table('internal_folders')->where('id', $id)->value('name'));
        $this->assertSame(3, DB::table('outbox_events')->count());
    }
    public function test_english_name_is_saved_in_list_and_breadcrumbs_without_changing_legacy_clients(): void
    {
        $id = (string) Str::uuid();
        $body = ['id' => $id, 'parent_id' => null, 'name' => 'Actas', 'name_en' => 'Minutes'];
        $this->internal('POST', 'folders', $body, $this->p)->assertOk()->assertJsonPath('data.name_en', 'Minutes');
        $this->internal('POST', 'folders', $body, $this->p)->assertOk();
        $this->internal('POST', 'folders', ['id' => $id, 'parent_id' => null, 'name' => 'Actas'], $this->p)->assertOk();
        $this->internal('POST', 'folders', array_replace($body, ['name_en' => 'Records']), $this->p)->assertConflict();
        $this->internal('GET', 'folders', [], $this->p)->assertJsonPath('data.items.0.name_en', 'Minutes');
        $this->internal('GET', 'folders', [], $this->p, 'gateway', ['parent_id' => $id])
            ->assertJsonPath('data.breadcrumbs.0.name_en', 'Minutes');
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Actas', 'name_en' => 'Meeting minutes', 'version' => 1], $this->p)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.name_en', 'Meeting minutes');
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Actas 2026', 'version' => 2], $this->p)
            ->assertOk()->assertJsonPath('data.name_en', 'Meeting minutes');
        $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => 'Histórico'], $this->p)
            ->assertOk()->assertJsonPath('data.name_en', null);
        $this->internal('PATCH', 'folders/'.$id, ['name' => 'Actas 2026', 'name_en' => 'Bad/Name', 'version' => 3], $this->p)
            ->assertUnprocessable();
    }
    public function test_move_preserves_descendants_versions_and_rejects_cycles_foreign_destinations_and_names(): void
    {
        $root = $this->create('Actas'); $child = $this->create('2026', $root); $leaf = $this->create('Septiembre', $child);
        $target = $this->create('Archivo');
        $foreign = array_replace($this->p, ['organization_id' => (string) Str::uuid()]);
        $foreignId = $this->create('Ajena', null, $foreign);
        $path = 'folders/'.$root.'/move';
        foreach ([$root, $child, $leaf] as $destination) {
            $this->internal('POST', $path, ['parent_id' => $destination, 'version' => 1], $this->p)->assertUnprocessable();
        }
        $this->internal('POST', $path, ['parent_id' => $foreignId, 'version' => 1], $this->p)->assertNotFound();
        $this->internal('POST', $path, ['parent_id' => null, 'version' => 1], $foreign)->assertNotFound();
        $this->internal('POST', $path, ['version' => 1], $this->p)->assertUnprocessable();
        $this->internal('POST', $path, ['parent_id' => null, 'version' => 1], $this->p)->assertOk()->assertJsonPath('data.version', 1);
        $this->internal('POST', $path, ['parent_id' => $target, 'version' => 1, 'organization_id' => $foreign['organization_id']], $this->p)
            ->assertOk()->assertJsonPath('data.parent_id', $target)->assertJsonPath('data.version', 2);
        $this->internal('GET', 'folders', [], $this->p, 'gateway', ['parent_id' => $child])
            ->assertOk()->assertJsonPath('data.items.0.id', $leaf)->assertJsonCount(3, 'data.breadcrumbs');
        $this->assertSame($root, DB::table('internal_folders')->where('id', $child)->value('parent_id'));
        $this->assertSame(1, DB::table('internal_folders')->where('id', $child)->value('version'));
        $this->internal('POST', $path, ['parent_id' => null, 'version' => 1], $this->p)->assertConflict();
        $this->create('Actas');
        $this->internal('POST', $path, ['parent_id' => null, 'version' => 2], $this->p)->assertConflict();
        $this->assertSame($target, DB::table('internal_folders')->where('id', $root)->value('parent_id'));
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'folder.moved')->count());
        $this->internal('POST', 'folders/'.$leaf.'/move', ['parent_id' => null, 'version' => 1], $this->p)
            ->assertOk()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.version', 2);
    }

    public function test_move_depth_checks_the_entire_subtree(): void
    {
        $root = $this->create('Árbol'); $child = $this->create('Hijo', $root); $this->create('Nieto', $child);
        $parent = null;
        for ($i = 0; $i < 18; $i++) $parent = $this->create('Destino '.$i, $parent);
        $this->internal('POST', 'folders/'.$root.'/move', ['parent_id' => $parent, 'version' => 1], $this->p)->assertUnprocessable();
        $this->assertNull(DB::table('internal_folders')->where('id', $root)->value('parent_id'));
        $this->assertSame(0, DB::table('outbox_events')->where('action', 'folder.moved')->count());
        $this->internal('POST', 'folders/'.$child.'/move', ['parent_id' => $parent, 'version' => 1], $this->p)
            ->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_invalid_names_parents_and_depth_do_not_create_folders(): void
    {
        $this->internal('POST', 'folders', ['id' => '00000000-0000-0000-0000-000000000000', 'name' => 'Raíz reservada'], $this->p)->assertUnprocessable();
        foreach (['..', '.', 'a/b', 'a\\b', "a\nb", '   '] as $name) {
            $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => $name], $this->p)->assertUnprocessable();
        }
        $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => 'Orphan', 'parent_id' => (string) Str::uuid()], $this->p)->assertNotFound();
        $parent = null;
        for ($i = 0; $i < 20; $i++) $parent = $this->create('Nivel '.$i, $parent);
        $this->internal('POST', 'folders', ['id' => (string) Str::uuid(), 'name' => 'Nivel 21', 'parent_id' => $parent], $this->p)->assertUnprocessable();
        $this->assertSame(20, DB::table('internal_folders')->count());
        $this->assertSame(20, DB::table('outbox_events')->count());
    }
}
