<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatAttachmentReferenceController
{
    public function __invoke(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'files', 403);
        $principal = $request->attributes->get('principal', []);
        abort_unless(!empty($principal['organization_id']), 403);

        return ['data' => ['referenced' => DB::table('messages')
            ->where('organization_id', $principal['organization_id'])
            ->whereNull('deleted_for_all_at')
            ->where('attachment_id', $id)->exists()]];
    }
}
