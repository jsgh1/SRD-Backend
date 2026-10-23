<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Outbox;

final class PlatformController
{
    public function index(Request $request): array
    {
        Access::require('platform.manage');
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);
        return ['data' => DB::table('organizations')->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1))];
    }

    public function store(Request $request): array
    {
        $p = Access::require('platform.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:organizations,code'],
            'name' => 'required|string|max:160',
            'terms' => 'required|string|min:20|max:50000',
            'terms_en' => 'required|string|min:20|max:50000',
        ]);
        return DB::transaction(function () use ($data, $p) {
            $id = (string) Str::uuid();
            $created = DB::table('organizations')->insertOrIgnore(['id' => $id, 'code' => $data['code'], 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($created, 409);
            DB::table('terms_versions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $id, 'version' => 1, 'body' => $data['terms'], 'body_en' => $data['terms_en'], 'published_at' => now()]);
            Outbox::record('organization.created', $id, $p['user_id'], $id);
            return ['data' => DB::table('organizations')->where('id', $id)->first()];
        });
    }

    public function status(Request $request, string $id): array
    {
        $p = Access::require('platform.manage');
        $data = $request->validate(['active' => 'required|boolean', 'version' => 'required|integer|min:1']);
        return DB::transaction(function () use ($id, $data, $p) {
            $org = DB::table('organizations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($org, 404);
            abort_unless((int) $org->version === (int) $data['version'], 409);
            abort_if($id === $p['organization_id'] && ! $data['active'], 422, 'Cambia a otra junta antes de suspender la junta actual.');
            if ((bool) $org->active !== (bool) $data['active']) {
                DB::table('organizations')->where('id', $id)->update(['active' => $data['active'], 'version' => $org->version + 1, 'updated_at' => now()]);
                Outbox::record($data['active'] ? 'organization.activated' : 'organization.suspended', $id, $p['user_id'], $id);
            }
            return ['data' => DB::table('organizations')->where('id', $id)->first()];
        });
    }
}
