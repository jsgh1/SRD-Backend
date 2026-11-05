<?php
namespace SrdFiles;
use Closure;
use PDO;

/** Internal storage engine; callers must supply a trusted, live resource authorizer. No HTTP endpoint. */
final class PhotoStore implements PhotoStorage, AssetPhotoStorage, UserPhotoStorage
{
    public const SLOTS = ['person','document','property'];
    public const ASSET_SLOTS = ['front','side','detail'];
    public const USER_SLOTS = ['avatar'];
    public const QUOTA_BYTES = 5 * 1024 * 1024 * 1024;
    private string $table;
    private string $idColumn;
    private string $readPermission;
    private string $writePermission;
    private array $slots;
    public function __construct(private PDO $db, private string $objects, private ImageGate $gate, private Closure $authorize, private int $quota = self::QUOTA_BYTES, private string $resource = 'person') {
        if (!in_array($resource, ['person', 'asset', 'user'], true)) throw new \InvalidArgumentException('Invalid photo resource.');
        $this->table = match ($resource) { 'person' => 'person_photos', 'asset' => 'asset_photos', 'user' => 'user_photos' };
        $this->idColumn = match ($resource) { 'person' => 'person_id', 'asset' => 'asset_id', 'user' => 'user_id' };
        $this->readPermission = match ($resource) { 'person' => 'persons.read', 'asset' => 'inventory.read', 'user' => 'contacts.read' };
        $this->writePermission = match ($resource) { 'person' => 'persons.write', 'asset' => 'inventory.write', 'user' => 'profile.write' };
        $this->slots = match ($resource) { 'person' => self::SLOTS, 'asset' => self::ASSET_SLOTS, 'user' => self::USER_SLOTS };
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new \InvalidArgumentException('Se requiere MySQL.');
        if (!is_dir($objects) || is_link($objects) || $quota < 1) throw new \InvalidArgumentException('Almacenamiento privado no preparado.');
        $this->objects = realpath($objects);
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }
    private function sql(string $sql, array $values=[]): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($values);return $s; }
    private function uuid(string $value): void { if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D',$value)) throw new \InvalidArgumentException('Identificador inválido.'); }
    private function guard(array $p,string $person,string $action,?string $slot=null): string {
        $org=$p['organization_id']??'';$this->uuid($org);$this->uuid($p['user_id']??'');$this->uuid($person);
        if($slot!==null && !in_array($slot,$this->slots,true)) throw new \InvalidArgumentException('Tipo de fotografía inválido.');
        if(($this->authorize)($p,$person,$action,$slot)!==true) throw new PhotoNotFound('Recurso no disponible.');
        return $org;
    }
    private function path(string $id): string {
        if(!preg_match('/^[a-f0-9]{48}$/D',$id)) throw new \RuntimeException('Identificador de objeto inválido.');
        $path=$this->objects.DIRECTORY_SEPARATOR.$id.'.png';
        if(is_link($path)) throw new \RuntimeException('Objeto privado inválido.');
        return $path;
    }
    private function account(string $org): int {
        $this->sql('INSERT INTO file_quotas (organization_id,reserved_bytes) VALUES (?,0) ON DUPLICATE KEY UPDATE organization_id=organization_id',[$org]);
        return (int)$this->sql('SELECT reserved_bytes FROM file_quotas WHERE organization_id=? FOR UPDATE',[$org])->fetchColumn();
    }
    private function row(string $org,string $person,string $slot): array|false { return $this->sql("SELECT * FROM {$this->table} WHERE organization_id=? AND {$this->idColumn}=? AND slot=?",[$org,$person,$slot])->fetch(PDO::FETCH_ASSOC); }
    private function transaction(Closure $operation): mixed {
        $this->db->beginTransaction();
        try{$result=$operation();$this->db->commit();return $result;}
        catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function event(array $p,string $person,string $action): void {
        $id=$this->newUuid();$time=gmdate('Y-m-d H:i:s');
        $correlation=$p['correlation_id']??$this->newUuid();$this->uuid($correlation);
        $this->sql('INSERT INTO outbox_events (id,organization_id,actor_id,action,resource_id,result,correlation_id,occurred_at,next_attempt_at) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$p['organization_id'],$p['user_id'],$action,$person,'success',$correlation,$time,$time]);
    }
    private function newUuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
    private function view(array $row): array { return ['slot'=>$row['slot'],'version'=>(int)$row['version'],'present'=>$row['blob_id']!==null,'size'=>(int)$row['bytes'],'width'=>$row['width']===null?null:(int)$row['width'],'height'=>$row['height']===null?null:(int)$row['height']]; }

    public function list(array $p,string $person): array {
        $org=$this->guard($p,$person,$this->readPermission);$items=[];
        foreach($this->slots as $slot){$r=$this->row($org,$person,$slot);$items[]=$r?$this->view($r):['slot'=>$slot,'version'=>0,'present'=>false,'size'=>0,'width'=>null,'height'=>null];}
        return $items;
    }
    public function put(array $p,string $person,string $slot,string $name,string $bytes,int $version): array {
        $org=$this->guard($p,$person,$this->writePermission,$slot);
        if($version<0)throw new \InvalidArgumentException('Versión inválida.');
        $image=$this->gate->prepare($name,$bytes);
        $this->guard($p,$person,$this->writePermission,$slot);
        $blob=bin2hex(random_bytes(24));$path=$this->path($blob);$written=false;
        try {
            return $this->transaction(function()use($p,$org,$person,$slot,$version,$image,$blob,$path,&$written){
                $reserved=$this->account($org);$old=$this->row($org,$person,$slot);
                // Same organization lock as purge: an in-flight upload cannot resurrect a deleted person.
                if($this->resource==='person' && $this->sql('SELECT 1 FROM file_deleted_persons WHERE organization_id=? AND person_id=?',[$org,$person])->fetchColumn())throw new PhotoNotFound('Recurso no disponible.');
                if((int)($old['version']??0)!==$version)throw new PhotoConflict('La fotografía cambió. Recarga antes de guardar.');
                // Old and new bytes coexist until garbage collection succeeds.
                if($reserved+$image['size']>$this->quota)throw new PhotoQuotaExceeded('La junta no tiene espacio disponible.');
                $handle=@fopen($path,'xb');if(!$handle)throw new \RuntimeException('No se pudo crear el objeto privado.');$written=true;
                try{if(!chmod($path,0600)||fwrite($handle,$image['content'])!==$image['size']||!fflush($handle)||!fsync($handle))throw new \RuntimeException('Escritura incompleta.');}finally{fclose($handle);}
                if($old && $old['blob_id']!==null)$this->sql('INSERT INTO file_garbage (blob_id,organization_id,bytes,person_id) VALUES (?,?,?,?)',[$old['blob_id'],$org,$old['bytes'],$this->resource==='person'?$person:null]);
                $this->sql("INSERT INTO {$this->table} (organization_id,{$this->idColumn},slot,version,blob_id,bytes,sha256,width,height) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),blob_id=VALUES(blob_id),bytes=VALUES(bytes),sha256=VALUES(sha256),width=VALUES(width),height=VALUES(height)",[$org,$person,$slot,$version+1,$blob,$image['size'],$image['sha256'],$image['width'],$image['height']]);
                $this->sql('UPDATE file_quotas SET reserved_bytes=reserved_bytes+? WHERE organization_id=?',[$image['size'],$org]);
                $this->event($p,$person,match ($this->resource) { 'person' => 'photo.', 'asset' => 'asset_photo.', 'user' => 'user_photo.' }.($old&&$old['blob_id']!==null?'replaced':'created'));
                return $this->view($this->row($org,$person,$slot));
            });
        } catch(\Throwable $e) {
            // A commit failure may be ambiguous. Only remove after confirming no metadata references it.
            if($written){try{if(!$this->sql("SELECT 1 FROM {$this->table} WHERE blob_id=?",[$blob])->fetchColumn() && is_file($path))unlink($path);}catch(\Throwable){/* Leave unknown outcome for reconciliation; never remove a referenced object. */}}
            throw $e;
        }
    }
    public function read(array $p,string $person,string $slot): array {
        $org=$this->guard($p,$person,$this->readPermission,$slot);$row=$this->row($org,$person,$slot);
        if(!$row||$row['blob_id']===null)throw new PhotoNotFound('Fotografía no disponible.');
        $path=$this->path($row['blob_id']);$bytes=@file_get_contents($path,false,null,0,ImageGate::MAX_BYTES+1);
        if($bytes===false||strlen($bytes)!==(int)$row['bytes']||!hash_equals($row['sha256'],hash('sha256',$bytes)))throw new PhotoNotFound('Fotografía no disponible.');
        $this->guard($p,$person,$this->readPermission,$slot);
        if(($this->row($org,$person,$slot)['blob_id']??null)!==$row['blob_id'])throw new PhotoConflict('La fotografía cambió durante la consulta.');
        return ['content'=>$bytes,'mime'=>'image/png','sha256'=>$row['sha256'],'version'=>(int)$row['version']];
    }
    public function delete(array $p,string $person,string $slot,int $version,bool $confirmed): array {
        $org=$this->guard($p,$person,$this->writePermission,$slot);if(!$confirmed)throw new \InvalidArgumentException('Confirma la eliminación.');
        return $this->transaction(function()use($p,$org,$person,$slot,$version){
            $this->account($org);$old=$this->row($org,$person,$slot);
            if(!$old||$old['blob_id']===null)throw new PhotoNotFound('Fotografía no disponible.');
            if((int)$old['version']!==$version)throw new PhotoConflict('La fotografía cambió.');
            $this->sql('INSERT INTO file_garbage (blob_id,organization_id,bytes,person_id) VALUES (?,?,?,?)',[$old['blob_id'],$org,$old['bytes'],$this->resource==='person'?$person:null]);
            $this->sql("UPDATE {$this->table} SET blob_id=NULL,bytes=0,sha256=NULL,width=NULL,height=NULL,version=version+1 WHERE organization_id=? AND {$this->idColumn}=? AND slot=?",[$org,$person,$slot]);
            $this->event($p,$person,match ($this->resource) { 'person' => 'photo.deleted', 'asset' => 'asset_photo.deleted', 'user' => 'user_photo.deleted' });return $this->view($this->row($org,$person,$slot));
        });
    }
    /** Only a committed Records deletion may invoke this operation. No browser or live-person permission. */
    public function purgePerson(array $p,string $person): void {
        if($this->resource!=='person')throw new \LogicException('Only person photos can be purged.');
        $org=$p['organization_id']??'';$this->uuid($org);$this->uuid($person);$this->uuid($p['user_id']??'');
        $this->transaction(function()use($p,$org,$person){
            $this->account($org);
            if($this->sql('SELECT 1 FROM file_deleted_persons WHERE organization_id=? AND person_id=?',[$org,$person])->fetchColumn())return;
            $this->sql('INSERT INTO file_deleted_persons (organization_id,person_id,deleted_at) VALUES (?,?,?)',[$org,$person,gmdate('Y-m-d H:i:s')]);
            $this->sql('INSERT INTO file_garbage (blob_id,organization_id,bytes,person_id) SELECT blob_id,organization_id,bytes,person_id FROM person_photos WHERE organization_id=? AND person_id=? AND blob_id IS NOT NULL',[$org,$person]);
            $this->sql('INSERT INTO file_deleted_person_blobs (blob_id,organization_id,person_id) SELECT blob_id,organization_id,person_id FROM file_garbage WHERE organization_id=? AND person_id=?',[$org,$person]);
            $this->sql('DELETE FROM person_photos WHERE organization_id=? AND person_id=?',[$org,$person]);
            $this->event($p,$person,'photo.person_deleted');
        });
        $blobs=$this->sql('SELECT blob_id FROM file_deleted_person_blobs WHERE organization_id=? AND person_id=? ORDER BY blob_id LIMIT 25',[$org,$person])->fetchAll(PDO::FETCH_COLUMN);
        foreach($blobs as $blob)$this->collectPersonBlob($org,$blob);
        if($this->sql('SELECT 1 FROM file_deleted_person_blobs WHERE organization_id=? AND person_id=? LIMIT 1',[$org,$person])->fetchColumn())throw new \RuntimeException('La limpieza de fotos aún no ha terminado.');
    }
    private function collectPersonBlob(string $org,string $blob): void {
        $this->transaction(function()use($org,$blob){
            $reserved=$this->account($org);
            $row=$this->sql('SELECT * FROM file_garbage WHERE organization_id=? AND blob_id=? FOR UPDATE',[$org,$blob])->fetch(PDO::FETCH_ASSOC);
            if(!$row)return; // The scheduled collector already removed the blob and its tracking row.
            if($this->sql('SELECT 1 FROM person_photos WHERE blob_id=?',[$blob])->fetchColumn() || $this->sql('SELECT 1 FROM asset_photos WHERE blob_id=?',[$blob])->fetchColumn() || $this->sql('SELECT 1 FROM user_photos WHERE blob_id=?',[$blob])->fetchColumn())throw new \RuntimeException('Un objeto vigente no puede retirarse.');
            $path=$this->path($blob);
            if($reserved<(int)$row['bytes'])throw new \RuntimeException('La cuota requiere conciliación.');
            if(file_exists($path)&&(!is_file($path)||!@unlink($path)))throw new \RuntimeException('No se pudo retirar el objeto.');
            $this->sql('DELETE FROM file_garbage WHERE blob_id=?',[$blob]);
            $this->sql('UPDATE file_quotas SET reserved_bytes=reserved_bytes-? WHERE organization_id=?',[$row['bytes'],$org]);
        });
    }
    /** Trusted maintenance job only. Not a client action. Quota stays reserved on any cleanup failure. */
    public function collect(string $org,int $limit=25): int {
        $this->uuid($org);if($limit<1||$limit>100)throw new \InvalidArgumentException('Límite inválido.');$count=0;
        while($count<$limit){$removed=$this->transaction(function()use($org){
            $reserved=$this->account($org);$row=$this->sql('SELECT * FROM file_garbage WHERE organization_id=? ORDER BY blob_id LIMIT 1 FOR UPDATE',[$org])->fetch(PDO::FETCH_ASSOC);
            if(!$row)return false;
            if($this->sql('SELECT 1 FROM person_photos WHERE blob_id=?',[$row['blob_id']])->fetchColumn() || $this->sql('SELECT 1 FROM asset_photos WHERE blob_id=?',[$row['blob_id']])->fetchColumn() || $this->sql('SELECT 1 FROM user_photos WHERE blob_id=?',[$row['blob_id']])->fetchColumn())throw new \RuntimeException('Un objeto vigente no puede retirarse.');
            $path=$this->path($row['blob_id']);
            if($reserved<(int)$row['bytes'])throw new \RuntimeException('La cuota requiere conciliación.');
            if(file_exists($path)&&(!is_file($path)||!@unlink($path)))throw new \RuntimeException('No se pudo retirar el objeto; su espacio sigue reservado.');
            $this->sql('DELETE FROM file_garbage WHERE blob_id=?',[$row['blob_id']]);
            $this->sql('UPDATE file_quotas SET reserved_bytes=reserved_bytes-? WHERE organization_id=?',[$row['bytes'],$org]);return true;
        });if(!$removed)break;$count++;}return $count;
    }
}
