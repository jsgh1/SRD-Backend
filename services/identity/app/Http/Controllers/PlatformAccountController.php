<?php

namespace App\Http\Controllers;

use App\Application\PlatformAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class PlatformAccountController
{
    public function __construct(private PlatformAccountService $accounts) {}

    private function principal(): array
    {
        $principal = Access::require('platform.manage');
        abort_unless(DB::table('users')->where('id', $principal['user_id'])
            ->where('active', true)->where('superadmin', true)->exists(), 403);

        return $principal;
    }

    public function index(Request $request): array
    {
        $this->principal();
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000',
            'search' => 'sometimes|string|max:120']);

        return ['data' => $this->accounts->listing((int) ($data['page'] ?? 1), trim($data['search'] ?? ''))];
    }

    public function update(Request $request, string $id): array
    {
        $principal = $this->principal();
        $data = $request->validate(['active' => 'required|boolean', 'expected_active' => 'required|boolean']);

        return ['data' => $this->accounts->update($principal, $id,
            (bool) $data['active'], (bool) $data['expected_active'])];
    }
}
