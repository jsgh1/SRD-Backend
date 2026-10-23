<?php

namespace Srd;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OutboxPublisher
{
    public function __construct(private InternalClient $client) {}

    public function publishOne(string $id): string
    {
        return DB::transaction(function () use ($id) {
            $event = DB::table('outbox_events')->where('id', $id)->lockForUpdate()->first();
            // A candidate can become stale while another publisher owns this lock.
            if (! $event || $event->published_at || $event->attempts >= 4 || Carbon::parse($event->next_attempt_at)->gt(now())) {
                return 'skipped';
            }
            try {
                $payload = array_intersect_key((array) $event, array_flip(['id', 'organization_id', 'actor_id', 'action', 'resource_id', 'result', 'correlation_id', 'occurred_at']));
                $ack = $this->client->call('audit', 'POST', 'events', $payload + ['service' => config('srd.service'), 'event_version' => 1]);
                if (($ack['accepted'] ?? null) !== true) {
                    throw new DependencyFailure;
                }
            } catch (\Throwable) {
                $attempt = (int) $event->attempts + 1;
                DB::table('outbox_events')->where('id', $id)->update([
                    'attempts' => $attempt,
                    'next_attempt_at' => now()->addMinutes([1, 5, 15, 15][$attempt - 1]),
                ]);
                return $attempt === 4 ? 'exhausted' : 'retry';
            }
            // A commit failure must roll back instead of being treated as a delivery rejection.
            DB::table('outbox_events')->where('id', $id)->update(['published_at' => now()]);
            return 'published';
        });
    }

    public function publishBatch(): array
    {
        $counts = ['published' => 0, 'retry' => 0, 'exhausted' => 0, 'skipped' => 0];
        $ids = DB::table('outbox_events')->whereNull('published_at')->where('attempts', '<', 4)->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('occurred_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) $counts[$this->publishOne($id)]++;
        return $counts;
    }

    public function status(?string $organizationId = null): array
    {
        $query = DB::table('outbox_events');
        if ($organizationId !== null) $query->where('organization_id', $organizationId);
        $row = $query->selectRaw(
            'COUNT(*) as total, SUM(CASE WHEN published_at IS NOT NULL THEN 1 ELSE 0 END) as published, '.
            'SUM(CASE WHEN published_at IS NULL AND attempts >= 4 THEN 1 ELSE 0 END) as exhausted, '.
            'SUM(CASE WHEN published_at IS NULL AND attempts < 4 AND next_attempt_at <= ? THEN 1 ELSE 0 END) as due', [now()]
        )->first();
        $pending = (int) $row->total - (int) $row->published;
        return ['service' => config('srd.service'), 'published' => (int) $row->published, 'pending' => $pending, 'due' => (int) $row->due,
            'deferred' => $pending - (int) $row->exhausted - (int) $row->due, 'exhausted' => (int) $row->exhausted];
    }
}
