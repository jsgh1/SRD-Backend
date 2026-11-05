<?php

namespace Srd;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Outbox
{
    // Caller owns the transaction. Metadata must be an explicit allowlist, never request bodies.
    public static function record(string $action, ?string $org, ?string $actor, ?string $resource, string $result = 'success'): void
    {
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org,
            'actor_id' => $actor, 'action' => $action, 'resource_id' => $resource, 'result' => $result,
            'correlation_id' => request()->attributes->get('correlation_id', (string) Str::uuid()),
            'occurred_at' => now(), 'attempts' => 0, 'next_attempt_at' => now()]);
    }
}
