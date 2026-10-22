<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Srd\OutboxPublisher;
use Srd\OutboxStatusController;

final class FileOutboxStatusController
{
    public function __invoke(Request $request, OutboxPublisher $publisher): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);

        return (new OutboxStatusController)($publisher);
    }
}
