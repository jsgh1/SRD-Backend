<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;
final class PersonPositionsTest extends TestCase {
    use RefreshDatabase, SignedRequests;
    private array $p = ['organization_id'=>'11111111-1111-4111-8111-111111111111','user_id'=>'22222222-2222-4222-8222-222222222222','role'=>'admin'];
    private function person(array $extra = []): array {
        return array_replace(['document_type'=>'CC','document_number'=>'CARGO-1','first_names'=>'Prueba','status'=>'pending','position_code'=>'president','authorization_basis'=>'Prueba','authorization_purpose'=>'Validación local'], $extra);
    }
    public function test_legacy_catalog_only_recovers_english_for_unchanged_standard_positions(): void {
        $items = array_map(function ($item) { unset($item['label_en']); return $item; }, app(\App\Application\PersonPositions::class)->defaults());
        $items[] = ['code'=>'vocal','label'=>'Vocal local','active'=>true];
        DB::table('person_position_catalogs')->insert(['organization_id'=>$this->p['organization_id'],'version'=>1,
            'items'=>json_encode($items, JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
        $this->internal('GET','person-positions',[],$this->p)->assertOk()
            ->assertJsonPath('data.items.0.label_en','President')->assertJsonMissingPath('data.items.6.label_en');
        $this->internal('PUT','person-positions',['version'=>1,'items'=>$items],$this->p)->assertUnprocessable();
    }
    public function test_catalog_permissions_scope_validation_and_versions(): void {
        $items = app(\App\Application\PersonPositions::class)->defaults();
        foreach (Access::ROLES as $role) {
            $p = array_replace($this->p, ['role'=>$role]);
            $this->internal('GET','person-positions', [], $p)->assertOk()->assertJsonCount(6,'data.items');
            if (!in_array($role,['admin','superadmin'])) $this->internal('PUT','person-positions',['version'=>0,'items'=>$items],$p)->assertForbidden();
        }
        $items[] = ['code'=>'vocal','label'=>'Vocal','label_en'=>'Committee member','active'=>true];
        $withoutEnglish = $items;
        unset($withoutEnglish[6]['label_en']);
        $this->internal('PUT','person-positions',['version'=>0,'items'=>$withoutEnglish],$this->p)->assertUnprocessable();
        $this->internal('PUT','person-positions',['version'=>0,'items'=>$items],$this->p)->assertOk()->assertJsonPath('data.version',1);
        $this->internal('PUT','person-positions',['version'=>0,'items'=>$items],$this->p)->assertConflict();
        $other = array_replace($this->p, ['organization_id'=>'33333333-3333-4333-8333-333333333333']);
        $this->internal('GET','person-positions',[],$other)->assertOk()->assertJsonPath('data.version',0)->assertJsonCount(6,'data.items');
        $this->internal('POST','persons',$this->person(['position_code'=>'vocal']),$other)->assertUnprocessable();
        foreach ([array_slice($items,1), [...$items,$items[0]], array_map(fn($i)=>array_replace($i,['active'=>false]),$items), array_replace($items,[0=>['code'=>'bad.path','label'=>'X','active'=>true]])] as $invalid) {
            $this->internal('PUT','person-positions',['version'=>1,'items'=>$invalid],$this->p)->assertUnprocessable();
        }
        $this->assertSame(1, DB::table('outbox_events')->where('action','person_positions.updated')->count());
    }
    public function test_history_inactive_options_and_stale_person_forms(): void {
        $id = $this->internal('POST','persons',$this->person(),$this->p)->assertOk()->json('data.id');
        $items = app(\App\Application\PersonPositions::class)->defaults();
        $items[0]['label']='Presidencia'; $items[0]['label_en']='Presidency'; $items[0]['active']=false;
        $items[]=['code'=>'vocal','label'=>'Vocal histórico','label_en'=>'Historical committee member','active'=>true];
        $this->internal('PUT','person-positions',['version'=>0,'items'=>$items],$this->p)->assertOk();
        $this->internal('PATCH','persons/'.$id,$this->person(['version'=>1]),$this->p)->assertConflict();
        $this->internal('PATCH','persons/'.$id,$this->person(['version'=>1,'positions_version'=>1]),$this->p)->assertOk();
        $this->internal('GET','persons/'.$id,[],$this->p)->assertOk()->assertJsonPath('data.position_label','Presidente')->assertJsonPath('data.position_label_en','President');
        $this->internal('POST','persons',$this->person(['document_number'=>'CARGO-2','positions_version'=>1]),$this->p)->assertUnprocessable();
        $this->internal('PATCH','persons/'.$id,$this->person(['version'=>2,'positions_version'=>1,'position_code'=>'vocal']),$this->p)->assertOk();
        $this->internal('GET','persons/'.$id,[],$this->p)->assertOk()->assertJsonPath('data.position_label','Vocal histórico')->assertJsonPath('data.position_label_en','Historical committee member');
        $this->internal('PATCH','persons/'.$id,$this->person(['version'=>3,'positions_version'=>1,'position_code'=>null]),$this->p)->assertOk();
        $this->internal('GET','persons/'.$id,[],$this->p)->assertOk()->assertJsonPath('data.position_label',null)->assertJsonPath('data.position_label_en',null)->assertJsonPath('data.position_code',null);
    }
}
