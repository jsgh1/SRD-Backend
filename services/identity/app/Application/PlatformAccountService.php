<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Srd\Outbox;

final class PlatformAccountService
{
    public function __construct(private MembershipService $members) {}

    public function listing(int $page, string $search): array
    {
        $query = DB::table('users as u')->where('u.superadmin', false);
        if ($search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("u.name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                    ->orWhereRaw("u.email LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            });
        }

        return ['items' => (clone $query)->select('u.id', 'u.name', 'u.email', 'u.active')
            ->selectSub(DB::table('memberships as m')->selectRaw('COUNT(*)')->whereColumn('m.user_id', 'u.id'), 'memberships_count')
            ->orderBy('u.name')->orderBy('u.id')->forPage($page, 25)->get(),
            'total' => $query->count(), 'page' => $page, 'page_size' => 25];
    }

    public function update(array $principal, string $id, bool $active, bool $expectedActive): array
    {
        abort_if($id === $principal['user_id'] || $active === $expectedActive, 422);

        return DB::transaction(function () use ($principal, $id, $active, $expectedActive) {
            $organizations = DB::table('memberships')->where('user_id', $id)
                ->distinct()->orderBy('organization_id')->pluck('organization_id');
            foreach ($organizations as $organizationId) $this->members->lockOrganization($organizationId);

            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            abort_unless($user, 404);
            abort_if($user->superadmin, 403);
            abort_unless((bool) $user->active === $expectedActive, 409);

            if (! $active) {
                $adminOrganizations = DB::table('memberships')->where('user_id', $id)
                    ->where('role', 'admin')->where('active', true)->pluck('organization_id');
                foreach ($adminOrganizations as $organizationId) {
                    $otherAdmins = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                        ->where('m.organization_id', $organizationId)->where('m.user_id', '!=', $id)
                        ->where('m.role', 'admin')->where('m.active', true)->where('u.active', true)->exists();
                    abort_unless($otherAdmins, 409);
                }
            }

            DB::table('users')->where('id', $id)->update(['active' => $active, 'updated_at' => now()]);
            $revokedIds = [];
            if (! $active) {
                $revokedIds = DB::table('auth_sessions')->where('user_id', $id)->whereNull('revoked_at')
                    ->lockForUpdate()->pluck('id')->all();
                if ($revokedIds !== []) {
                    DB::table('auth_sessions')->whereIn('id', $revokedIds)->update(['revoked_at' => now()]);
                    foreach (array_chunk($revokedIds, 500) as $batch) app(ChatSocketClosureQueue::class)->enqueue($batch);
                }
                DB::table('auth_challenges')->where('user_id', $id)->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            }
            foreach ($organizations as $organizationId) {
                Outbox::record($active ? 'account.activated' : 'account.suspended', $organizationId,
                    $principal['user_id'], $id);
            }

            return ['active' => $active, 'revoked_session_ids' => array_slice($revokedIds, 0, 20)];
        });
    }
}
