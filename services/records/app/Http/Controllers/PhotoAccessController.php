<?php

namespace App\Http\Controllers;

use App\Persistence\PersonRepository;
use Illuminate\Http\Request;
use Srd\Access;
use Srd\InternalClient;

/** Internal decision only: no person fields, notes or persistent access grants. */
final class PhotoAccessController
{
    public function __invoke(Request $request, string $id, PersonRepository $persons): array
    {
        abort_unless($request->attributes->get('issuer') === 'files', 403);
        $data = $request->validate(['action' => 'required|in:persons.read,persons.write',
            'slot' => 'sometimes|nullable|in:person,document,property']);
        $principal = Access::require('persons.read');
        abort_unless($persons->forOrganization($principal['organization_id'])->where('id', $id)->exists(), 404);
        if ($data['action'] === 'persons.write' && !Access::allows($principal['role'], 'persons.write')) {
            abort_unless(($data['slot'] ?? null) === 'person', 403);
            $result = app(InternalClient::class)->call('identity', 'POST', "persons/$id/self-photo-access", [], $principal);
            abort_unless(($result['authorized'] ?? false) === true, 403);
        }

        return ['data' => ['authorized' => true]];
    }
}
