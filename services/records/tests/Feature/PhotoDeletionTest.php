<?php
namespace Tests\Feature;
use App\Application\PhotoDeletionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PhotoDeletionTest extends TestCase {
    use RefreshDatabase, SignedRequests;
    public function test_deletion_queues_atomically_and_retries_until_positive_acknowledgement(): void {
        $p=['organization_id'=>'11111111-1111-4111-8111-111111111111','user_id'=>'22222222-2222-4222-8222-222222222222','role'=>'admin'];
        $id=$this->internal('POST','persons',['document_type'=>'CC','document_number'=>'DELETE-PHOTO','first_names'=>'Synthetic','status'=>'pending','authorization_basis'=>'Test','authorization_purpose'=>'Test'],$p)->assertOk()->json('data.id');
        $this->internal('DELETE',"persons/$id",['version'=>2,'confirmed'=>true],$p)->assertConflict();
        $this->assertSame(0,DB::table('photo_deletions')->count());
        $this->internal('DELETE',"persons/$id",['version'=>1,'confirmed'=>true],$p)->assertOk();
        $job=DB::table('photo_deletions')->sole();
        $this->assertSame($p['organization_id'],$job->organization_id);
        $this->assertSame($id,$job->person_id);
        Http::fakeSequence()->push([],503)->push(['data'=>['accepted'=>false]])->push(['data'=>['accepted'=>true]]);
        $publisher=app(PhotoDeletionPublisher::class);
        $this->assertSame('retry',$publisher->publishOne($job->id));
        $this->assertSame('skipped',$publisher->publishOne($job->id));
        $this->travel(2)->minutes();
        $this->assertSame('retry',$publisher->publishOne($job->id));
        $this->assertNull(DB::table('photo_deletions')->value('delivered_at'));
        $this->travel(6)->minutes();
        $this->assertSame('delivered',$publisher->publishOne($job->id));
        $this->assertSame('skipped',$publisher->publishOne($job->id));
        Http::assertSentCount(3);
        Http::assertSent(function($r)use($p,$id){
            $claims=json_decode(base64_decode(explode('.',$r->header('X-SRD-Context')[0])[0]),true);
            return str_ends_with($r->url(),'photo-deletions/'.$id)&&$claims['iss']==='records'&&$claims['aud']==='files'&&$claims['context']['organization_id']===$p['organization_id'];
        });
    }
}
