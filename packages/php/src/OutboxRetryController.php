<?php

namespace Srd;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OutboxRetryController
{
    public function __invoke(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require('audit.retry');

        DB::transaction(function () use ($principal, $id) {
            $event = DB::table('outbox_events')->where('id', $id)
                ->where('organization_id', $principal['organization_id'])->lockForUpdate()->first();
            abort_unless($event, 404);
            abort_unless($event->published_at === null && (int) $event->attempts >= 4, 409);

            DB::table('outbox_events')->where('id', $id)->update([
                'attempts' => 0, 'next_attempt_at' => now(),
            ]);
            Outbox::record('audit.delivery_retry_requested', $principal['organization_id'],
                $principal['user_id'], $id);
        });

        return ['data' => ['queued' => true]];
    }
}
