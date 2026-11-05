<?php
namespace App\Http\Controllers;

use App\Application\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class ContactController
{
    public function show(string $id, PresenceService $presence): array
    {
        $p = Access::require('contacts.read');
        abort_if($id === $p['user_id'], 404);
        $contact = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('member_profile_titles as pt', function ($join) {
                $join->on('pt.organization_id', '=', 'm.organization_id')->on('pt.user_id', '=', 'm.user_id');
            })
            ->leftJoin('member_profile_bios as pb', function ($join) {
                $join->on('pb.organization_id', '=', 'm.organization_id')->on('pb.user_id', '=', 'm.user_id');
            })
            ->where('m.organization_id', $p['organization_id'])->where('m.user_id', $id)
            ->where('m.active', true)->where('u.active', true)
            ->first(['u.id', 'u.name', 'u.email', 'm.role', 'm.person_id', 'u.superadmin', 'pt.title', 'pt.title_en', 'pb.bio', 'pb.bio_en']);
        abort_unless($contact, 404);
        $states = $presence->effectiveMany([$id]);
        return ['data' => ['id' => $contact->id, 'name' => $contact->name,
            'role' => $contact->superadmin ? 'superadmin' : $contact->role,
            'presence' => $states[$id] ?? 'offline', 'title' => $contact->title, 'title_en' => $contact->title_en,
            'email' => $contact->email, 'bio' => $contact->bio, 'bio_en' => $contact->bio_en,
            'person_id' => $contact->person_id]];
    }

    public function __invoke(Request $r, PresenceService $presence): array
    {
        $p = Access::require('contacts.read');
        $d = $r->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $query = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('member_profile_titles as pt', function ($join) {
                $join->on('pt.organization_id', '=', 'm.organization_id')->on('pt.user_id', '=', 'm.user_id');
            })
            ->where('m.organization_id', $p['organization_id'])->where('m.active', true)->where('u.active', true)
            ->where('u.id', '!=', $p['user_id']);
        if (($d['q'] ?? '') !== '') {
            // Explicit escape character keeps literal %, _ and ! consistent on SQLite/MySQL.
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $d['q']).'%';
            $query->whereRaw("u.name LIKE ? ESCAPE '!'", [$needle]);
        }
        $total = (clone $query)->count();
        $page = (int) ($d['page'] ?? 1);
        $items = $query->orderBy('u.name')->orderBy('u.id')->forPage($page, 25)
            ->get(['u.id', 'u.name', 'm.role', 'u.superadmin', 'pt.title', 'pt.title_en']);
        $states = $presence->effectiveMany($items->pluck('id')->all());
        return ['data' => ['items' => $items->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'role' => $u->superadmin ? 'superadmin' : $u->role,
            'presence' => $states[$u->id] ?? 'offline',
            'title' => $u->title, 'title_en' => $u->title_en,
        ]), 'page' => $page, 'page_size' => 25, 'total' => $total]];
    }
}
