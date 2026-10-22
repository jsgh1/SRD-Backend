<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalendarParticipantsController
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'calendar', 403);
        $principal = $request->attributes->get('principal', []);
        abort_unless(is_array($principal) && !empty($principal['organization_id']) && !empty($principal['user_id']), 403);
        $data = $request->validate([
            'user_ids' => 'present|array|list|max:50',
            'user_ids.*' => 'required|uuid|distinct:strict',
        ]);
        $ids = $data['user_ids'];
        if (!$ids) return ['data' => ['items' => []]];
        $members = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $principal['organization_id'])->where('m.active', true)
            ->where('u.active', true)->whereIn('u.id', $ids)->get(['u.id', 'u.name'])->keyBy('id');
        if ($members->count() !== count($ids)) {
            throw ValidationException::withMessages(['participants' => 'Selecciona Ãºnicamente integrantes activos de esta junta.']);
        }

        return ['data' => ['items' => array_map(fn ($id) => ['user_id' => $id, 'name' => $members[$id]->name], $ids)]];
    }
}
