<?php

namespace Srd;

use Illuminate\Http\Request;

final class SchedulerHealthController
{
    public function __invoke(Request $request, SchedulerHealth $health): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);

        return ['data' => $health->status()];
    }
}
