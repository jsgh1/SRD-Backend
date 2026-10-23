<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SrdFiles\PhotoStorage;

final class PhotoDeletionController {
    public function __invoke(Request $request, string $id): array {
        abort_unless($request->attributes->get('issuer') === 'records', 403);
        $p = $request->attributes->get('principal', []);
        abort_unless(is_array($p) && Validator::make($p, [
            'organization_id'=>'required|uuid', 'user_id'=>'required|uuid', 'correlation_id'=>'required|uuid',
        ])->passes(), 403);
        // Authority is exclusively the signed service context, never request data.
        app(PhotoStorage::class)->purgePerson($p, $id);
        return ['data'=>['accepted'=>true]];
    }
}
