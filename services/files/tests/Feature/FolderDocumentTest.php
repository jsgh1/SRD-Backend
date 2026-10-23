<?php
namespace Tests\Feature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use SrdFiles\{FolderDocumentStore,OfficeDocumentGate,OfficePreview,PdfPreview,ImageGate,AudioGate,Mp3Gate,PdfGate,Scanner,ScannerUnavailable};
use Tests\TestCase;
final class FolderDocumentTest extends TestCase {
 use SignedRequests;
 private string $dir;
 private array $p=['organization_id'=>'11111111-1111-4111-8111-111111111111','user_id'=>'22222222-2222-4222-8222-222222222222','session_id'=>'33333333-3333-4333-8333-333333333333','role'=>'admin'];
 protected function setUp():void {
  parent::setUp();
  (require database_path('migrations/2026_09_27_000001_create_internal_folders.php'))->up();
  (require database_path('migrations/2026_10_08_000001_add_folder_name_en.php'))->up();
  (require database_path('migrations/2026_09_27_000002_create_folder_documents.php'))->up();
  (require database_path('migrations/2026_09_27_000003_add_folder_document_version.php'))->up();
  (require database_path('migrations/2026_09_27_000004_add_folder_document_deletion.php'))->up();
  (require database_path('migrations/2026_09_27_000005_create_folder_access.php'))->up();
  (require database_path('migrations/2026_09_28_000006_add_folder_reader_memberships.php'))->up();
  Schema::create('file_quotas',function(Blueprint $t){$t->uuid('organization_id')->primary();$t->unsignedBigInteger('reserved_bytes')->default(0);});
  Schema::create('outbox_events',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('organization_id');$t->uuid('actor_id');$t->string('action');$t->uuid('resource_id');$t->string('result');$t->uuid('correlation_id');$t->dateTime('occurred_at');$t->integer('attempts');$t->dateTime('next_attempt_at');});
  $this->dir=storage_path('app/document-test-'.Str::uuid());mkdir($this->dir,0700,true);
  $scanner=new class implements Scanner {public function assertClean(string $path):void{}};
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,new ImageGate($this->dir,$scanner)));
  $this->app->instance(OfficePreview::class,new OfficePreview($this->dir));
 }
 protected function tearDown():void {foreach(glob($this->dir.'/*') as $p)unlink($p);rmdir($this->dir);parent::tearDown();}
 private function bytes(string $ext='docx',string $extra=''):string {
  $prefix=$ext==='docx'?'word/document':'xl/workbook';
  $mime=$ext==='docx'?'wordprocessingml.document':'spreadsheetml.sheet';
  $main=$ext==='docx'?'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>SRD</w:t></w:r></w:p>'.$extra.'</w:body></w:document>':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$extra.'</workbook>';
  $path=$this->dir.'/fixture';$z=new \ZipArchive;$z->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
  $z->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$prefix.'.xml" ContentType="application/vnd.openxmlformats-officedocument.'.$mime.'.main+xml"/></Types>');
  $z->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$prefix.'.xml"/></Relationships>');
  $z->addFromString($prefix.'.xml',$main);$z->close();$b=file_get_contents($path);unlink($path);return $b;
 }
 private function body(string $bytes):array{return ['id'=>(string)Str::uuid(),'folder_id'=>null,'name'=>'Acta.docx','content'=>base64_encode($bytes)];}
 private function wavBytes():string {
  $samples=str_repeat("\0\0",80);
  return 'RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '.pack('V',16).pack('vvVVvv',1,1,8000,16000,2,16)
    .'data'.pack('V',strlen($samples)).$samples;
 }
 private function mp3Bytes():string {
  $frame="\xff\xfb\x90\x00".str_repeat("\0",413);
  return str_repeat($frame,3);
 }
 private function pdfBytes():string {
  $content="BT /F1 12 Tf 50 80 Td (SRD) Tj ET\n";
  $objects=[
   '<< /Type /Catalog /Pages 2 0 R >>',
   '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
   '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 120] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
   "<< /Length ".strlen($content)." >>\nstream\n".$content.'endstream',
   '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  $pdf="%PDF-1.4\n";$offsets=[];
  foreach($objects as $index=>$object){$offsets[]=strlen($pdf);$pdf.=($index+1)." 0 obj\n".$object."\nendobj\n";}
  $xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
  foreach($offsets as $offset)$pdf.=sprintf("%010d 00000 n \n",$offset);
  return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
 }
 private function sheetBytes():string {
  $path=$this->dir.'/sheet-fixture';$zip=new \ZipArchive;$zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
  $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Informe" sheetId="1" r:id="r1"/><sheet name="Anexo" sheetId="2" r:id="r2"/></sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
  $rows='<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1"><v>120</v></c><c r="D1" t="inlineStr"><is><t>&lt;b&gt;texto&lt;/b&gt;</t></is></c><c r="M1"><v>999</v></c></row>';
  for($i=2;$i<=52;$i++)$rows.='<row r="'.$i.'"><c r="B'.$i.'" t="b"><v>1</v></c></row>';
  $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
  $zip->addFromString('xl/worksheets/sheet2.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Solo anexo</t></is></c></row></sheetData></worksheet>');
  $zip->addFromString('xl/sharedStrings.xml','<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nombre</t></si></sst>');
  $zip->close();$bytes=file_get_contents($path);unlink($path);return $bytes;
 }
 private function jpeg():string {
  $image=imagecreatetruecolor(3,2);imagefill($image,0,0,imagecolorallocate($image,220,32,24));
  ob_start();imagejpeg($image,null,90);$bytes=ob_get_clean();imagedestroy($image);
  return $bytes;
 }
 public function test_pdf_is_scanned_private_and_rejects_active_or_incompatible_content():void {
  $scanner=new class implements Scanner {public int $calls=0;public function assertClean(string $path):void{$this->calls++;}};
  $runner=static function(array $command):string {
   if($command[0]==='/usr/bin/pdfdetach')return "0 embedded files\n";
   if(in_array('-js',$command,true))return '';
   return "Pages: 1\nEncrypted: no\nJavaScript: no\nForm: none\n";
  };
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,
   null,null,new PdfGate($scanner,$this->dir,$runner)));
  $bytes=$this->pdfBytes();$body=$this->body($bytes);$body['name']='Acta.pdf';
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk()->assertJsonPath('data.mime','application/pdf');
  $this->assertSame(1,$scanner->calls);
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$this->p)->assertOk()
   ->assertJsonPath('data.content',base64_encode($bytes));
  $this->internal('PATCH','folder-documents/'.$body['id'],['name'=>'Informe.pdf','version'=>1],$this->p)->assertOk();
  $bad=$this->body($this->bytes());$bad['name']='Falso.pdf';
  $this->internal('POST','folder-documents',$bad,$this->p)->assertUnprocessable();
  $bad=$this->body(str_replace('%%EOF','/JavaScript %%EOF',$bytes));$bad['name']='Activo.pdf';
  $this->internal('POST','folder-documents',$bad,$this->p)->assertUnprocessable();
  $this->assertSame(1,$scanner->calls);
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$viewer)->assertForbidden();
 }
 public function test_pdf_parser_rejects_encryption_scripts_and_attachments_without_leaving_quarantine():void {
  $scanner=new class implements Scanner {public int $calls=0;public function assertClean(string $path):void{$this->calls++;}};
  foreach(['encrypted','script','attachment'] as $case){
   $runner=static function(array $command)use($case):string {
    if($command[0]==='/usr/bin/pdfdetach')return $case==='attachment'?"1 embedded files\n":"0 embedded files\n";
    if(in_array('-js',$command,true))return $case==='script'?"app.alert(1)\n":'';
    return "Pages: 1\nEncrypted: ".($case==='encrypted'?'yes':'no')."\nJavaScript: no\nForm: none\n";
   };
   try {(new PdfGate($scanner,$this->dir,$runner))->inspect('Prueba.pdf',$this->pdfBytes());$this->fail('El PDF debía rechazarse.');}
   catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('content',$e->errors());}
   $this->assertSame([] ,glob($this->dir.'/pdf-*'));
  }
  $this->assertSame(3,$scanner->calls);
 }
 public function test_pdf_preview_is_a_bounded_png_with_current_read_permission_and_cleanup():void {
  $scanner=new class implements Scanner {public function assertClean(string $path):void {}};
  $gateRunner=static function(array $command):string {
   if($command[0]==='/usr/bin/pdfdetach')return "0 embedded files\n";
   if(in_array('-js',$command,true))return '';
   return "Pages: 1\nEncrypted: no\nJavaScript: no\nForm: none\n";
  };
  $render=static function(array $command):string {
   if($command[0]==='/usr/bin/pdfinfo')return "Pages: 1\n";
   $image=imagecreatetruecolor(2,2);
   imagefill($image,0,0,imagecolorallocate($image,255,255,255));
   imagepng($image,$command[count($command)-1].'.png');imagedestroy($image);
   return '';
  };
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(
   new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,null,null,new PdfGate($scanner,$this->dir,$gateRunner)));
  $this->app->instance(PdfPreview::class,new PdfPreview($this->dir,$render));
  $body=$this->body($this->pdfBytes());$body['name']='Vista.pdf';
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $path='folder-documents/'.$body['id'].'/preview';
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $preview=$this->internal('GET',$path,[],$viewer)->assertOk()->assertJsonMissingPath('data.content')
   ->assertJsonPath('data.preview.format','image')->assertJsonPath('data.preview.mime','image/png')
   ->assertJsonPath('data.preview.width',2)->assertJsonPath('data.preview.height',2)
   ->assertJsonPath('data.preview.page',1)->assertJsonPath('data.preview.pages',1)->json('data.preview.content');
  $this->assertStringStartsWith("\x89PNG\r\n\x1a\n",base64_decode($preview,true));
  $this->assertSame([],glob($this->dir.'/pdf-view-*'));
  $this->internal('GET',$path,['sheet'=>2],$viewer)->assertUnprocessable();
  $this->internal('GET',$path,['page'=>2],$viewer)->assertUnprocessable();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk();
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
  $this->app->instance(PdfPreview::class,new PdfPreview($this->dir,static function(array $command):string {
   if($command[0]==='/usr/bin/pdfinfo')return "Pages: 1\n";
   file_put_contents($command[count($command)-1].'.png','not-an-image');
   return '';
  }));
  $this->internal('GET',$path,[],$this->p)->assertStatus(503);
  $this->assertSame([],glob($this->dir.'/pdf-view-*'));
 }
 public function test_folder_quota_is_private_scoped_and_counts_reserved_bytes():void {
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $other=array_replace($this->p,['organization_id'=>(string)Str::uuid()]);
  $this->getJson('/internal/v1/folder-quota')->assertUnauthorized();
  $this->internal('GET','folder-quota',[],$viewer)->assertForbidden();
  $this->internal('GET','folder-quota',[],$this->p)->assertOk()
    ->assertJsonPath('data.used_bytes',0)->assertJsonPath('data.limit_bytes',100000)
    ->assertJsonPath('data.available_bytes',100000);
  $bytes=$this->bytes();$body=$this->body($bytes);
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $this->internal('GET','folder-quota',[],$this->p)->assertOk()
    ->assertJsonPath('data.used_bytes',strlen($bytes))
    ->assertJsonPath('data.available_bytes',100000-strlen($bytes));
  $this->internal('GET','folder-quota',[],$other)->assertOk()->assertJsonPath('data.used_bytes',0);
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $this->internal('GET','folder-quota',[],$viewer)->assertForbidden();
 }
 public function test_docx_preview_is_plain_text_bounded_private_and_integrity_checked():void {
  $extra='<w:p><w:r><w:t>Acta &amp; acuerdos</w:t></w:r></w:p>'.str_repeat('<w:p><w:r><w:t>Línea</w:t></w:r></w:p>',201);
  $body=$this->body($this->bytes('docx',$extra));
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $path='folder-documents/'.$body['id'].'/preview';
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
  $this->internal('GET',$path,[],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $preview=$this->internal('GET',$path,[],$viewer)->assertOk()->assertJsonMissingPath('data.content')
    ->assertJsonPath('data.preview.format','plain_text')->assertJsonPath('data.preview.truncated',true)->json('data.preview');
  $this->assertStringStartsWith("SRD\nActa & acuerdos\nLínea",$preview['text']);
  $this->assertLessThanOrEqual(200,count(explode("\n",$preview['text'])));
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.downloaded')->count());
  $sheet=$this->body($this->bytes('xlsx'));$sheet['name']='Datos.xlsx';
  $this->internal('POST','folder-documents',$sheet,$this->p)->assertOk();
  $this->internal('GET','folder-documents/'.$sheet['id'].'/preview',[],$this->p)->assertOk()
    ->assertJsonPath('data.preview.format','grid')->assertJsonPath('data.preview.rows',[]);
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk();
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
  $row=DB::table('folder_documents')->where('id',$body['id'])->first();
  file_put_contents($this->dir.'/'.$row->blob_id.'.bin','tampered');
  $this->internal('GET',$path,[],$this->p)->assertStatus(503);
 }
 public function test_xlsx_preview_is_bounded_escaped_and_revocable():void {
  $sheet=$this->body($this->sheetBytes());$sheet['name']='Informe.xlsx';
  $this->internal('POST','folder-documents',$sheet,$this->p)->assertOk();
  $path='folder-documents/'.$sheet['id'].'/preview';
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
  $this->internal('GET',$path,[],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $result=$this->internal('GET',$path,[],$viewer)->assertOk()->assertJsonMissingPath('data.content')
    ->assertJsonPath('data.preview.format','grid')->assertJsonPath('data.preview.sheet','Informe')
    ->assertJsonPath('data.preview.sheet_index',1)->assertJsonPath('data.preview.sheets.1.name','Anexo')
    ->assertJsonPath('data.preview.columns',4)->assertJsonPath('data.preview.truncated',true)
    ->assertJsonPath('data.preview.rows.0.cells.0','Nombre')
    ->assertJsonPath('data.preview.rows.0.cells.1','')
    ->assertJsonPath('data.preview.rows.0.cells.2','120')
    ->assertJsonPath('data.preview.rows.0.cells.3','<b>texto</b>')
    ->assertJsonPath('data.preview.rows.1.cells.1','VERDADERO')->json('data.preview');
  $this->assertCount(50,$result['rows']);
  $this->internal('GET',$path,['sheet'=>2],$viewer)->assertOk()->assertJsonMissingPath('data.content')
    ->assertJsonPath('data.preview.sheet','Anexo')->assertJsonPath('data.preview.sheet_index',2)
    ->assertJsonPath('data.preview.rows.0.cells.0','Solo anexo')->assertJsonPath('data.preview.truncated',false);
  $this->internal('GET',$path,['sheet'=>3],$viewer)->assertUnprocessable();
  $this->internal('GET',$path,['sheet'=>33],$viewer)->assertUnprocessable();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk();
  $this->internal('GET',$path,[],$viewer)->assertForbidden();
 }
 public function test_folder_read_delegation_is_scoped_revocable_and_never_grants_writes():void {
  $body=$this->body($this->bytes());
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $other=array_replace($viewer,['organization_id'=>(string)Str::uuid()]);
  $this->internal('GET','folder-access',[],$this->p)->assertOk()->assertJsonPath('data.can_read',true)->assertJsonPath('data.version',0);
  $this->internal('GET','folders',[],$viewer)->assertForbidden();
  $this->internal('GET','folder-documents',[],$viewer)->assertForbidden();
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['superadmin']],$this->p)->assertUnprocessable();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer','viewer']],$this->p)->assertUnprocessable();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)
    ->assertOk()->assertJsonPath('data.version',1)->assertJsonPath('data.reader_roles.0','viewer');
  $this->internal('GET','folder-access',[],$viewer)->assertOk()->assertJsonPath('data.can_read',true)->assertJsonPath('data.reader_roles',[]);
  $this->internal('GET','folders',[],$viewer)->assertOk();
  $this->internal('GET','folder-documents',[],$viewer)->assertOk()->assertJsonPath('data.total',1);
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$viewer)->assertOk();
  $this->internal('GET','folders',[],$other)->assertForbidden();
  $this->internal('GET','folder-documents',[],$other)->assertForbidden();
  $this->internal('POST','folders',['id'=>(string)Str::uuid(),'parent_id'=>null,'name'=>'Denegada'],$viewer)->assertForbidden();
  $this->internal('POST','folder-documents',array_replace($body,['id'=>(string)Str::uuid()]),$viewer)->assertForbidden();
  $this->internal('PATCH','folder-documents/'.$body['id'],['name'=>'Denegada.docx','version'=>1],$viewer)->assertForbidden();
  $this->internal('DELETE','folder-documents/'.$body['id'],['version'=>1,'confirm'=>true],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>[]],$this->p)->assertConflict();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>['viewer']],$this->p)->assertOk()->assertJsonPath('data.version',1);
  $this->assertSame(1,DB::table('outbox_events')->where('action','folder.access_updated')->count());
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk()->assertJsonPath('data.version',2);
  $this->internal('GET','folders',[],$viewer)->assertForbidden();
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$viewer)->assertForbidden();
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$this->p)->assertOk();
  $this->assertSame(2,DB::table('outbox_events')->where('action','folder.access_updated')->count());
 }
 public function test_individual_folder_reader_is_bound_to_membership_version():void {
  $body=$this->body($this->bytes());
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $membership=(string)Str::uuid();
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid(),
    'membership_id'=>$membership,'membership_version'=>3]);
  $other=array_replace($viewer,['user_id'=>(string)Str::uuid(),'membership_id'=>(string)Str::uuid()]);
  $grant=['id'=>$membership,'version'=>3];
  $this->internal('GET','folder-access',[],$viewer)->assertJsonPath('data.can_read',false);
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>[],
    'reader_memberships'=>[$grant]],$this->p)->assertOk()
    ->assertJsonPath('data.reader_memberships.0.id',$membership);
  $this->internal('GET','folder-access',[],$viewer)->assertJsonPath('data.can_read',true)
    ->assertJsonPath('data.reader_memberships',[]);
  $this->internal('GET','folder-documents/'.$body['id'].'/preview',[],$viewer)->assertOk();
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$other)->assertForbidden();
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],array_replace($viewer,['membership_version'=>4]))->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[],
    'reader_memberships'=>[['id'=>$membership,'version'=>4]]],$this->p)->assertOk()
    ->assertJsonPath('data.version',1)->assertJsonPath('data.reader_memberships.0.version',3);
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],array_replace($viewer,['organization_id'=>(string)Str::uuid()]))->assertForbidden();
  $this->internal('POST','folders',['id'=>(string)Str::uuid(),'parent_id'=>null,'name'=>'Denegada'],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[],
    'reader_memberships'=>[]],$this->p)->assertOk()->assertJsonPath('data.can_read',true);
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$viewer)->assertForbidden();
  $this->assertSame(2,DB::table('outbox_events')->where('action','folder.access_updated')->count());
 }
 public function test_document_search_is_literal_scoped_and_follows_read_permission():void {
  $folder=(string)Str::uuid();
  $this->internal('POST','folders',['id'=>$folder,'parent_id'=>null,'name'=>'Actas','name_en'=>'Minutes'],$this->p)->assertOk();
  $child=(string)Str::uuid();
  $this->internal('POST','folders',['id'=>$child,'parent_id'=>$folder,'name'=>'2026','name_en'=>'Year 2026'],$this->p)->assertOk();
  $match=$this->body($this->bytes());$match['folder_id']=$child;$match['name']='Acta 50%_!.docx';
  $other=$this->body($this->bytes());$other['name']='Acta 50AA.docx';
  $this->internal('POST','folder-documents',$match,$this->p)->assertOk();
  $this->internal('POST','folder-documents',$other,$this->p)->assertOk();
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $this->internal('GET','folder-documents/search',['q'=>'50%_!'],$viewer)->assertForbidden();
  $this->internal('GET','folder-documents/search',['q'=>'50%_!'],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))
    ->assertOk()->assertJsonPath('data.total',0);
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $this->internal('GET','folder-documents/search',['q'=>'50%_!'],$viewer)->assertOk()
    ->assertJsonPath('data.total',1)->assertJsonPath('data.items.0.id',$match['id'])
    ->assertJsonPath('data.items.0.folder_name','2026')->assertJsonPath('data.items.0.folder_path','Inicio / Actas / 2026')
    ->assertJsonPath('data.items.0.folder_name_en','Year 2026')->assertJsonPath('data.items.0.folder_path_en','Home / Minutes / Year 2026')
    ->assertJsonMissingPath('data.items.0.content');
  $archive=(string)Str::uuid();
  $this->internal('POST','folders',['id'=>$archive,'parent_id'=>null,'name'=>'Archivo'],$this->p)->assertOk();
  $this->internal('POST','folders/'.$folder.'/move',['parent_id'=>$archive,'version'=>1],$this->p)->assertOk();
  $this->internal('GET','folder-documents/search',['q'=>'50%_!'],$viewer)->assertOk()
    ->assertJsonPath('data.items.0.folder_path','Inicio / Archivo / Actas / 2026')
    ->assertJsonPath('data.items.0.folder_path_en','Home / Archivo / Minutes / Year 2026');
  $this->internal('GET','folder-documents/search',['q'=>'  '],$viewer)->assertUnprocessable();
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk();
  $this->internal('GET','folder-documents/search',['q'=>'50%_!'],$viewer)->assertForbidden();
 }
 public function test_private_wav_is_structurally_validated_scanned_and_revocable():void {
  $scanner=new class implements Scanner {public int $calls=0;public function assertClean(string $path):void{$this->calls++;}};
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(
    new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,null,new AudioGate($scanner,$this->dir)));
  $bytes=$this->wavBytes();$body=$this->body($bytes);$body['name']='Mensaje.wav';
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk()->assertJsonPath('data.mime','audio/wav')
    ->assertJsonPath('data.sha256',hash('sha256',$bytes));
  $this->assertSame(1,$scanner->calls);
  $viewer=array_replace($this->p,['role'=>'viewer','user_id'=>(string)Str::uuid()]);
  $path='folder-documents/'.$body['id'];
  $this->internal('GET',$path.'/download',[],$viewer)->assertForbidden();
  $this->internal('PUT','folder-access',['version'=>0,'reader_roles'=>['viewer']],$this->p)->assertOk();
  $this->internal('GET',$path.'/download',[],$viewer)->assertOk()->assertJsonPath('data.content',base64_encode($bytes));
  $this->internal('PATCH',$path,['name'=>'Cambiado.mp3','version'=>1],$this->p)->assertUnprocessable();
  $this->internal('PATCH',$path,['name'=>'Mensaje nuevo.WAV','version'=>1],$this->p)->assertOk()->assertJsonPath('data.version',2);
  $this->internal('PUT','folder-access',['version'=>1,'reader_roles'=>[]],$this->p)->assertOk();
  $this->internal('GET',$path.'/download',[],$viewer)->assertForbidden();
  $invalid=substr_replace($bytes,pack('v',3),20,2);
  foreach ([$invalid,$bytes.'JUNK',substr_replace($bytes,'LIST',36,4)] as $bad) {
   $upload=$this->body($bad);$upload['name']='Rechazado '.Str::uuid().'.wav';
   $this->internal('POST','folder-documents',$upload,$this->p)->assertUnprocessable();
  }
  $this->assertSame(1,$scanner->calls);
  $this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->internal('GET',$path.'/download',[],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
 }
 public function test_mp3_strips_id3_scans_both_versions_and_rejects_malformed_frames():void {
  $scanner=new class implements Scanner {
   public array $hashes=[];
   public function assertClean(string $path):void {$this->hashes[]=hash_file('sha256',$path);}
  };
  $decoded=[];
  $decoder=static function(string $path)use(&$decoded):void {$decoded[]=hash_file('sha256',$path);};
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(
   new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,null,null,null,new Mp3Gate($scanner,$this->dir,$decoder)));
  $plain=$this->mp3Bytes();
  $tagged='ID3'."\x03\x00\x00\x00\x00\x00\x04".'META'.$plain.'TAG'.str_repeat('x',125);
  $body=$this->body($tagged);$body['name']='Nota.mp3';
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk()
   ->assertJsonPath('data.mime','audio/mpeg')->assertJsonPath('data.bytes',strlen($plain))
   ->assertJsonPath('data.sha256',hash('sha256',$plain));
  $this->assertSame([hash('sha256',$tagged),hash('sha256',$plain)],$scanner->hashes);
  $this->assertSame([hash('sha256',$plain)],$decoded);
  $this->assertSame([],glob($this->dir.'/mp3-*'));
  $this->internal('GET','folder-documents/'.$body['id'].'/download',[],$this->p)->assertOk()
   ->assertJsonPath('data.content',base64_encode($plain));
  $this->internal('GET','folder-quota',[],$this->p)->assertJsonPath('data.used_bytes',strlen($plain));
  $this->internal('PATCH','folder-documents/'.$body['id'],['name'=>'Nueva.mp3','version'=>1],$this->p)
   ->assertOk()->assertJsonPath('data.version',2);
  foreach ([$plain.'junk',substr($plain,0,-1),substr_replace($plain,"\0",1,1),
   str_replace("\x90\x00","\xf0\x00",$plain),$this->wavBytes()] as $bad) {
   $upload=$this->body($bad);$upload['name']='Rechazado '.Str::uuid().'.mp3';
   $this->internal('POST','folder-documents',$upload,$this->p)->assertUnprocessable();
  }
  $this->assertCount(2,$scanner->hashes);
  $recording=file_get_contents(base_path('../../tools/browser-tests/silence.mp3'));
  $tagSize=0;
  for($i=6;$i<10;$i++)$tagSize=($tagSize<<7)|ord($recording[$i]);
  $recordedFrames=substr($recording,10+$tagSize);
  $real=$this->body($recording);$real['name']='Audio real.mp3';
  $this->internal('POST','folder-documents',$real,$this->p)->assertOk()
   ->assertJsonPath('data.bytes',strlen($recordedFrames));
  $this->internal('GET','folder-documents/'.$real['id'].'/download',[],$this->p)->assertOk()
   ->assertJsonPath('data.content',base64_encode($recordedFrames));
  $this->assertSame([hash('sha256',$plain),hash('sha256',$recordedFrames)],$decoded);
 }
 public function test_mp3_decoder_failure_releases_quarantine_without_reserving_quota():void {
  $scanner=new class implements Scanner {public int $calls=0;public function assertClean(string $path):void {$this->calls++;}};
  $decoder=static function(string $path):void {
   throw \Illuminate\Validation\ValidationException::withMessages(['content'=>'Audio MP3 no decodificable.']);
  };
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(
   new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000,null,null,null,new Mp3Gate($scanner,$this->dir,$decoder)));
  $body=$this->body($this->mp3Bytes());$body['name']='Falso.mp3';
  $this->internal('POST','folder-documents',$body,$this->p)->assertUnprocessable();
  $this->assertSame(2,$scanner->calls);
  $this->assertSame([],glob($this->dir.'/mp3-*'));
  $this->assertSame(0,DB::table('folder_documents')->count());
  $this->assertSame(0,(int)(DB::table('file_quotas')->value('reserved_bytes')??0));
 }
 public function test_folder_images_are_normalized_scoped_versioned_and_count_only_png_bytes():void {
  $source=$this->jpeg();$body=$this->body($source);$body['name']='Reunión.jpeg';
  $response=$this->internal('POST','folder-documents',$body,$this->p)->assertOk()->assertJsonPath('data.name','Reunión.png')->assertJsonPath('data.mime','image/png');
  $stored=$response->json('data');$row=DB::table('folder_documents')->where('id',$body['id'])->first();
  $normalized=file_get_contents($this->dir.'/'.$row->blob_id.'.bin');
  $this->assertStringStartsWith("\x89PNG\r\n\x1a\n",$normalized);$this->assertNotSame($source,$normalized);
  $this->assertSame(hash('sha256',$normalized),$stored['sha256']);
  $this->assertSame(strlen($normalized),(int)$row->bytes);
  $this->assertSame(strlen($normalized),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk()->assertJsonPath('data.id',$body['id']);
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.created')->count());
  $path='folder-documents/'.$body['id'];
  $this->internal('GET',$path.'/download',[],$this->p)->assertOk()->assertJsonPath('data.content',base64_encode($normalized));
  $this->internal('GET',$path.'/download',[],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
  $this->internal('PATCH',$path,['name'=>'Otra.jpeg','version'=>1],$this->p)->assertUnprocessable();
  $this->internal('PATCH',$path,['name'=>'Otra.png','version'=>1],$this->p)->assertOk()->assertJsonPath('data.version',2);
  $this->internal('DELETE',$path,['version'=>2,'confirm'=>true],$this->p)->assertOk();
  $this->assertSame(strlen($normalized),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame(1,$this->app->make(FolderDocumentStore::class)->collect());
  $this->assertSame(0,(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertFileDoesNotExist($this->dir.'/'.$row->blob_id.'.bin');
  $bad=array_replace($body,['id'=>(string)Str::uuid(),'name'=>'Falsa.jpg','content'=>base64_encode($normalized)]);
  $this->internal('POST','folder-documents',$bad,$this->p)->assertUnprocessable();
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.created')->count());
 }
 public function test_rename_guards_version_names_scope_and_preserves_object_quota():void {
  $bytes=$this->bytes();$b=$this->body($bytes);
  $this->internal('POST','folder-documents',$b,$this->p)->assertOk()->assertJsonPath('data.version',1);
  $before=DB::table('folder_documents')->first();$path='folder-documents/'.$b['id'];
  $this->patchJson('/internal/v1/'.$path,['name'=>'Denied.docx','version'=>1])->assertUnauthorized();
  foreach(['registrar','treasurer','auditor','viewer'] as $role)$this->internal('PATCH',$path,['name'=>'Denied.docx','version'=>1],array_replace($this->p,['role'=>$role]))->assertForbidden();
  $this->internal('PATCH',$path,['name'=>'Denied.docx','version'=>1],$this->p,'identity')->assertForbidden();
  $this->internal('PATCH',$path,['name'=>'Other.docx','version'=>1],array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
  $this->internal('PATCH',$path,['name'=>'Report.docx'],$this->p)->assertUnprocessable();
  $this->internal('PATCH',$path,['name'=>'Report.docx','version'=>1,'padding'=>str_repeat('x',4096)],$this->p)->assertStatus(413);
  foreach(['bad.xlsx','bad.pdf','../bad.docx','.docx',"bad\x01.docx"] as $name)$this->internal('PATCH',$path,['name'=>$name,'version'=>1],$this->p)->assertUnprocessable();
  $this->internal('PATCH',$path,['name'=>'Acta.docx','version'=>1],$this->p)->assertOk()->assertJsonPath('data.version',1);
  $this->assertSame(1,DB::table('outbox_events')->count());
  $this->internal('PATCH',$path,['name'=>' Informe.DOCX ','version'=>1],$this->p)->assertOk()->assertJsonPath('data.name','Informe.DOCX')->assertJsonPath('data.version',2);
  $this->internal('PATCH',$path,['name'=>'Obsolete.docx','version'=>1],$this->p)->assertConflict();
  $other=$this->body($bytes);$other['name']='Occupied.docx';$this->internal('POST','folder-documents',$other,$this->p)->assertOk();
  $this->internal('PATCH',$path,['name'=>'occupied.docx','version'=>2],$this->p)->assertConflict();
  $this->assertSame(3,DB::table('outbox_events')->count());
  $after=DB::table('folder_documents')->where('id',$b['id'])->first();
  foreach(['id','folder_id','blob_id','sha256','bytes','created_by','created_at'] as $key)$this->assertSame($before->$key,$after->$key);
  $this->assertSame('Informe.DOCX',$after->name);$this->assertSame(2,(int)$after->version);
  $this->assertSame(2*strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->internal('GET',$path.'/download',[],$this->p)->assertOk()->assertJsonPath('data.name','Informe.DOCX')->assertJsonPath('data.content',base64_encode($bytes));
  $this->internal('POST','folder-documents',$b,$this->p)->assertConflict();
  $this->internal('PATCH',$path,['name'=>'Admin.docx','version'=>2],array_replace($this->p,['role'=>'superadmin']))->assertOk()->assertJsonPath('data.version',3);
  DB::table('folder_documents')->where('id',$other['id'])->update(['ready'=>false]);
  $this->internal('PATCH','folder-documents/'.$other['id'],['name'=>'Incomplete.docx','version'=>1],$this->p)->assertNotFound();
  $sheet=$this->body($this->bytes('xlsx'));$sheet['name']='Budget.xlsx';
  $this->internal('POST','folder-documents',$sheet,$this->p)->assertOk();
  $this->internal('PATCH','folder-documents/'.$sheet['id'],['name'=>'Budget final.XLSX','version'=>1],$this->p)
    ->assertOk()->assertJsonPath('data.version',2)->assertJsonPath('data.name','Budget final.XLSX');
  $this->internal('GET','folder-documents/'.$sheet['id'].'/download',[],$this->p)->assertOk()->assertJsonPath('data.content',$sheet['content']);
 }
 public function test_move_is_scoped_versioned_and_preserves_references_quota_and_download():void {
  $folder=(string)Str::uuid();$otherFolder=(string)Str::uuid();
  foreach([$folder,$otherFolder] as $id)$this->internal('POST','folders',['id'=>$id,'name'=>$id,'parent_id'=>null],$this->p)->assertOk();
  $bytes=$this->bytes();$b=$this->body($bytes);$this->internal('POST','folder-documents',$b,$this->p)->assertOk();
  $before=DB::table('folder_documents')->where('id',$b['id'])->first();$path='folder-documents/'.$b['id'].'/move';
  $payload=['folder_id'=>$folder,'version'=>1];$events=DB::table('outbox_events')->count();
  $this->postJson('/internal/v1/'.$path,$payload)->assertUnauthorized();
  foreach(['registrar','treasurer','auditor','viewer'] as $role)$this->internal('POST',$path,$payload,array_replace($this->p,['role'=>$role]))->assertForbidden();
  $this->internal('POST',$path,$payload,$this->p,'identity')->assertForbidden();
  $foreign=array_replace($this->p,['organization_id'=>(string)Str::uuid()]);$foreignFolder=(string)Str::uuid();
  $this->internal('POST','folders',['id'=>$foreignFolder,'name'=>'Foreign','parent_id'=>null],$foreign)->assertOk();
  $this->internal('POST',$path,$payload,$foreign)->assertNotFound();
  foreach([$foreignFolder,(string)Str::uuid()] as $dest)$this->internal('POST',$path,['folder_id'=>$dest,'version'=>1],$this->p)->assertNotFound();
  $this->internal('POST',$path,['version'=>1],$this->p)->assertUnprocessable();
  $this->internal('POST',$path,['folder_id'=>$folder],$this->p)->assertUnprocessable();
  $this->internal('POST',$path,$payload+['padding'=>str_repeat('x',4096)],$this->p)->assertStatus(413);
  $this->internal('POST',$path,['folder_id'=>null,'version'=>1],$this->p)->assertOk()->assertJsonPath('data.version',1);
  $this->assertSame($events+1,DB::table('outbox_events')->count()); // Only foreign-folder creation.
  $this->internal('POST',$path,$payload,$this->p)->assertOk()->assertJsonPath('data.folder_id',$folder)->assertJsonPath('data.version',2);
  $after=DB::table('folder_documents')->where('id',$b['id'])->first();
  foreach(['id','name','name_key','blob_id','sha256','bytes','mime','created_by','created_at'] as $key)$this->assertSame($before->$key,$after->$key);
  $this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->where('organization_id',$this->p['organization_id'])->value('reserved_bytes'));
  $this->internal('GET','folder-documents',[],$this->p)->assertOk()->assertJsonPath('data.total',0);
  $this->internal('GET','folder-documents',[],$this->p,'gateway',['folder_id'=>$folder])->assertOk()->assertJsonPath('data.items.0.id',$b['id']);
  $this->internal('GET','folder-documents/'.$b['id'].'/download',[],$this->p)->assertOk()->assertJsonPath('data.content',$b['content']);
  $this->internal('POST',$path,['folder_id'=>$otherFolder,'version'=>1],$this->p)->assertConflict();
  $occupied=$this->body($bytes);$occupied['folder_id']=$otherFolder;$occupied['name']='ACTA.docx';
  $this->internal('POST','folder-documents',$occupied,$this->p)->assertOk();$events=DB::table('outbox_events')->count();
  $this->internal('POST',$path,['folder_id'=>$otherFolder,'version'=>2],$this->p)->assertConflict();
  $this->assertSame($events,DB::table('outbox_events')->count());
  $this->assertSame($folder,DB::table('folder_documents')->where('id',$b['id'])->value('folder_id'));
  $this->internal('POST',$path,['folder_id'=>null,'version'=>2],array_replace($this->p,['role'=>'superadmin']))->assertOk()->assertJsonPath('data.version',3)->assertJsonPath('data.folder_id',null);
  $this->internal('PATCH','folder-documents/'.$b['id'],['name'=>'Obsolete.docx','version'=>2],$this->p)->assertConflict();
  DB::table('folder_documents')->where('id',$b['id'])->update(['ready'=>false]);
  $this->internal('POST',$path,['folder_id'=>$folder,'version'=>3],$this->p)->assertNotFound();
 }
 public function test_xlsx_rename_invalidates_pending_move_and_preserves_storage():void {
  $folder=(string)Str::uuid();
  $this->internal('POST','folders',['id'=>$folder,'name'=>'Informes','parent_id'=>null],$this->p)->assertOk();
  $bytes=$this->bytes('xlsx');$body=$this->body($bytes);$body['name']='Balance.xlsx';
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $path='folder-documents/'.$body['id'];$before=DB::table('folder_documents')->where('id',$body['id'])->first();
  $quota=(int)DB::table('file_quotas')->value('reserved_bytes');
  $this->internal('PATCH',$path,['name'=>'Balance final.XLSX','version'=>1],$this->p)->assertOk()->assertJsonPath('data.version',2);
  $events=DB::table('outbox_events')->count();
  $this->internal('POST',$path.'/move',['folder_id'=>$folder,'version'=>1],$this->p)->assertConflict();
  $this->assertNull(DB::table('folder_documents')->where('id',$body['id'])->value('folder_id'));
  $this->assertSame($events,DB::table('outbox_events')->count());
  $this->internal('POST',$path.'/move',['folder_id'=>$folder,'version'=>2],$this->p)->assertOk()
   ->assertJsonPath('data.version',3)->assertJsonPath('data.name','Balance final.XLSX')->assertJsonPath('data.folder_id',$folder);
  $after=DB::table('folder_documents')->where('id',$body['id'])->first();
  foreach(['id','blob_id','bytes','sha256','mime','created_by','created_at'] as $key)$this->assertSame($before->$key,$after->$key);
  $this->assertSame($folder,$after->parent_key);
  $this->assertSame($quota,(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame($bytes,file_get_contents($this->dir.'/'.$after->blob_id.'.bin'));
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.moved')->where('resource_id',$body['id'])->count());
  $this->internal('GET',$path.'/download',[],$this->p)->assertOk()->assertJsonPath('data.name','Balance final.XLSX')->assertJsonPath('data.content',$body['content']);
  $this->internal('POST',$path.'/move',['folder_id'=>null,'version'=>3],$this->p)->assertOk()->assertJsonPath('data.version',4);
  $this->assertSame('00000000-0000-0000-0000-000000000000',DB::table('folder_documents')->where('id',$body['id'])->value('parent_key'));
  $this->assertSame($quota,(int)DB::table('file_quotas')->value('reserved_bytes'));
 }
 public function test_private_upload_retry_download_and_integrity():void {
  $bytes=$this->bytes();$b=$this->body($bytes);
  $this->internal('POST','folder-documents',$b,$this->p)->assertOk()->assertJsonPath('data.sha256',hash('sha256',$bytes));
  $this->internal('POST','folder-documents',$b,$this->p)->assertOk();
  $this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));$this->assertSame(1,DB::table('outbox_events')->count());
  $this->internal('GET','folder-documents',[],$this->p)->assertOk()->assertJsonPath('data.total',1);
  $this->internal('GET','folder-documents/'.$b['id'].'/download',[],$this->p)->assertOk()->assertJsonPath('data.content',base64_encode($bytes));
  $foreign=array_replace($this->p,['organization_id'=>(string)Str::uuid()]);
  $this->internal('GET','folder-documents/'.$b['id'].'/download',[],$foreign)->assertNotFound();
  $this->internal('POST','folder-documents',array_replace($b,['id'=>(string)Str::uuid()]),$this->p)->assertConflict();
  $this->assertSame(1,DB::table('folder_documents')->count());
  $row=DB::table('folder_documents')->first();file_put_contents($this->dir.'/'.$row->blob_id.'.bin','corrupt');
  $this->internal('GET','folder-documents/'.$b['id'].'/download',[],$this->p)->assertStatus(503);
 }
 public function test_roles_and_fail_closed_validation():void {
  $this->getJson('/internal/v1/folder-documents')->assertUnauthorized();
  foreach(['registrar','treasurer','auditor','viewer'] as $role){$p=array_replace($this->p,['role'=>$role]);$this->internal('POST','folder-documents',$this->body($this->bytes()),$p)->assertForbidden();$this->internal('GET','folder-documents',[],$p)->assertForbidden();}
  $this->internal('GET','folder-documents',[],$this->p,'identity')->assertForbidden();
  $this->internal('GET','folder-documents',[],array_replace($this->p,['role'=>'superadmin']))->assertOk();
  foreach(['not zip',$this->bytes('docx','<w:fldSimple w:instr="active"/>')] as $bytes)$this->internal('POST','folder-documents',$this->body($bytes),$this->p)->assertUnprocessable();
  $b=$this->body($this->bytes('xlsx','<f>1+1</f>'));$b['name']='table.xlsx';$this->internal('POST','folder-documents',$b,$this->p)->assertUnprocessable();
  $b=$this->body($this->bytes('xlsx'));$b['name']='table.xlsx';$this->internal('POST','folder-documents',$b,$this->p)->assertOk();
  $scanner=new class implements Scanner {public function assertClean(string $path):void{throw new ScannerUnavailable;}};
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000));
  $this->internal('POST','folder-documents',$this->body($this->bytes()),$this->p)->assertStatus(503);
  $scanner=new class implements Scanner {public function assertClean(string $path):void{throw new \SrdFiles\ImageRejected;}};
  $this->app->instance(FolderDocumentStore::class,new FolderDocumentStore(new OfficeDocumentGate($scanner,$this->dir),$this->dir,100000));
  $this->internal('POST','folder-documents',$this->body($this->bytes()),$this->p)->assertUnprocessable();
  $this->assertSame(1,DB::table('folder_documents')->count());
 }
 public function test_exact_quota_accepts_once_and_completed_retry_needs_no_extra_space():void {
  $bytes=$this->bytes();$body=$this->body($bytes);
  DB::table('file_quotas')->insert(['organization_id'=>$this->p['organization_id'],'reserved_bytes'=>100000-strlen($bytes)]);
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $before=DB::table('folder_documents')->where('id',$body['id'])->first();
  $this->assertSame(100000,(int)DB::table('file_quotas')->value('reserved_bytes'));
  $other=array_replace($body,['id'=>(string)Str::uuid(),'name'=>'Otra.docx']);
  $this->internal('POST','folder-documents',$other,$this->p)->assertStatus(507);
  $this->assertFalse(DB::table('folder_documents')->where('id',$other['id'])->exists());
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk()->assertJsonPath('data.id',$body['id'])->assertJsonPath('data.version',1);
  $after=DB::table('folder_documents')->where('id',$body['id'])->first();
  $this->assertSame($before->blob_id,$after->blob_id);$this->assertSame($bytes,file_get_contents($this->dir.'/'.$after->blob_id.'.bin'));
  $this->assertSame(100000,(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame(1,DB::table('folder_documents')->count());
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.created')->count());
  $this->assertCount(1,glob($this->dir.'/*.bin'));
 }
 public function test_quota_and_expired_staging_release_only_own_reservation():void {
  DB::table('file_quotas')->insert(['organization_id'=>$this->p['organization_id'],'reserved_bytes'=>99999]);
  $b=$this->body($this->bytes());$this->internal('POST','folder-documents',$b,$this->p)->assertStatus(507);
  $this->assertSame(0,DB::table('folder_documents')->count());
  DB::table('file_quotas')->update(['reserved_bytes'=>100]);
  $this->internal('POST','folder-documents',$b,$this->p)->assertOk();
  DB::table('folder_documents')->update(['ready'=>false,'created_at'=>now()->subMinutes(16)]);
  $this->internal('GET','folder-documents',[],$this->p)->assertOk()->assertJsonPath('data.total',0);
  $this->assertSame(1,$this->app->make(FolderDocumentStore::class)->collect());
  $this->assertSame(100,(int)DB::table('file_quotas')->value('reserved_bytes'));$this->assertSame(0,DB::table('folder_documents')->count());
 }
 public function test_hostile_packages_never_reserve_storage():void {
  $mutations=[
   ['word/../outside.xml','<x/>'],
   ['word/vbaProject.bin','macro'],
   ['word/_rels/document.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="external" Target="https://example.invalid" TargetMode="External"/></Relationships>'],
   ['word/_rels/document.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="image" Target="missing.png"/></Relationships>'],
   ['word/document.xml','<!DOCTYPE document [<!ENTITY x SYSTEM "file:///private">]><document>&x;</document>'],
   ['word/bomb.xml','<x>'.str_repeat('A',2*1024*1024).'</x>'],
  ];
  foreach($mutations as [$name,$content]){
   $path=$this->dir.'/mutated';file_put_contents($path,$this->bytes());$z=new \ZipArchive;$z->open($path);$z->addFromString($name,$content);$z->close();
   $bytes=file_get_contents($path);unlink($path);
   $this->internal('POST','folder-documents',$this->body($bytes),$this->p)->assertUnprocessable();
  }
  $b=$this->body($this->bytes());$b['folder_id']=(string)Str::uuid();$this->internal('POST','folder-documents',$b,$this->p)->assertNotFound();
  $this->assertSame(0,DB::table('folder_documents')->count());$this->assertSame(0,DB::table('file_quotas')->count());$this->assertSame([],glob($this->dir.'/*'));
 }
 public function test_documents_remain_in_their_folder_after_a_folder_move():void {
  $folder=(string)Str::uuid();$target=(string)Str::uuid();
  foreach([$folder,$target] as $id)$this->internal('POST','folders',['id'=>$id,'name'=>$id,'parent_id'=>null],$this->p)->assertOk();
  $b=$this->body($this->bytes());$b['folder_id']=$folder;
  $this->internal('POST','folder-documents',$b,$this->p)->assertOk();
  $this->internal('GET','folder-documents',[],$this->p)->assertOk()->assertJsonPath('data.total',0);
  $this->internal('GET','folder-documents',[],$this->p,'gateway',['folder_id'=>$folder])->assertOk()->assertJsonPath('data.total',1);
  $foreign=array_replace($this->p,['organization_id'=>(string)Str::uuid()]);
  $this->internal('GET','folder-documents',[],$foreign,'gateway',['folder_id'=>$folder])->assertNotFound();
  $this->internal('POST','folder-documents',array_replace($b,['id'=>(string)Str::uuid()]),$foreign)->assertNotFound();
  $this->internal('POST','folders/'.$folder.'/move',['parent_id'=>$target,'version'=>1],$this->p)->assertOk();
  $this->internal('GET','folder-documents/'.$b['id'].'/download',[],$this->p)->assertOk()->assertJsonPath('data.folder_id',$folder);
 }
 public function test_deletion_hides_document_then_collector_releases_blob_and_quota_but_retains_id():void {
  $folder=(string)Str::uuid();$this->internal('POST','folders',['id'=>$folder,'name'=>'Actas','parent_id'=>null],$this->p)->assertOk();
  $bytes=$this->bytes();$body=$this->body($bytes);$body['folder_id']=$folder;
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $row=DB::table('folder_documents')->where('id',$body['id'])->first();$file=$this->dir.'/'.$row->blob_id.'.bin';
  $this->assertFileExists($file);$this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $uri='folder-documents/'.$body['id'];$payload=['version'=>1,'confirm'=>true];
  $this->deleteJson('/internal/v1/'.$uri,$payload)->assertUnauthorized();
  foreach(['registrar','treasurer','auditor','viewer'] as $role)$this->internal('DELETE',$uri,$payload,array_replace($this->p,['role'=>$role]))->assertForbidden();
  $this->internal('DELETE',$uri,$payload,array_replace($this->p,['organization_id'=>(string)Str::uuid()]))->assertNotFound();
  $this->internal('DELETE',$uri,['version'=>1,'confirm'=>false],$this->p)->assertUnprocessable();
  $this->internal('DELETE',$uri,['version'=>2,'confirm'=>true],$this->p)->assertConflict();
  $this->assertSame(0,DB::table('outbox_events')->where('action','document.deleted')->count());
  $this->internal('DELETE',$uri,$payload,$this->p)->assertOk()->assertJsonPath('data.deleted',true);
  $this->internal('DELETE',$uri,$payload,$this->p)->assertNotFound();
  $this->internal('GET',$uri.'/download',[],$this->p)->assertNotFound();
  $this->internal('PATCH',$uri,['name'=>'Otro.docx','version'=>2],$this->p)->assertNotFound();
  $this->internal('POST',$uri.'/move',['folder_id'=>null,'version'=>2],$this->p)->assertNotFound();
  $this->internal('POST','folder-documents',$body,$this->p)->assertConflict();
  $this->internal('GET','folder-documents',[],$this->p,'gateway',['folder_id'=>$folder])->assertOk()->assertJsonPath('data.total',0);
  $pending=DB::table('folder_documents')->where('id',$body['id'])->first();
  $this->assertFalse((bool)$pending->ready);$this->assertTrue((bool)$pending->delete_pending);
  $this->assertNull($pending->folder_id);$this->assertFileExists($file);
  $this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.deleted')->where('resource_id',$body['id'])->count());
  $this->internal('DELETE','folders/'.$folder,['version'=>1,'confirm'=>true],$this->p)->assertOk();
  $new=array_replace($body,['id'=>(string)Str::uuid(),'folder_id'=>null]);
  $this->internal('POST','folder-documents',$new,$this->p)->assertOk();
  $this->assertSame(1,$this->app->make(FolderDocumentStore::class)->collect());
  $this->assertFileDoesNotExist($file);
  $tombstone=DB::table('folder_documents')->where('id',$body['id'])->first();
  $this->assertSame(0,(int)$tombstone->bytes);$this->assertTrue((bool)$tombstone->delete_pending);
  $this->assertSame(strlen($bytes),(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame(0,$this->app->make(FolderDocumentStore::class)->collect());
  $this->assertSame(1,DB::table('outbox_events')->where('action','document.deleted')->count());
 }
 public function test_deletion_collector_recovers_when_unlink_preceded_lost_commit():void {
  $bytes=$this->bytes();$body=$this->body($bytes);
  $this->internal('POST','folder-documents',$body,$this->p)->assertOk();
  $row=DB::table('folder_documents')->where('id',$body['id'])->first();
  $this->internal('DELETE','folder-documents/'.$body['id'],['version'=>1,'confirm'=>true],$this->p)->assertOk();
  unlink($this->dir.'/'.$row->blob_id.'.bin');
  $this->assertSame(1,$this->app->make(FolderDocumentStore::class)->collect());
  $this->assertSame(0,(int)DB::table('file_quotas')->value('reserved_bytes'));
  $this->assertSame(0,(int)DB::table('folder_documents')->where('id',$body['id'])->value('bytes'));
  $this->internal('POST','folder-documents',$body,$this->p)->assertConflict();
  $this->assertSame(0,$this->app->make(FolderDocumentStore::class)->collect());
 }
}
