<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Outbox;

final class DeliveryRetryController
{
    public function __invoke(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require('calendar.delivery.manage');

        DB::transaction(function () use ($id, $principal) {
            $job = DB::table('calendar_delivery_jobs')->where('id', $id)
                ->where('organization_id', $principal['organization_id'])->lockForUpdate()->first();
            abort_unless($job, 404);
            abort_unless($job->delivered_at === null && (int) $job->attempts >= 8, 409);

            DB::table('calendar_delivery_jobs')->where('id', $id)->update([
                'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
            Outbox::record('calendar.delivery_retry_requested', $principal['organization_id'],
                $principal['user_id'], $id);
        });

        return ['data' => ['queued' => true]];
    }
}
