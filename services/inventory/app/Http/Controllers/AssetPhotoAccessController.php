<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

/** Internal yes/no check; never exposes the asset or its other fields. */
final class AssetPhotoAccessController
{
    public function __invoke(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'files', 403);
        $data = $request->validate(['action' => 'required|in:inventory.read,inventory.write']);
        $principal = Access::require($data['action']);
        $asset = DB::table('assets')->where('organization_id', $principal['organization_id'])->where('id', $id)->first(['status']);
        abort_unless($asset, 404);
        abort_if($data['action'] === 'inventory.write' && $asset->status !== 'active', 403);
        return ['data' => ['authorized' => true]];
    }
}
