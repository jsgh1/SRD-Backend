<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SrdFiles\FolderAccess;

final class FolderAccessController
{
    public function show(Request $request, FolderAccess $access): array
    {
        abort_if(strlen($request->getContent()) > 4096, 413);
        return ['data' => $access->settings($access->principal($request, null))];
    }

    public function update(Request $request, FolderAccess $access): array
    {
        abort_if(strlen($request->getContent()) > 4096, 413);
        $principal = $access->principal($request, true);
        $data = $request->validate([
            'version' => 'required|integer|min:0|max:2147483646',
            'reader_roles' => 'present|array|list|max:4',
            'reader_roles.*' => 'required|in:registrar,treasurer,auditor,viewer|distinct:strict',
            'reader_memberships' => 'sometimes|array|list|max:20',
            'reader_memberships.*.id' => 'required|uuid|distinct:strict',
            'reader_memberships.*.version' => 'required|integer|min:1|max:2147483647',
        ]);
        return ['data' => $access->update($principal, (int)$data['version'], $data['reader_roles'], $data['reader_memberships'] ?? null)];
    }
}
