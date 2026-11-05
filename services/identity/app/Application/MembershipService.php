<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Srd\Outbox;

final class MembershipService
{
    public const ASSIGNABLE = ['admin', 'registrar', 'treasurer', 'auditor', 'viewer'];

    // All membership/invitation mutations lock the organization before rows/users.
    public function lockOrganization(string $id): void
    {
        DB::table('membership_guards')->insertOrIgnore(['organization_id' => $id]);
        DB::table('membership_guards')->where('organization_id', $id)->lockForUpdate()->first();
    }

    public function canManage(string $userId, string $organizationId): bool
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user || ! $user->active) {
            return false;
        }

        return $user->superadmin || DB::table('memberships')->where('user_id', $userId)
            ->where('organization_id', $organizationId)->where('active', true)->where('role', 'admin')->exists();
    }

    public function canInvite(string $userId, string $organizationId, string $role, ?string $personId): bool
    {
        if ($this->canManage($userId, $organizationId)) return true;
        return $role === 'viewer' && $personId !== null
            && DB::table('users')->where('id', $userId)->where('active', true)->exists()
            && DB::table('memberships')->where('user_id', $userId)->where('organization_id', $organizationId)
                ->where('active', true)->where('role', 'registrar')->exists();
    }

    public function listing(array $principal, int $page): array
    {
        $query = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $principal['organization_id']);

        return ['items' => (clone $query)->select('m.id', 'm.user_id', 'm.person_id', 'm.role', 'm.active', 'm.version', 'u.name', 'u.email', 'u.superadmin')
            ->orderBy('u.name')->orderBy('m.id')->forPage($page, 25)->get(), 'total' => $query->count(), 'page' => $page, 'page_size' => 25];
    }

    public function folderReaders(array $principal, int $page, ?string $search = null, array $ids = []): array
    {
        $query = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $principal['organization_id'])->where('m.active', true)
            ->where('u.active', true)->whereIn('m.role', ['registrar', 'treasurer', 'auditor', 'viewer']);
        if ($ids !== []) $query->whereIn('m.id', $ids);
        elseif ($search !== null && $search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("u.name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                    ->orWhereRaw("u.email LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            });
        }
        return ['items' => (clone $query)->select('m.id', 'm.version', 'm.role', 'u.name', 'u.email')
            ->orderBy('u.name')->orderBy('m.id')->forPage($page, 25)->get(),
            'total' => $query->count(), 'page' => $page, 'page_size' => 25];
    }

    public function verifiedFolderReaders(array $principal, array $ids): array
    {
        if ($ids === []) return [];
        $items = $this->folderReaders($principal, 1, null, $ids)['items'];
        abort_unless(count($items) === count($ids), 422);
        $versions = [];
        foreach ($items as $item) $versions[$item->id] = (int)$item->version;
        return array_map(fn ($id) => ['id' => $id, 'version' => $versions[$id]], $ids);
    }

    public function update(array $p, string $id, array $data): array
    {
        return DB::transaction(function () use ($p, $id, $data) {
            $this->lockOrganization($p['organization_id']);
            abort_unless($this->canManage($p['user_id'], $p['organization_id']), 403);
            $member = DB::table('memberships')->where('organization_id', $p['organization_id'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($member, 404);
            $user = DB::table('users')->where('id', $member->user_id)->first();
            abort_if($member->user_id === $p['user_id'] || $user->superadmin, 403);
            abort_unless((int) $member->version === $data['version'], 409);
            if ($member->active && $member->role === 'admin' && (! $data['active'] || $data['role'] !== 'admin')) {
                abort_unless(DB::table('memberships')->where('organization_id', $p['organization_id'])
                    ->where('active', true)->where('role', 'admin')->count() > 1, 409);
            }
            DB::table('memberships')->where('id', $id)->update(['role' => $data['role'], 'active' => $data['active'], 'version' => $data['version'] + 1]);
            $revokedIds = DB::table('auth_sessions')->where('organization_id', $p['organization_id'])
                ->where('user_id', $member->user_id)->whereNull('revoked_at')
                ->lockForUpdate()->pluck('id')->all();
            if ($revokedIds !== []) {
                DB::table('auth_sessions')->whereIn('id', $revokedIds)->update(['revoked_at' => now()]);
                app(ChatSocketClosureQueue::class)->enqueue($revokedIds);
            }
            DB::table('auth_challenges')->where('organization_id', $p['organization_id'])->where('user_id', $member->user_id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            Outbox::record('membership.updated', $p['organization_id'], $p['user_id'], $id);

            return ['version' => $data['version'] + 1, 'revoked_session_ids' => $revokedIds];
        });
    }
}
