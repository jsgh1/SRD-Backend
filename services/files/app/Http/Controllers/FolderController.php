<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;
use SrdFiles\FolderAccess;

final class FolderController
{
    private const ROOT = '00000000-0000-0000-0000-000000000000';

    private function context(Request $r, bool $write = true): array
    {
        $p = app(FolderAccess::class)->principal($r, $write);
        abort_if(strlen($r->getContent()) > 4096, 413);
        return $p;
    }

    private function folders(string $org)
    {
        return DB::table('internal_folders')->where('organization_id', $org);
    }

    private function folder(string $org, string $id): object
    {
        return $this->folders($org)->where('id', strtolower($id))->first() ?? abort(404);
    }

    private function name(string $name, string $field = 'name'): string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true) || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) {
            throw ValidationException::withMessages([$field => 'Escribe un nombre sin barras ni caracteres de control.']);
        }
        return $name;
    }

    private function lock(string $org): void
    {
        // Shared service-owned quota row serializes hierarchy changes per junta.
        // Preserve reserved bytes while acquiring an exclusive duplicate-key lock.
        DB::table('file_quotas')->upsert([['organization_id' => $org, 'reserved_bytes' => 0]], ['organization_id'], ['organization_id']);
        DB::table('file_quotas')->where('organization_id', $org)->lockForUpdate()->first();
    }

    private function breadcrumbs(string $org, ?string $parent): array
    {
        $items = [];
        while ($parent !== null) {
            abort_if(count($items) >= 20, 409);
            $folder = $this->folder($org, $parent);
            array_unshift($items, ['id' => $folder->id, 'name' => $folder->name, 'name_en' => $folder->name_en]);
            $parent = $folder->parent_id;
        }
        return $items;
    }

    public function index(Request $r): array
    {
        $p = $this->context($r, false);
        $data = $r->validate(['parent_id' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1|max:100000']);
        $parent = isset($data['parent_id']) ? strtolower($data['parent_id']) : null;
        $path = $this->breadcrumbs($p['organization_id'], $parent);
        $query = $this->folders($p['organization_id'])->where('parent_id', $parent);
        $page = (int) ($data['page'] ?? 1);
        $total = $query->count();
        return ['data' => ['items' => $query->orderBy('name')->orderBy('id')->offset(($page - 1) * 25)->limit(25)
            ->get(['id', 'parent_id', 'name', 'name_en', 'version', 'created_at', 'updated_at']),
            'total' => $total, 'page' => $page, 'page_size' => 25, 'breadcrumbs' => $path]];
    }

    public function store(Request $r): array
    {
        $p = $this->context($r);
        $data = $r->validate(['id' => 'required|uuid|not_in:'.self::ROOT, 'parent_id' => 'nullable|uuid',
            'name' => 'required|string|max:120', 'name_en' => 'sometimes|nullable|string|max:120']);
        $name = $this->name($data['name']);
        $nameEn = isset($data['name_en']) ? $this->name($data['name_en'], 'name_en') : null;
        $id = strtolower($data['id']);
        $parent = isset($data['parent_id']) ? strtolower($data['parent_id']) : null;
        return DB::transaction(function () use ($p, $name, $nameEn, $id, $parent, $data) {
            $org = $p['organization_id'];
            $this->lock($org);
            $existing = DB::table('internal_folders')->where('id', $id)->first();
            if ($existing) {
                abort_unless($existing->organization_id === $org && $existing->created_by === $p['user_id']
                    && $existing->name === $name && $existing->parent_id === $parent
                    && (!array_key_exists('name_en', $data) || $existing->name_en === $nameEn), 409);
                return ['data' => $existing];
            }
            if (count($this->breadcrumbs($org, $parent)) >= 20) {
                throw ValidationException::withMessages(['parent_id' => 'La jerarquía admite hasta 20 niveles.']);
            }
            abort_if($this->folders($org)->count() >= 10000, 409);
            DB::table('internal_folders')->insert(['id' => $id, 'organization_id' => $org, 'parent_id' => $parent,
                'parent_key' => $parent ?? self::ROOT, 'name' => $name, 'name_en' => $nameEn,
                'name_key' => hash('sha256', mb_strtolower($name)),
                'version' => 1, 'created_by' => $p['user_id'], 'created_at' => now(), 'updated_at' => now()]);
            Outbox::record('folder.created', $org, $p['user_id'], $id);
            return ['data' => $this->folder($org, $id)];
        });
    }

    public function move(Request $r, string $id): array
    {
        $p = $this->context($r);
        $data = $r->validate(['parent_id' => 'present|nullable|uuid', 'version' => 'required|integer|min:1|max:2147483646']);
        $parent = isset($data['parent_id']) ? strtolower($data['parent_id']) : null;
        return DB::transaction(function () use ($p, $data, $id, $parent) {
            $org = $p['organization_id'];
            $this->lock($org);
            $folder = $this->folder($org, $id);
            abort_unless((int) $folder->version === (int) $data['version'], 409);
            $path = $this->breadcrumbs($org, $parent);
            if (in_array($folder->id, array_column($path, 'id'), true)) {
                throw ValidationException::withMessages(['parent_id' => 'No puedes mover una carpeta dentro de sí misma ni de sus descendientes.']);
            }
            if ($folder->parent_id === $parent) return ['data' => $folder];
            // Bounded tree (10,000 folders); check the whole subtree, not only its root.
            $children = [];
            foreach ($this->folders($org)->get(['id', 'parent_id']) as $row) {
                $children[$row->parent_id ?? self::ROOT][] = $row->id;
            }
            $frontier = [$folder->id]; $seen = []; $height = 0;
            while ($frontier) {
                $height++;
                abort_if($height > 20, 409);
                $next = [];
                foreach ($frontier as $node) {
                    abort_if(isset($seen[$node]), 409);
                    $seen[$node] = true;
                    foreach ($children[$node] ?? [] as $child) $next[] = $child;
                }
                $frontier = $next;
            }
            if (count($path) + $height > 20) {
                throw ValidationException::withMessages(['parent_id' => 'La carpeta y sus descendientes superarían los 20 niveles permitidos.']);
            }
            $this->folders($org)->where('id', $folder->id)->update(['parent_id' => $parent,
                'parent_key' => $parent ?? self::ROOT, 'version' => $folder->version + 1, 'updated_at' => now()]);
            Outbox::record('folder.moved', $org, $p['user_id'], $folder->id);
            return ['data' => $this->folder($org, $id)];
        });
    }

    public function destroy(Request $r, string $id): array
    {
        $p = $this->context($r);
        $data = $r->validate(['version' => 'required|integer|min:1|max:2147483646', 'confirm' => 'required|boolean|accepted']);
        return DB::transaction(function () use ($p, $data, $id) {
            $org = $p['organization_id'];
            $this->lock($org);
            $folder = $this->folder($org, $id);
            abort_unless((int) $folder->version === (int) $data['version'], 409);
            // Include unfinished uploads: their reserved objects must retain a parent.
            if ($this->folders($org)->where('parent_id', $folder->id)->exists()
                || DB::table('folder_documents')->where('organization_id', $org)->where('folder_id', $folder->id)->exists()) {
                throw ValidationException::withMessages(['folder' => 'La carpeta contiene subcarpetas o documentos. Muévelos antes de eliminarla.']);
            }
            $this->folders($org)->where('id', $folder->id)->delete();
            Outbox::record('folder.deleted', $org, $p['user_id'], $folder->id);
            return ['data' => ['id' => $folder->id, 'deleted' => true]];
        });
    }

    public function rename(Request $r, string $id): array
    {
        $p = $this->context($r);
        $data = $r->validate(['name' => 'required|string|max:120', 'name_en' => 'sometimes|nullable|string|max:120',
            'version' => 'required|integer|min:1|max:2147483646']);
        $name = $this->name($data['name']);
        $nameEn = isset($data['name_en']) ? $this->name($data['name_en'], 'name_en') : null;
        return DB::transaction(function () use ($p, $data, $name, $nameEn, $id) {
            $org = $p['organization_id'];
            $this->lock($org);
            $folder = $this->folder($org, $id);
            abort_unless((int) $folder->version === (int) $data['version'], 409);
            if ($folder->name !== $name || (array_key_exists('name_en', $data) && $folder->name_en !== $nameEn)) {
                $updates = ['name' => $name, 'name_key' => hash('sha256', mb_strtolower($name)),
                    'version' => $folder->version + 1, 'updated_at' => now()];
                if (array_key_exists('name_en', $data)) $updates['name_en'] = $nameEn;
                $this->folders($org)->where('id', $folder->id)->update($updates);
                Outbox::record('folder.renamed', $org, $p['user_id'], $folder->id);
            }
            return ['data' => $this->folder($org, $id)];
        });
    }
}
