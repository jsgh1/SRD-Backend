<?php
namespace Tests\Feature;
use Srd\Testing\SignedRequests;
use SrdFiles\PhotoStorage;
use Tests\TestCase;
final class PhotoDeletionTest extends TestCase {
    use SignedRequests;
    public function test_only_records_can_send_deletion_and_body_never_supplies_authority(): void {
        $id='11111111-1111-4111-8111-111111111111';
        $p=['organization_id'=>$id,'user_id'=>$id,'correlation_id'=>$id];
        $storage=$this->mock(PhotoStorage::class);
        $storage->shouldReceive('purgePerson')->once()->with($p,$id);
        $path='photo-deletions/'.$id;
        $this->postJson('/internal/v1/'.$path)->assertUnauthorized();
        foreach(['gateway','identity','configuration','audit'] as $issuer)$this->internal('POST',$path,[],$p,$issuer)->assertForbidden();
        $this->internal('POST',$path,$p,[],'records')->assertForbidden();
        $this->internal('POST',$path,['organization_id'=>'forged'],$p,'records')->assertOk()->assertJsonPath('data.accepted',true);
    }
}
