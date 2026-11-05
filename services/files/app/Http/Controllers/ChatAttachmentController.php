<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SrdFiles\ChatAttachmentStore;

final class ChatAttachmentController
{
    private function principal(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'chat', 403);
        $p = $request->attributes->get('principal', []);
        abort_unless(!empty($p['organization_id']) && !empty($p['user_id']), 403);
        return $p;
    }

    public function store(Request $request, ChatAttachmentStore $store): array
    {
        $p = $this->principal($request);
        abort_if(strlen($request->getContent()) > 14 * 1024 * 1024 + 4096, 413);
        $d = $request->validate([
            'id' => 'required|uuid', 'conversation_id' => 'required|uuid',
            'name' => 'required|string|max:255', 'content' => 'required|string|max:13981016',
        ]);
        return ['data' => $store->put($p, $d)];
    }

    public function show(Request $request, ChatAttachmentStore $store, string $id): array
    {
        $p = $this->principal($request);
        $d = $request->validate(['conversation_id' => 'required|uuid']);
        return ['data' => $store->read($p, $id, $d['conversation_id'])];
    }
}
