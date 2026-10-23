<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChatContactController
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'chat', 403);
        $principal = $request->attributes->get('principal', []);
        abort_unless(is_array($principal) && !empty($principal['organization_id']) && !empty($principal['user_id']), 403);
        $data = $request->validate(['user_id' => 'required|uuid']);
        if ($data['user_id'] === $principal['user_id']) {
            throw ValidationException::withMessages(['user_id' => 'Elige otro integrante de la junta.']);
        }
        $contact = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $principal['organization_id'])
            ->where('m.user_id', $data['user_id'])->where('m.active', true)->where('u.active', true)
            ->first(['u.id', 'u.name', 'm.role', 'u.superadmin']);
        if (!$contact) {
            throw ValidationException::withMessages(['user_id' => 'Elige un integrante activo de esta junta.']);
        }
        return ['data' => ['id' => $contact->id, 'name' => $contact->name,
            'role' => $contact->superadmin ? 'superadmin' : $contact->role]];
    }
}
