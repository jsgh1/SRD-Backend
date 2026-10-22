<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;
final class PersonFilterSettingsTest extends TestCase {
    use RefreshDatabase, SignedRequests;
    private array $p=['organization_id'=>'11111111-1111-4111-8111-111111111111','user_id'=>'22222222-2222-4222-8222-222222222222','role'=>'admin'];
    private function data(int $version=0): array { return ['version'=>$version,'base'=>['gender','zone'],'custom'=>[]]; }
    public function test_defaults_scope_validation_and_versions(): void {
        foreach (Access::ROLES as $role) {
            $p=array_replace($this->p,['role'=>$role]);
            $this->internal('GET','person-filter-settings',[],$p)->assertOk()->assertJsonPath('data.version',0)->assertJsonCount(9,'data.base')->assertJsonPath('data.custom',null)->assertJsonPath('data.can_manage',in_array($role,['admin','superadmin']));
            if (!in_array($role,['admin','superadmin'])) $this->internal('PUT','person-filter-settings',$this->data(),$p)->assertForbidden();
        }
        $this->assertDatabaseCount('person_filter_settings',0);
        foreach ([['base'=>['note']],['base'=>['gender','gender']],['custom'=>['33333333-3333-4333-8333-333333333333']],['delegated_roles'=>['superadmin']],['delegated_roles'=>['viewer','viewer']]] as $invalid) {
            $this->internal('PUT','person-filter-settings',array_replace($this->data(),$invalid),$this->p)->assertUnprocessable();
        }
        $this->internal('PUT','person-filter-settings',$this->data(),$this->p)->assertOk()->assertJsonPath('data.version',1);
        $this->internal('PUT','person-filter-settings',$this->data(),$this->p)->assertConflict();
        $other=array_replace($this->p,['organization_id'=>'33333333-3333-4333-8333-333333333333']);
        $this->internal('GET','person-filter-settings',[],$other)->assertOk()->assertJsonPath('data.version',0);
        $this->assertSame(1,DB::table('outbox_events')->where('action','person_filters.updated')->count());
    }
    public function test_delegates_cannot_grant_permissions_and_revocation_is_checked_on_every_write(): void {
        $version=0;
        foreach (['registrar','treasurer','auditor','viewer'] as $role) {
            $delegate=array_replace($this->p,['role'=>$role]);
            $this->internal('PUT','person-filter-settings',$this->data($version)+['delegated_roles'=>[$role]],$this->p)->assertOk(); $version++;
            $this->internal('GET','person-filter-settings',[],$delegate)->assertOk()->assertJsonPath('data.can_manage',true)->assertJsonPath('data.can_delegate',false);
            $this->internal('PUT','person-filter-settings',$this->data($version)+['delegated_roles'=>[$role]],$delegate)->assertForbidden();
            $this->internal('PUT','person-filter-settings',$this->data($version),$delegate)->assertOk()->assertJsonPath('data.delegated_roles',[$role]); $version++;
            $stale=$version;
            $this->internal('PUT','person-filter-settings',$this->data($version)+['delegated_roles'=>[]],$this->p)->assertOk(); $version++;
            $this->internal('PUT','person-filter-settings',$this->data($stale),$delegate)->assertForbidden();
            $this->internal('PUT','person-filter-settings',$this->data($version),$delegate)->assertForbidden();
            $this->assertFalse(Access::allows($role,'organization.manage'));
        }
        $this->assertSame(12,DB::table('outbox_events')->where('action','person_filters.updated')->count());
    }
    public function test_custom_fields_must_belong_to_junta_and_inactive_fields_remain_selectable(): void {
        $id='44444444-4444-4444-8444-444444444444';
        $field=['id'=>$id,'label'=>'Campo histórico','label_en'=>'Historical field','type'=>'text','active'=>false,'required'=>false,'options'=>[]];
        $this->internal('PUT','person-fields',['version'=>0,'fields'=>[$field]],$this->p)->assertOk();
        $data=array_replace($this->data(),['base'=>[],'custom'=>[$id]]);
        $this->internal('PUT','person-filter-settings',$data,$this->p)->assertOk()->assertJsonPath('data.base',[])->assertJsonPath('data.custom',[$id]);
        $other=array_replace($this->p,['organization_id'=>'33333333-3333-4333-8333-333333333333']);
        $this->internal('PUT','person-filter-settings',$data,$other)->assertUnprocessable();
        $this->assertDatabaseCount('person_filter_settings',1);
        $event=DB::table('outbox_events')->where('action','person_filters.updated')->first();
        $this->assertStringNotContainsString('Campo histórico',json_encode($event));
    }
}
