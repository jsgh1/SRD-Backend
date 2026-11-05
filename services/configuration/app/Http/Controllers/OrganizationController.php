<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Outbox;

final class OrganizationController
{
    public function publicConfig(string $code): array
    {
        $org = DB::table('organizations')->where('code', $code)->where('active', true)->first();
        abort_unless($org, 404);

        return ['data' => $this->view($org)];
    }

    public function byId(string $id): array
    {
        abort_unless(request()->attributes->get('issuer') === 'identity', 403);
        $org = DB::table('organizations')->where('id', $id)->where('active', true)->first();
        abort_unless($org, 403);

        return ['data' => $this->view($org)];
    }

    private function view(object $org): array
    {
        $terms = DB::table('terms_versions')->where('organization_id', $org->id)->orderByDesc('version')->first();

        return array_merge((array) $org, ['terms' => $terms]);
    }

    public function update(Request $r): array
    {
        $p = Access::require('organization.manage');
        $data = $r->validate(['name' => 'required|string|max:160', 'accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($data, $p) {
            $changed = DB::table('organizations')->where('id', $p['organization_id'])->where('version', $data['version'])->update(['name' => $data['name'], 'accent' => $data['accent'], 'version' => $data['version'] + 1, 'updated_at' => now()]);
            abort_unless($changed, 409);
            Outbox::record('organization.updated', $p['organization_id'], $p['user_id'], $p['organization_id']);

            return ['data' => $this->view(DB::table('organizations')->where('id', $p['organization_id'])->first())];
        });
    }

    public function terms(Request $r): array
    {
        $p = Access::require('organization.manage');
        $data = $r->validate(['body' => 'required|string|min:20|max:50000', 'body_en' => 'required|string|min:20|max:50000', 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($data, $p) {
            DB::table('organizations')->where('id', $p['organization_id'])->lockForUpdate()->firstOrFail();
            $current = DB::table('terms_versions')->where('organization_id', $p['organization_id'])->max('version');
            abort_unless($current === $data['version'], 409);
            $id = (string) Str::uuid();
            DB::table('terms_versions')->insert(['id' => $id, 'organization_id' => $p['organization_id'], 'version' => $current + 1, 'body' => $data['body'], 'body_en' => $data['body_en'], 'published_at' => now()]);
            Outbox::record('terms.published', $p['organization_id'], $p['user_id'], $id);

            return ['data' => ['id' => $id, 'version' => $current + 1]];
        });
    }
}
