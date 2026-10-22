<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SrdFiles\{FolderAccess, FolderDocumentStore, OfficeDocumentGate, OfficePreview, PdfPreview, ImageRejected};
final class FolderDocumentController {
    private function context(Request $r, bool $write = true): array {
        $p=app(FolderAccess::class)->principal($r,$write);
        abort_if(strlen($r->getContent())>28*1024*1024,413);
        return $p;
    }
    public function index(Request $r,FolderDocumentStore $store): array {
        $p=$this->context($r,false); $d=$r->validate(['folder_id'=>'nullable|uuid','page'=>'sometimes|integer|min:1|max:100000']);
        return ['data'=>$store->listing($p['organization_id'],isset($d['folder_id'])?strtolower($d['folder_id']):null,(int)($d['page']??1))];
    }
    public function search(Request $r,FolderDocumentStore $store): array {
        $p=$this->context($r,false);
        $d=$r->validate(['q'=>'required|string|max:120','page'=>'sometimes|integer|min:1|max:100000']);
        $term=trim($d['q']);abort_unless($term!=='' && mb_strlen($term)<=120,422);
        return ['data'=>$store->search($p['organization_id'],$term,(int)($d['page']??1))];
    }
    public function store(Request $r,FolderDocumentStore $store): array {
        $p=$this->context($r); $d=$r->validate(['id'=>'required|uuid','folder_id'=>'present|nullable|uuid','name'=>'required|string|max:255','content'=>'required|string|max:27962028']);
        $bytes=base64_decode($d['content'],true);
        if(!is_string($bytes)||$bytes===''||strlen($bytes)>OfficeDocumentGate::MAX_BYTES||base64_encode($bytes)!==$d['content']) throw ValidationException::withMessages(['content'=>'Usa contenido base64 válido de hasta 20 MB.']);
        try { $result=$store->put($p,strtolower($d['id']),isset($d['folder_id'])?strtolower($d['folder_id']):null,$d['name'],$bytes); }
        catch(ImageRejected) { throw ValidationException::withMessages(['content'=>'La imagen o el análisis antimalware rechazó el archivo.']); }
        return ['data'=>$result];
    }
    public function rename(Request $r,FolderDocumentStore $store,string $id): array {
        $p=$this->context($r);abort_if(strlen($r->getContent())>4096,413);
        $d=$r->validate(['name'=>'required|string|max:255','version'=>'required|integer|min:1|max:2147483646']);
        return ['data'=>$store->rename($p,strtolower($id),$d['name'],(int)$d['version'])];
    }
    public function move(Request $r,FolderDocumentStore $store,string $id): array {
        $p=$this->context($r);abort_if(strlen($r->getContent())>4096,413);
        $d=$r->validate(['folder_id'=>'present|nullable|uuid','version'=>'required|integer|min:1|max:2147483646']);
        return ['data'=>$store->move($p,strtolower($id),isset($d['folder_id'])?strtolower($d['folder_id']):null,(int)$d['version'])];
    }
    public function download(Request $r,FolderDocumentStore $store,string $id): array {
        return ['data'=>$store->read($this->context($r,false),strtolower($id))];
    }
    public function preview(Request $r,FolderDocumentStore $store,OfficePreview $preview,PdfPreview $pdfPreview,string $id): array {
        $principal=$this->context($r,false);
        $selection=$r->validate(['sheet'=>'sometimes|integer|min:1|max:32','page'=>'sometimes|integer|min:1|max:1000']);
        $result=$store->read($principal,strtolower($id));
        $bytes=base64_decode($result['content'],true);
        abort_unless($result['mime']!=='application/pdf' || !array_key_exists('sheet',$selection), 422);
        abort_unless($result['mime']==='application/pdf' || !array_key_exists('page',$selection), 422);
        $excerpt=$result['mime']==='application/pdf'
            ? $pdfPreview->render($bytes,(int)($selection['page']??1))
            : $preview->extract($result['mime'],$bytes,(int)($selection['sheet']??1));
        unset($result['content']);
        return ['data'=>$result+['preview'=>$excerpt]];
    }
    public function destroy(Request $r,FolderDocumentStore $store,string $id): array {
        $p=$this->context($r);abort_if(strlen($r->getContent())>4096,413);
        $d=$r->validate(['version'=>'required|integer|min:1|max:2147483646','confirm'=>'required|boolean|accepted']);
        return ['data'=>$store->remove($p,strtolower($id),(int)$d['version'])];
    }
}
