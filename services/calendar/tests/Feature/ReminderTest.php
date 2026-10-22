<?php

namespace Tests\Feature;

use App\Application\DeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class ReminderTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const MEMBER = '44444444-4444-4444-8444-444444444444';
    private function principal(string $role = 'admin', ?string $user = null): array
    {
        return ['organization_id' => '11111111-1111-4111-8111-111111111111',
            'user_id' => $user ?? '22222222-2222-4222-8222-222222222222', 'role' => $role];
    }
    private function input(): array
    {
        return ['type' => 'meeting', 'title' => 'Recordatorios de prueba', 'title_en' => 'Test reminders',
            'starts_at' => '2026-09-24T14:00:00+00:00', 'ends_at' => '2026-09-24T15:00:00+00:00',
            'participants' => [self::MEMBER]];
    }
    private function resolver(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn ($request) => Http::response(['data' => ['items' => array_map(
            fn ($id) => ['user_id' => $id, 'name' => 'Integrante de prueba'], $request['user_ids'] ?? [])]]));
    }

    public function test_one_hour_due_time_retry_and_backfill_keep_stable_distinct_keys(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $this->resolver();
            $event = $this->internal('POST', 'events', $this->input(), $this->principal())->assertOk()->json('data');
            $delivery = app(DeliveryService::class);
            $job = DB::table('calendar_delivery_jobs')->where('event_id', $event['id'])->where('kind', 'reminder_1h')->first();
            $this->assertSame('2026-09-24 13:00:00', $job->due_at);
            $this->assertSame('Test reminders', $job->title_en);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'reminder_24h', 'due_at' => '2026-09-23 14:00:00']);
            $this->assertSame(1, $delivery->backfillFutureReminders());
            $this->assertSame(1, $delivery->backfillFutureReminders());
            $this->assertDatabaseCount('calendar_delivery_jobs', 3);
            $this->assertSame(3, DB::table('calendar_delivery_jobs')->distinct()->count('delivery_key'));
            CarbonImmutable::setTestNow('2026-09-24T12:59:59Z');
            $this->assertSame('skipped', $delivery->deliverOne($job->id));
            CarbonImmutable::setTestNow('2026-09-24T13:00:00Z');
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response([], 503));
            $this->assertSame('retry', $delivery->deliverOne($job->id));
            $this->assertDatabaseHas('calendar_delivery_jobs', ['id' => $job->id, 'attempts' => 1, 'delivered_at' => null, 'delivery_key' => $job->delivery_key]);
            $this->assertSame('skipped', $delivery->deliverOne($job->id));
            CarbonImmutable::setTestNow('2026-09-24T13:01:00Z');
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response(['data' => ['id' => '66666666-6666-4666-8666-666666666666']]));
            $this->assertSame('delivered', $delivery->deliverOne($job->id));
            $this->assertSame('skipped', $delivery->deliverOne($job->id));
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request['kind'] === 'reminder_1h' && $request['delivery_key'] === $job->delivery_key
                && $request['title_en'] === 'Test reminders');
            $delivery->backfillFutureReminders();
            $this->assertDatabaseCount('calendar_delivery_jobs', 3);
            $this->assertNotNull(DB::table('calendar_delivery_jobs')->where('id', $job->id)->value('delivered_at'));
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_event_choices_survive_legacy_edits_responses_and_backfill(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $this->resolver();
            $admin = $this->principal();
            $member = $this->principal('viewer', self::MEMBER);
            $input = $this->input() + ['remind_24h' => false, 'remind_1h' => true];
            $event = $this->internal('POST', 'events', $input, $admin)->assertOk()
                ->assertJsonPath('data.remind_24h', false)->assertJsonPath('data.remind_1h', true)->json('data');
            $this->assertDatabaseCount('calendar_delivery_jobs', 2);
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['kind' => 'reminder_24h']);
            app(DeliveryService::class)->backfillFutureReminders();
            $this->assertDatabaseCount('calendar_delivery_jobs', 2);
            $this->internal('POST', 'invitations/'.$event['id'].'/respond', ['response' => 'declined', 'version' => 1], $member)->assertOk();
            $this->internal('POST', 'invitations/'.$event['id'].'/respond', ['response' => 'accepted', 'version' => 2], $member)->assertOk();
            $this->assertDatabaseCount('calendar_delivery_jobs', 2);
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['kind' => 'reminder_24h']);
            $updated = array_replace($this->input(), ['version' => 1, 'remind_24h' => true, 'remind_1h' => false]);
            $this->internal('PATCH', 'events/'.$event['id'], $updated, $member)->assertForbidden();
            $this->internal('PATCH', 'events/'.$event['id'], $updated, $admin)->assertOk()
                ->assertJsonPath('data.remind_24h', true)->assertJsonPath('data.remind_1h', false);
            $this->assertDatabaseCount('calendar_delivery_jobs', 2);
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['kind' => 'reminder_1h']);
            $legacy = $this->input() + ['version' => 2];
            $this->internal('PATCH', 'events/'.$event['id'], $legacy, $admin)->assertOk()
                ->assertJsonPath('data.remind_24h', true)->assertJsonPath('data.remind_1h', false);
            $this->internal('PATCH', 'events/'.$event['id'], $legacy, $admin)->assertConflict();
            $this->internal('PATCH', 'events/'.$event['id'], array_replace($updated, ['version' => 3, 'remind_24h' => false]), $admin)->assertOk();
            app(DeliveryService::class)->backfillFutureReminders();
            app(DeliveryService::class)->backfillFutureReminders();
            $this->assertDatabaseCount('calendar_delivery_jobs', 1);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['kind' => 'event_changed']);
            $this->internal('GET', 'events/'.$event['id'], [], $member)->assertOk()
                ->assertJsonPath('data.remind_24h', false)->assertJsonPath('data.remind_1h', false);
            foreach (['remind_24h', 'remind_1h'] as $field) $this->internal('POST', 'events', array_replace($input, [$field => 'invalid']), $admin)->assertUnprocessable();
            $default = $this->internal('POST', 'events', $this->input(), $admin)->assertOk()
                ->assertJsonPath('data.remind_24h', true)->assertJsonPath('data.remind_1h', true)->json('data');
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $default['id'], 'kind' => 'reminder_24h']);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $default['id'], 'kind' => 'reminder_1h']);
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_a_disabled_anticipation_cannot_deliver_an_old_job(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $this->resolver();
            $event = $this->internal('POST', 'events', $this->input(), $this->principal())->assertOk()->json('data');
            $job = DB::table('calendar_delivery_jobs')->where('event_id', $event['id'])->where('kind', 'reminder_1h')->first();
            DB::table('calendar_events')->where('id', $event['id'])->update(['remind_1h' => false]);
            CarbonImmutable::setTestNow('2026-09-24T13:00:00Z');
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake();
            $this->assertSame('discarded', app(DeliveryService::class)->deliverOne($job->id));
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['id' => $job->id]);
            Http::assertNothingSent();
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_reschedule_decline_accept_and_late_creation_manage_both_reminders(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $this->resolver();
            $admin = $this->principal();
            $member = $this->principal('viewer', self::MEMBER);
            $event = $this->internal('POST', 'events', $this->input(), $admin)->assertOk()->json('data');
            $oldKeys = DB::table('calendar_delivery_jobs')->whereIn('kind', ['reminder_24h', 'reminder_1h'])->pluck('delivery_key')->all();
            $updated = array_replace($this->input(), ['starts_at' => '2026-09-25T14:00:00+00:00', 'ends_at' => '2026-09-25T15:00:00+00:00', 'version' => 1]);
            unset($updated['participants']);
            $this->internal('PATCH', 'events/'.$event['id'], $updated, $admin)->assertOk();
            $this->assertSame(0, DB::table('calendar_delivery_jobs')->whereIn('delivery_key', $oldKeys)->count());
            $this->assertDatabaseCount('calendar_delivery_jobs', 3);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['kind' => 'reminder_1h', 'due_at' => '2026-09-25 13:00:00']);
            $this->internal('POST', 'invitations/'.$event['id'].'/respond', ['response' => 'declined', 'version' => 1], $member)->assertOk();
            $this->assertSame(0, DB::table('calendar_delivery_jobs')->whereIn('kind', ['reminder_24h', 'reminder_1h'])->count());
            $this->assertSame(0, app(DeliveryService::class)->backfillFutureReminders());
            $this->internal('POST', 'invitations/'.$event['id'].'/respond', ['response' => 'accepted', 'version' => 2], $member)->assertOk();
            $this->assertSame(2, DB::table('calendar_delivery_jobs')->whereIn('kind', ['reminder_24h', 'reminder_1h'])->count());
            $this->internal('PATCH', 'events/'.$event['id'], array_replace($updated, ['version' => 2, 'participants' => []]), $admin)->assertOk();
            $this->assertDatabaseCount('calendar_delivery_jobs', 0);
            $late = $this->internal('POST', 'events', array_replace($this->input(), [
                'starts_at' => '2026-09-23T12:30:00+00:00', 'ends_at' => '2026-09-23T13:30:00+00:00',
            ]), $admin)->assertOk()->json('data');
            foreach (['reminder_24h', 'reminder_1h'] as $kind) $this->assertDatabaseHas('calendar_delivery_jobs', [
                'event_id' => $late['id'], 'kind' => $kind, 'due_at' => '2026-09-23 12:00:00']);
            CarbonImmutable::setTestNow('2026-09-23T12:30:00Z');
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake();
            foreach (DB::table('calendar_delivery_jobs')->whereIn('kind', ['reminder_24h', 'reminder_1h'])->pluck('id') as $id) {
                $this->assertSame('discarded', app(DeliveryService::class)->deliverOne($id));
            }
            Http::assertNothingSent();
        } finally { CarbonImmutable::setTestNow(); }
    }
}
