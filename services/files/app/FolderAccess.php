<?php
namespace SrdFiles;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\{Access, Outbox};

final class FolderAccess
{
    private const DELEGABLE = ['registrar','treasurer','auditor','viewer'];

    public function principal(Request $request, ?bool $write): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = $request->attributes->get('principal', []);
        foreach (['organization_id','user_id','session_id'] as $key)
            abort_unless(is_string($principal[$key] ?? null) && Str::isUuid($principal[$key]), 403);
        abort_unless(in_array($principal['role'] ?? '', Access::ROLES, true), 403);
        if ($write !== null) $this->require($principal, $write);
        return $principal;
    }

    private function roles(string $organizationId): array
    {
        $row = DB::table('folder_access')->where('organization_id', $organizationId)->first();
        return $row ? json_decode($row->reader_roles, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function memberships(?object $row): array
    {
        return $row && $row->reader_memberships
            ? json_decode($row->reader_memberships, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    private function granted(array $principal, array $memberships): bool
    {
        $id = $principal['membership_id'] ?? null;
        $version = $principal['membership_version'] ?? null;
        if (!is_string($id) || !Str::isUuid($id) || !is_int($version)) return false;
        foreach ($memberships as $grant)
            if ($grant['id'] === $id && $grant['version'] === $version) return true;
        return false;
    }

    public function require(array $principal, bool $write): void
    {
        if (Access::allows($principal['role'], 'folders.manage')) return;
        if ($write) abort(403);
        $row = DB::table('folder_access')->where('organization_id', $principal['organization_id'])->first();
        $roles = $row ? json_decode($row->reader_roles, true, 512, JSON_THROW_ON_ERROR) : [];
        abort_unless(in_array($principal['role'], $roles, true) || $this->granted($principal, $this->memberships($row)), 403);
    }

    public function settings(array $principal): array
    {
        $row = DB::table('folder_access')->where('organization_id', $principal['organization_id'])->first();
        $roles = $row ? json_decode($row->reader_roles, true, 512, JSON_THROW_ON_ERROR) : [];
        $memberships = $this->memberships($row);
        $manage = Access::allows($principal['role'], 'folders.manage');
        return ['version' => $row ? (int)$row->version : 0,
            'reader_roles' => $manage ? $roles : [],
            'reader_memberships' => $manage ? $memberships : [],
            'can_manage' => $manage,
            'can_read' => $manage || in_array($principal['role'], $roles, true) || $this->granted($principal, $memberships)];
    }

    public function update(array $principal, int $version, array $roles, ?array $memberships = null): array
    {
        abort_unless(Access::allows($principal['role'], 'folders.manage'), 403);
        sort($roles);
        abort_unless(count(array_diff($roles, self::DELEGABLE)) === 0, 422);
        return DB::transaction(function () use ($principal, $version, $roles, $memberships) {
            $org = $principal['organization_id'];
            DB::table('folder_access')->upsert([['organization_id'=>$org,'version'=>0,'reader_roles'=>'[]','reader_memberships'=>'[]',
                'created_at'=>now(),'updated_at'=>now()]], ['organization_id'], ['organization_id']);
            $row = DB::table('folder_access')->where('organization_id', $org)->lockForUpdate()->first();
            abort_unless((int)$row->version === $version && $version < 2147483647, 409);
            $oldMembers = $this->memberships($row);
            $oldVersions = array_column($oldMembers, 'version', 'id');
            $newMembers = $memberships ?? $oldMembers;
            foreach ($newMembers as &$grant)
                if (array_key_exists($grant['id'], $oldVersions)) $grant['version'] = $oldVersions[$grant['id']];
            unset($grant);
            usort($newMembers, fn ($a, $b) => strcmp($a['id'], $b['id']));
            if (json_decode($row->reader_roles, true, 512, JSON_THROW_ON_ERROR) !== $roles
                || $oldMembers !== $newMembers) {
                DB::table('folder_access')->where('organization_id', $org)->update([
                    'version'=>$version+1,'reader_roles'=>json_encode($roles, JSON_THROW_ON_ERROR),
                    'reader_memberships'=>json_encode($newMembers, JSON_THROW_ON_ERROR),'updated_at'=>now()]);
                Outbox::record('folder.access_updated', $org, $principal['user_id'], $org);
            }
            return $this->settings($principal);
        });
    }
}
