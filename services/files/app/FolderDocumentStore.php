<?php
namespace SrdFiles;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;

final class FolderDocumentStore
{
    private const ROOT = '00000000-0000-0000-0000-000000000000';
    public function __construct(private OfficeDocumentGate $gate, private string $objects, private int $quota,
        private ?ImageGate $imageGate = null, private ?AudioGate $audioGate = null,
        private ?PdfGate $pdfGate = null, private ?Mp3Gate $mp3Gate = null) {}
    private function rows(string $org) { return DB::table('folder_documents')->where('organization_id', $org); }
    private function path(string $blob): string {
        if (!preg_match('/^[a-f0-9]{48}$/D', $blob) || !is_dir($this->objects) || is_link($this->objects)) throw new \RuntimeException('Private storage unavailable');
        $path = $this->objects.'/'.$blob.'.bin';
        if (is_link($path)) throw new \RuntimeException('Invalid private object');
        return $path;
    }
    private function account(string $org): object {
        // Duplicate-key UPDATE takes an exclusive lock in InnoDB; INSERT IGNORE
        // could give both writers shared locks and deadlock on FOR UPDATE.
        DB::table('file_quotas')->upsert([['organization_id' => $org, 'reserved_bytes' => 0]], ['organization_id'], ['organization_id']);
        return DB::table('file_quotas')->where('organization_id', $org)->lockForUpdate()->first();
    }
    public function folder(string $org, ?string $id): void {
        if ($id !== null) abort_unless(DB::table('internal_folders')->where('organization_id', $org)->where('id', $id)->exists(), 404);
    }
    private function metadata(object $row): array {
        return array_intersect_key((array) $row, array_flip(['id','folder_id','name','mime','bytes','sha256','created_at','version']));
    }
    public function quota(string $org): array {
        $used = (int) (DB::table('file_quotas')->where('organization_id', $org)->value('reserved_bytes') ?? 0);
        return ['used_bytes' => $used, 'limit_bytes' => $this->quota,
            'available_bytes' => max(0, $this->quota - $used)];
    }
    public function listing(string $org, ?string $folder, int $page): array {
        $this->folder($org, $folder);
        $q = $this->rows($org)->where('folder_id', $folder)->where('ready', true)->where('delete_pending', false);
        $total = $q->count();
        return ['items' => $q->orderBy('name')->orderBy('id')->offset(($page-1)*25)->limit(25)->get()->map(fn ($r) => $this->metadata($r)),
            'total' => $total, 'page_size' => 25, 'page' => $page];
    }
    public function search(string $org, string $term, int $page): array {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $query = DB::table('folder_documents as d')
            ->leftJoin('internal_folders as f', function ($join) {
                $join->on('f.id', '=', 'd.folder_id')->on('f.organization_id', '=', 'd.organization_id');
            })
            ->where('d.organization_id', $org)->where('d.ready', true)->where('d.delete_pending', false)
            ->whereRaw("d.name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        $items = (clone $query)->select('d.id', 'd.folder_id', 'd.name', 'd.mime', 'd.bytes',
                'd.sha256', 'd.version', 'f.name as folder_name', 'f.name_en as folder_name_en')
                ->orderBy('d.name')->orderBy('d.id')->offset(($page-1)*25)->limit(25)->get();
        $folders = []; $pending = $items->pluck('folder_id')->filter()->unique()->values()->all();
        for ($depth = 0; $pending && $depth < 20; $depth++) {
            $next = [];
            foreach (DB::table('internal_folders')->where('organization_id', $org)->whereIn('id', $pending)
                ->get(['id', 'parent_id', 'name', 'name_en']) as $folder) {
                $folders[$folder->id] = $folder;
                if ($folder->parent_id !== null && !isset($folders[$folder->parent_id])) $next[] = $folder->parent_id;
            }
            $pending = array_values(array_unique($next));
        }
        foreach ($items as $item) {
            $parts = []; $partsEn = []; $folderId = $item->folder_id; $seen = [];
            while ($folderId !== null && isset($folders[$folderId]) && !isset($seen[$folderId]) && count($parts) < 20) {
                $seen[$folderId] = true;
                array_unshift($parts, $folders[$folderId]->name);
                array_unshift($partsEn, $folders[$folderId]->name_en ?: $folders[$folderId]->name);
                $folderId = $folders[$folderId]->parent_id;
            }
            $item->folder_path = $folderId === null ? implode(' / ', ['Inicio', ...$parts]) : 'Ubicación no disponible';
            $item->folder_path_en = $folderId === null ? implode(' / ', ['Home', ...$partsEn]) : 'Location unavailable';
        }
        return ['items' => $items,
            'total' => $query->count(), 'page_size' => 25, 'page' => $page];
    }
    public function put(array $p, string $id, ?string $folder, string $name, string $bytes): array {
        $org = $p['organization_id'];
        $name = trim($name);
        if ($name === '' || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) throw ValidationException::withMessages(['name' => 'El nombre no puede contener barras ni caracteres de control.']);
        $this->folder($org, $folder);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg','jpeg','png','webp'], true)) {
            if (!$this->imageGate) throw ValidationException::withMessages(['content' => 'Las imágenes no están disponibles.']);
            $image = $this->imageGate->prepare($name, $bytes);
            $base = trim(pathinfo($name, PATHINFO_FILENAME));
            if ($base === '') throw ValidationException::withMessages(['name' => 'Escribe un nombre antes de la extensión.']);
            $name = $base.'.png';
            $bytes = $image['content'];
            $checked = ['mime' => $image['mime'], 'sha256' => $image['sha256']];
        } elseif ($extension === 'wav') {
            if (!$this->audioGate) throw ValidationException::withMessages(['content' => 'El audio no está disponible.']);
            $checked = $this->audioGate->inspect($name, $bytes);
        } elseif ($extension === 'mp3') {
            if (!$this->mp3Gate) throw ValidationException::withMessages(['content' => 'El MP3 no está disponible.']);
            $audio = $this->mp3Gate->prepare($name, $bytes);
            $bytes = $audio['content'];
            $checked = ['mime' => $audio['mime'], 'sha256' => $audio['sha256']];
        } elseif ($extension === 'pdf') {
            if (!$this->pdfGate) throw ValidationException::withMessages(['content' => 'El PDF no está disponible.']);
            $checked = $this->pdfGate->inspect($name, $bytes);
        } else {
            $checked = $this->gate->inspect($name, $bytes);
        }
        $row = DB::transaction(function () use ($org,$p,$id,$folder,$name,$bytes,$checked) {
            $quota = $this->account($org); $this->folder($org, $folder);
            $existing = DB::table('folder_documents')->where('id', $id)->first();
            if ($existing) {
                abort_unless($existing->organization_id === $org && $existing->created_by === $p['user_id'] && $existing->folder_id === $folder
                    && $existing->name === $name && hash_equals($existing->sha256, $checked['sha256']), 409);
                abort_unless($existing->ready && !$existing->delete_pending, 409);
                return $existing;
            }
            if ((int) $quota->reserved_bytes + strlen($bytes) > $this->quota) throw new PhotoQuotaExceeded;
            DB::table('folder_documents')->insert(['id'=>$id,'organization_id'=>$org,'folder_id'=>$folder,'parent_key'=>$folder ?? self::ROOT,
                'name'=>$name,'name_key'=>hash('sha256',mb_strtolower($name)),'mime'=>$checked['mime'],'blob_id'=>bin2hex(random_bytes(24)),
                'bytes'=>strlen($bytes),'sha256'=>$checked['sha256'],'ready'=>false,'delete_pending'=>false,'version'=>1,'created_by'=>$p['user_id'],'created_at'=>now(),'updated_at'=>now()]);
            DB::table('file_quotas')->where('organization_id',$org)->increment('reserved_bytes', strlen($bytes));
            return $this->rows($org)->where('id',$id)->first();
        });
        if ($row->ready) return $this->metadata($row);
        $path = $this->path($row->blob_id);
        try {
            $file = @fopen($path, 'x+b');
            if (!$file) throw new \RuntimeException('Private write failed');
            try {
                chmod($path,0600); $offset=0;
                while ($offset < strlen($bytes)) {
                    $n = fwrite($file, substr($bytes,$offset,65536));
                    if (!$n) throw new \RuntimeException('Private write incomplete');
                    $offset += $n;
                }
                if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new \RuntimeException('Private sync failed');
            } finally { fclose($file); }
            return DB::transaction(function () use ($org,$p,$id,$row) {
                $this->account($org);
                $current = $this->rows($org)->where('id',$id)->where('blob_id',$row->blob_id)->first();
                abort_unless($current && !$current->ready && !$current->delete_pending,409);
                $this->rows($org)->where('id',$id)->update(['ready'=>true,'updated_at'=>now()]);
                Outbox::record('document.created',$org,$p['user_id'],$id);
                return $this->metadata($this->rows($org)->where('id',$id)->first());
            });
        } catch (\Throwable $e) {
            // A commit may have succeeded despite losing its acknowledgement. Never delete a ready object.
            try { $this->discard($org,$id,$row->blob_id,false); } catch (\Throwable) {}
            throw $e;
        }
    }
    public function rename(array $p, string $id, string $name, int $version): array {
        $name=trim($name);
        if($name==='' || mb_strlen($name)>255 || preg_match('/[\x00-\x1f\x7f\/\\\\]/u',$name))
            throw ValidationException::withMessages(['name'=>'Usa un nombre de hasta 255 caracteres, sin barras ni caracteres de control.']);
        return DB::transaction(function () use ($p,$id,$name,$version) {
            $org=$p['organization_id'];$this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('ready',true)->where('delete_pending',false)->first();
            abort_unless($row,404);$this->folder($org,$row->folder_id);
            abort_unless((int)$row->version===$version && $version<2147483647,409);
            $extension=match($row->mime){
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
                'image/png'=>'png',
                'audio/wav'=>'wav',
                'audio/mpeg'=>'mp3',
                'application/pdf'=>'pdf',
                default=>null,
            };
            abort_unless($extension,409);
            if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!==$extension || trim(pathinfo($name,PATHINFO_FILENAME))==='')
                throw ValidationException::withMessages(['name'=>'Conserva la extensión .'.$extension.' y escribe un nombre antes de ella.']);
            if($row->name===$name)return $this->metadata($row);
            $this->rows($org)->where('id',$id)->update(['name'=>$name,'name_key'=>hash('sha256',mb_strtolower($name)),
                'version'=>$version+1,'updated_at'=>now()]);
            Outbox::record('document.renamed',$org,$p['user_id'],$id);
            return $this->metadata($this->rows($org)->where('id',$id)->first());
        });
    }
    public function move(array $p, string $id, ?string $folder, int $version): array {
        return DB::transaction(function () use ($p,$id,$folder,$version) {
            $org=$p['organization_id'];$this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('ready',true)->where('delete_pending',false)->first();
            abort_unless($row,404);$this->folder($org,$row->folder_id);$this->folder($org,$folder);
            abort_unless((int)$row->version===$version && $version<2147483647,409);
            if($row->folder_id===$folder)return $this->metadata($row);
            $this->rows($org)->where('id',$id)->update(['folder_id'=>$folder,'parent_key'=>$folder??self::ROOT,
                'version'=>$version+1,'updated_at'=>now()]);
            Outbox::record('document.moved',$org,$p['user_id'],$id);
            return $this->metadata($this->rows($org)->where('id',$id)->first());
        });
    }
    public function read(array $p, string $id): array {
        return DB::transaction(function () use ($p,$id) {
            $org=$p['organization_id']; $this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('ready',true)->where('delete_pending',false)->first();
            abort_unless($row,404); $this->folder($org,$row->folder_id);
            $content=@file_get_contents($this->path($row->blob_id),false,null,0,OfficeDocumentGate::MAX_BYTES+1);
            abort_unless(is_string($content) && strlen($content)===(int)$row->bytes && hash_equals($row->sha256,hash('sha256',$content)),503);
            Outbox::record('document.downloaded',$org,$p['user_id'],$id);
            return $this->metadata($row)+['content'=>base64_encode($content)];
        });
    }
    public function remove(array $p, string $id, int $version): array {
        return DB::transaction(function () use ($p,$id,$version) {
            $org=$p['organization_id'];$this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('ready',true)->where('delete_pending',false)->first();
            abort_unless($row,404);$this->folder($org,$row->folder_id);
            abort_unless((int)$row->version===$version && $version<2147483647,409);
            // Keep the blob and its quota until the collector has removed it. Keep the ID as a tombstone to reject upload replays.
            $this->rows($org)->where('id',$id)->update(['ready'=>false,'delete_pending'=>true,'folder_id'=>null,
                'parent_key'=>self::ROOT,'name'=>'Documento eliminado','name_key'=>hash('sha256','deleted-document:'.$id),
                'version'=>$version+1,'updated_at'=>now()]);
            Outbox::record('document.deleted',$org,$p['user_id'],$id);
            return ['id'=>$id,'deleted'=>true];
        });
    }
    private function discard(string $org,string $id,string $blob,bool $expired): bool {
        return DB::transaction(function () use ($org,$id,$blob,$expired) {
            $this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('blob_id',$blob)->first();
            if ($row && ($row->ready || $row->delete_pending || ($expired && $row->created_at > now()->subMinutes(15)->toDateTimeString()))) return false;
            $path=$this->path($blob);
            if (is_file($path) && !unlink($path)) throw new \RuntimeException('Private cleanup failed');
            if ($row) {
                $this->rows($org)->where('id',$id)->delete();
                DB::table('file_quotas')->where('organization_id',$org)->decrement('reserved_bytes',(int)$row->bytes);
            }
            return true;
        });
    }
    public function collect(): int {
        $count=0;
        foreach(DB::table('folder_documents')->where('delete_pending',true)->where('bytes','>',0)->limit(25)->get() as $r) {
            if($this->collectDeleted($r->organization_id,$r->id,$r->blob_id))$count++;
        }
        foreach(DB::table('folder_documents')->where('ready',false)->where('delete_pending',false)->where('created_at','<=',now()->subMinutes(15))->limit(25-$count)->get() as $r) {
            if($this->discard($r->organization_id,$r->id,$r->blob_id,true)) $count++;
        }
        return $count;
    }
    private function collectDeleted(string $org,string $id,string $blob): bool {
        return DB::transaction(function () use ($org,$id,$blob) {
            $this->account($org);
            $row=$this->rows($org)->where('id',$id)->where('blob_id',$blob)->where('delete_pending',true)->where('bytes','>',0)->first();
            if(!$row)return false;
            $path=$this->path($blob);
            // A previous attempt may have unlinked the object but lost the database commit.
            if(is_file($path)&&!unlink($path))throw new \RuntimeException('Private cleanup failed');
            $this->rows($org)->where('id',$id)->update(['bytes'=>0,'sha256'=>str_repeat('0',64),'updated_at'=>now()]);
            DB::table('file_quotas')->where('organization_id',$org)->decrement('reserved_bytes',(int)$row->bytes);
            return true;
        });
    }
}
