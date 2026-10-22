<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\DependencyFailure;
use Srd\InternalClient;

final class DeliveryService
{
    public function __construct(private InternalClient $client) {}

    public function enqueueReminders(string $organizationId, string $eventId, string $userId, string $title, CarbonImmutable $start, bool $remind24h = true, bool $remind1h = true, ?string $titleEn = null): void
    {
        $now = CarbonImmutable::now('UTC');
        if ($start->lessThanOrEqualTo($now)) return;
        foreach (['reminder_24h' => 24, 'reminder_1h' => 1] as $kind => $hours) {
            if (($hours === 24 && !$remind24h) || ($hours === 1 && !$remind1h)) continue;
            $due = $start->subHours($hours);
            if ($due->lessThan($now)) $due = $now;
            $this->enqueue($organizationId, $eventId, $userId, $kind, $title, $start->toIso8601String(), $due, $start, $titleEn);
        }
    }

    public function enqueue(string $organizationId, string $eventId, string $userId, string $kind, string $title, string $anchor, CarbonImmutable $due, ?CarbonImmutable $eventStart = null, ?string $titleEn = null): void
    {
        $key = hash('sha256', implode('|', [$organizationId, $eventId, $userId, $kind, $anchor]));
        DB::table('calendar_delivery_jobs')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'event_id' => $eventId,
            'user_id' => $userId, 'kind' => $kind, 'title' => $title, 'title_en' => $titleEn, 'delivery_key' => $key,
            'due_at' => $due, 'event_starts_at' => $eventStart, 'next_attempt_at' => $due, 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function clearPending(string $eventId, ?string $userId = null, ?array $kinds = null): void
    {
        $query = DB::table('calendar_delivery_jobs')->where('event_id', $eventId)->whereNull('delivered_at');
        if ($userId !== null) $query->where('user_id', $userId);
        if ($kinds !== null) $query->whereIn('kind', $kinds);
        $query->delete();
    }

    public function deliverOne(string $id): string
    {
        return DB::transaction(function () use ($id) {
            $job = DB::table('calendar_delivery_jobs')->where('id', $id)->lockForUpdate()->first();
            if (!$job || $job->delivered_at !== null || $job->attempts >= 8
                || CarbonImmutable::parse($job->next_attempt_at, 'UTC')->isFuture()) return 'skipped';
            $event = DB::table('calendar_events')->where('id', $job->event_id)
                ->where('organization_id', $job->organization_id)->first();
            $participant = DB::table('calendar_participants')->where('event_id', $job->event_id)
                ->where('organization_id', $job->organization_id)->where('user_id', $job->user_id)->first();
            $valid = $event && $participant && ($job->kind === 'event_cancelled'
                ? $event->cancelled_at !== null
                : $event->cancelled_at === null && CarbonImmutable::parse($event->starts_at, 'UTC')->isFuture());
            if ($valid && in_array($job->kind, ['reminder_24h', 'reminder_1h'], true)) {
                $valid = $participant->response !== 'declined'
                    && (bool) $event->{$job->kind === 'reminder_24h' ? 'remind_24h' : 'remind_1h'}
                    && $job->event_starts_at !== null
                    && CarbonImmutable::parse($event->starts_at, 'UTC')->equalTo(CarbonImmutable::parse($job->event_starts_at, 'UTC'));
            }
            if (!$valid) {
                DB::table('calendar_delivery_jobs')->where('id', $id)->delete();
                return 'discarded';
            }
            try {
                $result = $this->client->call('notifications', 'POST', 'deliveries', [
                    'organization_id' => $job->organization_id, 'event_id' => $job->event_id,
                    'user_id' => $job->user_id, 'kind' => $job->kind,
                    'title' => $job->title, 'title_en' => $job->title_en, 'delivery_key' => $job->delivery_key,
                ], ['organization_id' => $job->organization_id]);
                if (empty($result['id'])) throw new DependencyFailure;
            } catch (\Throwable) {
                $attempt = (int) $job->attempts + 1;
                DB::table('calendar_delivery_jobs')->where('id', $id)->update([
                    'attempts' => $attempt,
                    'next_attempt_at' => now()->addMinutes(min(60, 2 ** ($attempt - 1))),
                    'updated_at' => now(),
                ]);
                return $attempt === 8 ? 'exhausted' : 'retry';
            }
            DB::table('calendar_delivery_jobs')->where('id', $id)->update(['delivered_at' => now(), 'updated_at' => now()]);
            return 'delivered';
        });
    }

    public function deliverBatch(): array
    {
        $counts = ['delivered' => 0, 'retry' => 0, 'exhausted' => 0, 'discarded' => 0, 'skipped' => 0];
        $ids = DB::table('calendar_delivery_jobs')->whereNull('delivered_at')->where('attempts', '<', 8)
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) $counts[$this->deliverOne($id)]++;
        return $counts;
    }

    public function backfillFutureReminders(): int
    {
        $count = 0;
        DB::table('calendar_participants as p')->join('calendar_events as e', 'e.id', '=', 'p.event_id')
            ->whereColumn('p.organization_id', 'e.organization_id')
            ->whereNull('e.cancelled_at')->where('e.starts_at', '>', CarbonImmutable::now('UTC'))
            ->where('p.response', '!=', 'declined')
            ->orderBy('p.event_id')->orderBy('p.user_id')
            ->select('p.organization_id', 'p.event_id', 'p.user_id', 'e.title', 'e.title_en', 'e.starts_at', 'e.remind_24h', 'e.remind_1h')
            ->chunk(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $start = CarbonImmutable::parse($row->starts_at, 'UTC');
                    $this->enqueueReminders($row->organization_id, $row->event_id, $row->user_id, $row->title, $start,
                        (bool) $row->remind_24h, (bool) $row->remind_1h, $row->title_en);
                    $count++;
                }
            });
        return $count;
    }
}
