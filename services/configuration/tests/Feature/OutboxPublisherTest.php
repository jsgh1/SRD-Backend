<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Srd\Outbox;
use Srd\OutboxPublisher;
use Tests\TestCase;

final class OutboxPublisherTest extends TestCase
{
    use RefreshDatabase;
    use \Srd\Testing\SignedRequests;

    public function test_web_status_checks_roles_and_never_includes_other_juntas(): void
    {
        $org = (string) Str::uuid();
        $other = (string) Str::uuid();
        $user = (string) Str::uuid();
        Outbox::record('probe.own', $org, $user, $user);
        Outbox::record('probe.foreign', $other, $user, $user);
        DB::table('outbox_events')->where('organization_id', $org)->update(['attempts' => 4]);
        DB::table('outbox_events')->where('organization_id', $other)->update(['attempts' => 4]);
        foreach (['viewer', 'registrar', 'treasurer'] as $role) {
            $this->internal('GET', 'outbox-status', [], ['organization_id' => $org, 'user_id' => $user, 'role' => $role])->assertForbidden();
        }
        foreach (['admin', 'superadmin', 'auditor'] as $role) {
            $this->internal('GET', 'outbox-status', ['organization_id' => $other], ['organization_id' => $org, 'user_id' => $user, 'role' => $role])
                ->assertOk()->assertJsonPath('data.pending', 1)->assertJsonPath('data.exhausted', 1)->assertJsonCount(1, 'data.exhausted_events')
                ->assertJsonPath('data.exhausted_events.0.action', 'probe.own')->assertJsonMissingPath('data.exhausted_events.0.actor_id');
        }
        $this->assertDatabaseCount('outbox_events', 2);
    }

    private function event(): string
    {
        $id = (string) Str::uuid();
        Outbox::record('outbox.unit_probe', null, null, $id);
        return DB::table('outbox_events')->where('resource_id', $id)->value('id');
    }

    public function test_stale_candidates_recheck_backoff_exhaustion_and_publication(): void
    {
        Http::fake(fn () => Http::response([], 503));
        $publisher = app(OutboxPublisher::class);
        $id = $this->event();
        $this->assertSame('retry', $publisher->publishOne($id));
        $this->assertSame('skipped', $publisher->publishOne($id));
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->assertSame('retry', $publisher->publishOne($id));
        $this->travel(301)->seconds();
        $this->assertSame('retry', $publisher->publishOne($id));
        $this->travel(901)->seconds();
        $this->assertSame('exhausted', $publisher->publishOne($id));
        $this->travel(901)->seconds();
        $this->assertSame('skipped', $publisher->publishOne($id));
        Http::assertSentCount(4);
        $this->assertDatabaseHas('outbox_events', ['id' => $id, 'attempts' => 4, 'published_at' => null]);
        DB::table('outbox_events')->where('id', $id)->update(['attempts' => 0, 'published_at' => now()]);
        $this->assertSame('skipped', $publisher->publishOne($id));
        $this->assertSame('skipped', $publisher->publishOne((string) Str::uuid()));
        Http::assertSentCount(4);
    }

    public function test_a_positive_ack_is_required_and_only_event_fields_are_sent(): void
    {
        Http::fake(fn () => Http::response(['data' => ['accepted' => false]], 200));
        $id = $this->event();
        $this->assertSame('retry', app(OutboxPublisher::class)->publishOne($id));
        $this->assertDatabaseHas('outbox_events', ['id' => $id, 'published_at' => null]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['data' => ['accepted' => true]]));
        $this->travel(61)->seconds();
        $this->assertSame('published', app(OutboxPublisher::class)->publishOne($id));
        Http::assertSent(fn ($request) => $request['id'] === $id && $request['event_version'] === 1 && $request['service'] === 'configuration'
            && !isset($request['attempts']) && !isset($request['next_attempt_at']) && !isset($request['published_at']));
        $this->assertNotNull(DB::table('outbox_events')->where('id', $id)->value('published_at'));
        $this->assertSame('skipped', app(OutboxPublisher::class)->publishOne($id));
        Http::assertSentCount(1);
    }

    public function test_status_is_read_only_and_batch_skips_deferred_and_exhausted_rows(): void
    {
        Http::fake(fn () => Http::response(['data' => ['accepted' => true]]));
        $published = $this->event();
        DB::table('outbox_events')->where('id', $published)->update(['published_at' => now()]);
        $later = $this->event();
        DB::table('outbox_events')->where('id', $later)->update(['next_attempt_at' => now()->addHour()]);
        $exhausted = $this->event();
        DB::table('outbox_events')->where('id', $exhausted)->update(['attempts' => 4]);
        $this->event();
        $publisher = app(OutboxPublisher::class);
        $this->assertSame(['service' => 'configuration', 'published' => 1, 'pending' => 3, 'due' => 1, 'deferred' => 1, 'exhausted' => 1], $publisher->status());
        Http::assertNothingSent();
        $this->assertSame(['published' => 1, 'retry' => 0, 'exhausted' => 0, 'skipped' => 0], $publisher->publishBatch());
        Http::assertSentCount(1);
        $this->assertSame(2, $publisher->status()['pending']);
        $this->artisan('srd:outbox-status')->assertExitCode(0);
        Http::assertSentCount(1);
    }
}
