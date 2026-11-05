<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SrdFiles\{FolderAccess, FolderDocumentStore};

final class FolderQuotaController
{
    public function __invoke(Request $request, FolderAccess $access, FolderDocumentStore $store): array
    {
        $principal = $access->principal($request, true);
        return ['data' => $store->quota($principal['organization_id'])];
    }
}
