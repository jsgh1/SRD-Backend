<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;
final class PersonFieldDelegationsTest extends TestCase {
    use RefreshDatabase, SignedRequests;
    private array $p=['organization_id'=>'11111111-1111-4111-8111-111111111111','user_id'=>'22222222-2222-4222-8222-222222222222','role'=>'admin'];
    public function test_all_delegated_roles_can_edit_but_cannot_delegate_or_bypass_history_and_revocation(): void {
        $version=0; $fields=[];
        foreach (['registrar','treasurer','auditor','viewer'] as $role) {
            $delegate=array_replace($this->p,['role'=>$role]);
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields],$delegate)->assertForbidden();
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields,'delegated_roles'=>[$role]],$this->p)->assertOk(); $version++;
            $this->internal('GET','person-fields',[],$delegate)->assertOk()->assertJsonPath('data.can_manage',true)->assertJsonPath('data.can_delegate',false);
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields,'delegated_roles'=>[$role]],$delegate)->assertForbidden();
            $fields[]=['id'=>(string)Str::uuid(),'label'=>'Campo sintético','label_en'=>'Synthetic field','type'=>'text','active'=>true,'required'=>false,'options'=>[]];
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields],$delegate)->assertOk()->assertJsonPath('data.delegated_roles',[$role]); $version++;
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>[]],$delegate)->assertUnprocessable();
            $altered=$fields; $altered[0]['type']='date';
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$altered],$delegate)->assertUnprocessable();
            $other=array_replace($delegate,['organization_id'=>'33333333-3333-4333-8333-333333333333']);
            $this->internal('PUT','person-fields',['version'=>0,'fields'=>[]],$other)->assertForbidden();
            $old=$version;
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields,'delegated_roles'=>[]],$this->p)->assertOk(); $version++;
            $this->internal('PUT','person-fields',['version'=>$old,'fields'=>$fields],$delegate)->assertForbidden();
            $this->internal('PUT','person-fields',['version'=>$version,'fields'=>$fields],$delegate)->assertForbidden();
            $this->internal('PUT','person-filter-settings',['version'=>0,'base'=>[],'custom'=>[]],$delegate)->assertForbidden();
        }
        $this->assertSame(12,DB::table('outbox_events')->where('action','person_fields.updated')->count());
        $this->internal('GET','person-fields',[],$this->p)->assertOk()->assertJsonPath('data.version',12)->assertJsonCount(4,'data.fields');
    }
    public function test_grants_are_validated_versioned_and_invalidate_stale_person_forms(): void {
        foreach ([['admin'],['superadmin'],['viewer','viewer'],['unknown']] as $roles) $this->internal('PUT','person-fields',['version'=>0,'fields'=>[],'delegated_roles'=>$roles],$this->p)->assertUnprocessable();
        $this->internal('PUT','person-fields',['version'=>0,'fields'=>[],'delegated_roles'=>['viewer']],$this->p)->assertOk();
        $this->internal('PUT','person-fields',['version'=>0,'fields'=>[],'delegated_roles'=>[]],$this->p)->assertConflict();
        $data=['document_type'=>'CC','document_number'=>'DELEGATION-1','first_names'=>'Prueba','status'=>'pending','schema_version'=>0,'authorization_basis'=>'Prueba','authorization_purpose'=>'Validar versión'];
        $this->internal('POST','persons',$data,$this->p)->assertConflict();
        $this->internal('POST','persons',array_replace($data,['schema_version'=>1]),array_replace($this->p,['role'=>'viewer']))->assertForbidden();
        $this->internal('POST','persons',array_replace($data,['schema_version'=>1]),$this->p)->assertOk();
    }
}
