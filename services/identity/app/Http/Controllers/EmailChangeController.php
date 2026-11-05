<?php

namespace App\Http\Controllers;

use App\Application\EmailChangeService;
use Illuminate\Http\Request;
use Srd\Access;

final class EmailChangeController
{
    public function __construct(private EmailChangeService $service) {}

    public function request(Request $request): array
    {
        $principal = Access::require('profile.write');
        $data = $request->validate(['new_email' => 'required|email|max:254', 'password' => 'required|string|max:1024']);

        return ['data' => $this->service->request($principal, strtolower($data['new_email']), $data['password'])];
    }

    public function resend(Request $request): array
    {
        $principal = Access::require('profile.write');
        $data = $request->validate(['challenge_id' => 'required|uuid']);

        return ['data' => $this->service->resend($principal, $data['challenge_id'])];
    }

    public function confirm(Request $request): array
    {
        $principal = Access::require('profile.write');
        $data = $request->validate(['challenge_id' => 'required|uuid', 'code' => 'required|digits:6']);
        $revokedIds = $this->service->confirm($principal, $data['challenge_id'], $data['code']);
        abort_if($revokedIds === false, 422);

        return ['data' => ['message' => 'Correo actualizado. Se revocaron las otras sesiones y se programó un aviso al correo anterior.',
            'revoked_session_ids' => $revokedIds]];
    }
}
