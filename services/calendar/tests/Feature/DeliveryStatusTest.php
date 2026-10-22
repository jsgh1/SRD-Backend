<?php

namespace Tests\Feature;

use App\Application\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class DeliveryStatusTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const ACTOR = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'admin'): array
    {
        return ['organization_id' => self::ORG, 'user_id' => self::ACTOR, 'role' => $role];
    }

    private function job(string $organization, int $attempts, int $nextMinutes, bool $delivered = false): string
    {
        $event = (string) Str::uuid();
        $id = (string) Str::uuid();
        DB::table('calendar_events')->insert([
            'id' => $event, 'organization_id' => $organization, 'type' => 'meeting',
            'title' => 'Título privado', 'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(), 'created_by' => self::ACTOR,
            'updated_by' => self::ACTOR, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_participants')->insert([
            'event_id' => $event, 'organization_id' => $organization, 'user_id' => self::ACTOR,
            'name' => 'Integrante sintético', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_delivery_jobs')->insert([
            'id' => $id, 'organization_id' => $organization, 'user_id' => self::ACTOR,
            'event_id' => $event, 'kind' => 'event_changed', 'title' => 'Título privado',
            'delivery_key' => hash('sha256', $id), 'due_at' => now()->subHour(),
            'next_attempt_at' => now()->addMinutes($nextMinutes), 'attempts' => $attempts,
            'delivered_at' => $delivered ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function test_delivery_status_is_scoped_to_junta_and_omits_private_content(): void
    {
        $due = $this->job(self::ORG, 1, -1);
        $this->job(self::ORG, 2, 5);
        $exhausted = $this->job(self::ORG, 8, -1);
        $this->job(self::ORG, 0, -1, true);
        $this->job(self::OTHER, 8, -1);

        $this->getJson('/internal/v1/delivery-status')->assertUnauthorized();
        $this->internal('GET', 'delivery-status', [], $this->principal(), 'chat')->assertUnauthorized();
        $this->internal('GET', 'delivery-status', [], $this->principal('auditor'))->assertForbidden();
        $response = $this->internal('GET', 'delivery-status', [], $this->principal())
            ->assertOk()->assertJsonPath('data.delivered', 1)
            ->assertJsonPath('data.pending', 3)->assertJsonPath('data.due', 1)
            ->assertJsonPath('data.deferred', 1)->assertJsonPath('data.exhausted', 1)
            ->assertJsonPath('data.exhausted_jobs.0.id', $exhausted)
            ->assertJsonPath('data.exhausted_jobs.0.attempts', 8);
        $this->assertStringNotContainsString('Título privado', $response->getContent());
        $this->assertStringNotContainsString($due, $response->getContent());
    }

    public function test_only_managers_can_retry_an_exhausted_delivery_once_with_original_key(): void
    {
        $id = $this->job(self::ORG, 8, -1);
        $other = $this->job(self::OTHER, 8, -1);
        $pending = $this->job(self::ORG, 3, 5);
        $delivered = $this->job(self::ORG, 8, -1, true);
        $key = DB::table('calendar_delivery_jobs')->where('id', $id)->value('delivery_key');

        $this->postJson('/internal/v1/delivery-status/'.$id.'/retry')->assertUnauthorized();
        $this->internal('POST', 'delivery-status/'.$id.'/retry', [], $this->principal(), 'chat')->assertUnauthorized();
        foreach (['auditor', 'viewer', 'treasurer'] as $role)
            $this->internal('POST', 'delivery-status/'.$id.'/retry', [], $this->principal($role))->assertForbidden();
        $this->internal('POST', 'delivery-status/'.$other.'/retry', [], $this->principal())->assertNotFound();
        $this->internal('POST', 'delivery-status/'.$pending.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'delivery-status/'.$delivered.'/retry', [], $this->principal())->assertConflict();

        $this->internal('POST', 'delivery-status/'.$id.'/retry', [], $this->principal())->assertOk()
            ->assertJsonPath('data.queued', true);
        $this->assertDatabaseHas('calendar_delivery_jobs', ['id' => $id, 'attempts' => 0,
            'delivery_key' => $key, 'delivered_at' => null]);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG,
            'actor_id' => self::ACTOR, 'action' => 'calendar.delivery_retry_requested', 'resource_id' => $id]);
        $this->internal('POST', 'delivery-status/'.$id.'/retry', [], $this->principal())->assertConflict();
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'calendar.delivery_retry_requested')->count());

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['data' => ['id' => (string) Str::uuid()]]));
        $this->assertSame('delivered', app(DeliveryService::class)->deliverOne($id));
        $this->assertSame('skipped', app(DeliveryService::class)->deliverOne($id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['delivery_key'] === $key
            && $request['organization_id'] === self::ORG);
        $this->assertNotNull(DB::table('calendar_delivery_jobs')->where('id', $id)->value('delivered_at'));
    }

    public function test_retried_notice_for_finished_event_is_discarded_without_sending(): void
    {
        $id = $this->job(self::ORG, 8, -1);
        $event = DB::table('calendar_delivery_jobs')->where('id', $id)->value('event_id');
        DB::table('calendar_events')->where('id', $event)->update([
            'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(),
        ]);

        $this->internal('POST', 'delivery-status/'.$id.'/retry', [], $this->principal())->assertOk();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();
        $this->assertSame('discarded', app(DeliveryService::class)->deliverOne($id));
        $this->assertDatabaseMissing('calendar_delivery_jobs', ['id' => $id]);
        Http::assertNothingSent();
    }
}
