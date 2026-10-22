<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Srd\Access;
use Srd\Outbox;

final class QuickLinkService
{
    public const FUNCTIONS = [
        'register' => ['label' => 'Nuevo registro', 'permission' => 'persons.write'],
        'list' => ['label' => 'Ver personas', 'permission' => 'persons.read'],
        'lookup' => ['label' => 'Consultar', 'permission' => 'persons.read'],
        'settings' => ['label' => 'Configuración', 'permission' => 'profile.write'],
        'audit' => ['label' => 'Auditoría', 'permission' => 'audit.read'],
        'downloads' => ['label' => 'Descargas', 'permission' => 'downloads.read'],
    ];
    private const DEFAULTS = [
        ['function' => 'register', 'label' => 'Nuevo registro', 'label_en' => 'New record'],
        ['function' => 'list', 'label' => 'Ver personas', 'label_en' => 'View people'],
        ['function' => 'lookup', 'label' => 'Consultar', 'label_en' => 'Search'],
    ];

    private function allowed(array $item, array $p): bool
    {
        return isset(self::FUNCTIONS[$item['function']]) && Access::allows($p['role'], self::FUNCTIONS[$item['function']]['permission']);
    }

    private function visible(array $items, array $p): array
    {
        return array_values(array_filter($items, fn ($item) => $this->allowed($item, $p)));
    }

    private function common(array $p): ?object
    {
        return DB::table('organization_quick_links')->where('organization_id', $p['organization_id'])->first();
    }

    private function personal(array $p)
    {
        return DB::table('personal_quick_links')->where('organization_id', $p['organization_id'])->where('user_id', $p['user_id']);
    }

    public function read(array $p): array
    {
        abort_unless(DB::table('organizations')->where('id', $p['organization_id'])->where('active', true)->exists(), 403);
        $common = $this->common($p);
        $personal = $this->personal($p)->first();
        $shared = $common ? json_decode($common->items, true, 512, JSON_THROW_ON_ERROR) : self::DEFAULTS;
        $own = $personal?->items === null ? null : json_decode($personal->items, true, 512, JSON_THROW_ON_ERROR);
        $mode = $common->mode ?? 'common';
        $data = [
            'mode' => $mode,
            'common_version' => $common->version ?? 0,
            'personal_version' => $personal->version ?? 0,
            'inherited' => $own === null,
            'items' => $this->visible($mode === 'personal' && $own !== null ? $own : $shared, $p),
            'personal_items' => $own === null ? null : $this->visible($own, $p),
            'catalog' => array_values(array_map(fn ($key) => ['function' => $key, 'label' => self::FUNCTIONS[$key]['label']], array_keys(array_filter(self::FUNCTIONS, fn ($f) => Access::allows($p['role'], $f['permission']))))),
        ];
        if (Access::allows($p['role'], 'organization.manage')) {
            $data['common_items'] = $this->visible($shared, $p);
        }
        return $data;
    }

    public function save(array $p, array $data, bool $common): array
    {
        foreach ($data['items'] as $i => $item) {
            if (! $this->allowed($item, $p)) {
                throw ValidationException::withMessages(["items.$i.function" => 'No tienes permiso para seleccionar esta función.']);
            }
        }
        return DB::transaction(function () use ($p, $data, $common) {
            // Same organization lock orders mode changes and personal writes, including first insert.
            $org = DB::table('organizations')->where('id', $p['organization_id'])->lockForUpdate()->first();
            abort_unless($org && $org->active, 403);
            $shared = $this->common($p);
            $commonVersion = (int) ($shared->version ?? 0);
            if ($common) {
                abort_unless($commonVersion === (int) $data['version'], 409);
                $mode = $data['mode'] ?? ($shared->mode ?? 'common');
                abort_if($mode !== ($shared->mode ?? 'common') && $p['role'] !== 'superadmin', 403);
                DB::table('organization_quick_links')->updateOrInsert(['organization_id' => $p['organization_id']], [
                    'mode' => $mode, 'items' => json_encode($data['items'], JSON_THROW_ON_ERROR), 'version' => $commonVersion + 1,
                    'created_at' => $shared->created_at ?? now(), 'updated_at' => now(),
                ]);
            } else {
                abort_unless(($shared->mode ?? 'common') === 'personal', 403);
                abort_unless($commonVersion === (int) $data['common_version'], 409);
                $own = $this->personal($p)->first();
                abort_unless((int) ($own->version ?? 0) === (int) $data['version'], 409);
                DB::table('personal_quick_links')->updateOrInsert(['organization_id' => $p['organization_id'], 'user_id' => $p['user_id']], [
                    'items' => $data['inherit'] ? null : json_encode($data['items'], JSON_THROW_ON_ERROR), 'version' => ($own->version ?? 0) + 1,
                    'created_at' => $own->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
            Outbox::record($common ? 'quick_links.organization_updated' : 'quick_links.personal_updated', $p['organization_id'], $p['user_id'], $common ? $p['organization_id'] : $p['user_id']);
            return $this->read($p);
        });
    }
}
