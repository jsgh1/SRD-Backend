<?php

namespace App\Http\Controllers;

use App\Application\InvitationService;
use App\Application\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Srd\Access;

final class MembershipController
{
    public function __construct(private MembershipService $members, private InvitationService $invitations) {}

    public function index(Request $r): array
    {
        $p = Access::require('members.manage');
        $d = $r->validate(['page' => 'sometimes|integer|min:1']);

        return ['data' => $this->members->listing($p, (int) ($d['page'] ?? 1))];
    }

    public function folderReaders(Request $r): array
    {
        $p = Access::require('members.manage');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'search' => 'sometimes|string|max:120',
            'ids' => 'sometimes|array|list|max:20', 'ids.*' => 'required|uuid|distinct:strict']);
        return ['data' => $this->members->folderReaders($p, (int)($d['page'] ?? 1), $d['search'] ?? null, $d['ids'] ?? [])];
    }

    public function verifyFolderReaders(Request $r): array
    {
        $p = Access::require('members.manage');
        $d = $r->validate(['ids' => 'present|array|list|max:20', 'ids.*' => 'required|uuid|distinct:strict']);
        return ['data' => ['items' => $this->members->verifiedFolderReaders($p, $d['ids'])]];
    }

    public function update(Request $r, string $id): array
    {
        $p = Access::require('members.manage');
        $d = $r->validate(['role' => ['required', Rule::in(MembershipService::ASSIGNABLE)], 'active' => 'required|boolean', 'version' => 'required|integer|min:1']);

        return ['data' => $this->members->update($p, $id, $d)];
    }

    public function invitations(Request $r): array
    {
        $p = Access::require('members.manage');
        $d = $r->validate(['page' => 'sometimes|integer|min:1']);

        return ['data' => $this->invitations->listing($p, (int) ($d['page'] ?? 1))];
    }

    public function personInvitationStatus(string $id): array
    {
        return ['data' => $this->invitations->personStatus(Access::require('members.invite'), $id)];
    }

    public function invite(Request $r): array
    {
        $p = Access::require('members.invite');
        $d = $r->validate(['email' => 'required|email|max:254', 'role' => ['required', Rule::in(MembershipService::ASSIGNABLE)],
            'person_id' => 'sometimes|nullable|uuid']);

        return ['data' => $this->invitations->issue($p, strtolower($d['email']), $d['role'], $d['person_id'] ?? null)];
    }

    public function inviteAdministrator(Request $r, string $id): array
    {
        $p = Access::require('platform.manage');
        abort_unless(\Illuminate\Support\Facades\DB::table('users')->where('id', $p['user_id'])->where('active', true)->where('superadmin', true)->exists(), 403);
        $d = $r->validate(['email' => 'required|email|max:254']);
        $p['organization_id'] = $id;
        return ['data' => $this->invitations->issue($p, strtolower($d['email']), 'admin')];
    }

    public function revoke(string $id): array
    {
        $this->invitations->revoke(Access::require('members.manage'), $id);

        return ['data' => []];
    }

    public function inspect(Request $r): array
    {
        $d = $r->validate(['invitation_id' => 'required|uuid', 'secret' => 'required|regex:/^[a-f0-9]{64}$/']);

        return ['data' => $this->invitations->inspect($d['invitation_id'], $d['secret'])];
    }

    public function accept(Request $r): array
    {
        $d = $r->validate(['invitation_id' => 'required|uuid', 'secret' => 'required|regex:/^[a-f0-9]{64}$/', 'name' => 'required|string|min:2|max:120',
            'password' => 'required|string|min:12|max:128|confirmed', 'terms_version_id' => 'required|uuid',
            'accepted' => 'required|accepted', 'language' => 'sometimes|in:es,en']);

        return ['data' => $this->invitations->accept($d)];
    }
}
