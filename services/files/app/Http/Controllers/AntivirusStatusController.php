<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SrdFiles\AntivirusStatus;

final class AntivirusStatusController
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        return ['data' => ['available' => (new AntivirusStatus(
            config('photos.signature_marker'), config('photos.scanner')
        ))->ready()]];
    }
}
