<?php

namespace Srd;

use Illuminate\Support\Facades\DB;

final class OutboxStatusController
{
    public function __invoke(OutboxPublisher $publisher): array
    {
        $p = Access::require('audit.read');
        $exhausted = DB::table('outbox_events')->where('organization_id', $p['organization_id'])->whereNull('published_at')->where('attempts', '>=', 4)
            ->orderBy('occurred_at')->orderBy('id')->limit(20)->get(['id', 'action', 'attempts', 'occurred_at']);
        return ['data' => $publisher->status($p['organization_id']) + ['exhausted_events' => $exhausted]];
    }
}
