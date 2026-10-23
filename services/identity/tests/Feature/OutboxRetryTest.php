<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class OutboxRetryTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private string $org = '11111111-1111-4111-8111-111111111111';
    private string $otherOrg = '22222222-2222-4222-8222-222222222222';
    private string $actor = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'admin'): array
    {
        return ['organization_id' => $this->org, 'user_id' => $this->actor, 'role' => $role];
    }

    private function event(string $org, int $attempts = 4, bool $published = false): string
    {
        $id = (string) Str::uuid();
        DB::table('outbox_events')->insert([
            'id' => $id, 'organization_id' => $org, 'actor_id' => null,
            'action' => 'test.original_event', 'resource_id' => null, 'result' => 'success',
            'correlation_id' => (string) Str::uuid(), 'occurred_at' => now()->subHour(),
            'published_at' => $published ? now() : null, 'attempts' => $attempts,
            'next_attempt_at' => now()->addHour(),
        ]);

        return $id;
    }

    public function test_only_gateway_and_managers_can_retry_an_exhausted_event_of_their_junta(): void
    {
        $id = $this->event($this->org);
        $foreign = $this->event($this->otherOrg);
        $due = $this->event($this->org, 1);
        $published = $this->event($this->org, 4, true);

        $this->postJson('/internal/v1/outbox-status/'.$id.'/retry')->assertUnauthorized();
        $this->internal('POST', 'outbox-status/'.$id.'/retry', [], $this->principal(), 'chat')->assertUnauthorized();
        foreach (['auditor', 'registrar', 'viewer'] as $role)
            $this->internal('POST', 'outbox-status/'.$id.'/retry', [], $this->principal($role))->assertForbidden();
        $this->internal('POST', 'outbox-status/'.$foreign.'/retry', [], $this->principal())->assertNotFound();
        $this->internal('POST', 'outbox-status/'.$due.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'outbox-status/'.$published.'/retry', [], $this->principal())->assertConflict();

        $this->internal('POST', 'outbox-status/'.$id.'/retry', [], $this->principal())->assertOk()
            ->assertJsonPath('data.queued', true);
        $this->assertDatabaseHas('outbox_events', ['id' => $id, 'attempts' => 0,
            'action' => 'test.original_event', 'published_at' => null]);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => $this->org,
            'actor_id' => $this->actor, 'action' => 'audit.delivery_retry_requested', 'resource_id' => $id]);
        $this->assertDatabaseHas('outbox_events', ['id' => $foreign, 'attempts' => 4]);
        $this->internal('POST', 'outbox-status/'.$id.'/retry', [], $this->principal())->assertConflict();
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'audit.delivery_retry_requested')->count());
    }
}
