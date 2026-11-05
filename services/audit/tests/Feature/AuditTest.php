<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class AuditTest extends TestCase
{
    use RefreshDatabase,SignedRequests;

    public function test_notifications_publish_only_their_own_events(): void
    {
        $event = $this->event(['service' => 'notifications', 'action' => 'notifications.read']);
        $this->internal('POST', 'events', $event, [], 'notifications')->assertOk();
        $this->internal('POST', 'events', $this->event(), [], 'notifications')->assertForbidden();
        $principal = ['organization_id' => $event['organization_id'], 'user_id' => $event['actor_id'], 'role' => 'auditor'];
        $this->internal('GET', 'events', ['service' => 'notifications'], $principal)->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_calendar_publishes_only_its_own_events_and_is_filterable(): void
    {
        $event = $this->event(['service' => 'calendar', 'action' => 'calendar.event_created']);
        $this->internal('POST', 'events', $event, [], 'calendar')->assertOk();
        $this->internal('POST', 'events', $event, [], 'calendar')->assertOk();
        $this->internal('POST', 'events', $this->event(), [], 'calendar')->assertForbidden();
        $principal = ['organization_id' => $event['organization_id'], 'user_id' => $event['actor_id'], 'role' => 'auditor'];
        $this->internal('GET', 'events', ['service' => 'calendar'], $principal)->assertOk()->assertJsonPath('data.total', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_files_can_publish_only_its_own_events_and_cannot_read_audit(): void
    {
        $event = $this->event(['service' => 'files', 'action' => 'photo.created']);
        $this->internal('POST', 'events', $event, [], 'files')->assertOk()->assertJsonPath('data.accepted', true);
        $this->internal('POST', 'events', $event, [], 'files')->assertOk();
        $this->assertDatabaseCount('audit_events', 1);
        $this->internal('POST', 'events', $this->event(), [], 'files')->assertForbidden();
        $principal = ['organization_id' => $event['organization_id'], 'user_id' => $event['actor_id'], 'role' => 'admin'];
        $this->internal('GET', 'events', [], $principal, 'files')->assertUnauthorized();
        $this->internal('GET', 'events', ['service' => 'files'], $principal)->assertOk()->assertJsonPath('data.total', 1);
    }

    private function event(array $changes = []): array
    {
        return array_replace(['id' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => '11111111-1111-4111-8111-111111111111', 'actor_id' => '22222222-2222-4222-8222-222222222222', 'service' => 'records', 'action' => 'person.created', 'resource_id' => null, 'result' => 'success', 'correlation_id' => (string) \Illuminate\Support\Str::uuid(), 'occurred_at' => '2026-09-10 12:00:00', 'event_version' => 1], $changes);
    }

    public function test_combined_filters_include_whole_utc_day_and_preserve_tenant_scope(): void
    {
        $first = $this->event(['occurred_at' => '2026-09-10 00:00:00']);
        $last = $this->event(['occurred_at' => '2026-09-10 23:59:59.999999']);
        $rows = [$first, $last];
        foreach ([['occurred_at' => '2026-09-09 23:59:59.999999'], ['occurred_at' => '2026-09-11 00:00:00'], ['actor_id' => null], ['service' => 'identity'], ['action' => 'person.updated'], ['result' => 'failed'], ['organization_id' => $first['actor_id']]] as $change) $rows[] = $this->event($change);
        \Illuminate\Support\Facades\DB::table('audit_events')->insert($rows);
        $principal = ['organization_id' => $first['organization_id'], 'user_id' => $first['actor_id'], 'role' => 'auditor'];
        $filters = ['date_from' => '2026-09-10', 'date_to' => '2026-09-10', 'actor_id' => $first['actor_id'], 'service' => 'records', 'action' => 'person.created', 'result' => 'success', 'organization_id' => $first['actor_id']];
        $this->internal('GET', 'events', $filters, $principal)->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.id', $last['id'])->assertJsonPath('data.items.1.id', $first['id']);
        $this->internal('GET', 'events', ['date_to' => '2026-09-09'], $principal)->assertOk()->assertJsonPath('data.total', 1);
        $this->internal('GET', 'events', ['date_from' => '2026-09-11'], $principal)->assertOk()->assertJsonPath('data.total', 1);
        $this->assertDatabaseCount('audit_events', 9);
    }

    public function test_pagination_has_stable_order_and_filtered_totals(): void
    {
        $rows = [];
        for ($i = 1; $i <= 26; $i++) $rows[] = $this->event(['id' => sprintf('33333333-3333-4333-8333-%012d', $i)]);
        \Illuminate\Support\Facades\DB::table('audit_events')->insert($rows);
        $principal = ['organization_id' => $rows[0]['organization_id'], 'user_id' => $rows[0]['actor_id'], 'role' => 'admin'];
        $ids = [];
        foreach ([1 => 10, 2 => 10, 3 => 6] as $page => $count) {
            $response = $this->internal('GET', 'events', ['page_size' => 10, 'page' => $page, 'action' => 'person.created'], $principal)
                ->assertOk()->assertJsonPath('data.total', 26)->assertJsonPath('data.page', $page)->assertJsonPath('data.page_size', 10)->assertJsonCount($count, 'data.items');
            $ids = array_merge($ids, array_column($response->json('data.items'), 'id'));
        }
        $this->assertSame(array_reverse(array_column($rows, 'id')), $ids);
        foreach ([25, 50] as $size) $this->internal('GET', 'events', ['page_size' => $size], $principal)->assertOk()->assertJsonCount(min($size, 26), 'data.items');
        $this->internal('GET', 'events', ['action' => 'absent'], $principal)->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.items');
        $this->internal('GET', 'events', ['action' => '0'], $principal)->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_invalid_filters_are_rejected_without_mutating_events(): void
    {
        $row = $this->event();
        \Illuminate\Support\Facades\DB::table('audit_events')->insert($row);
        $principal = ['organization_id' => $row['organization_id'], 'user_id' => $row['actor_id'], 'role' => 'admin'];
        foreach ([['date_from' => '2026-02-30'], ['date_to' => 'yesterday'], ['date_from' => '2026-09-11', 'date_to' => '2026-09-10'], ['actor_id' => 'invalid'], ['service' => 'unknown'], ['result' => 'unknown'], ['page_size' => 100], ['page' => 0], ['action' => str_repeat('a', 101)]] as $filters) {
            $this->internal('GET', 'events', $filters, $principal)->assertUnprocessable();
        }
        foreach (['registrar', 'treasurer', 'viewer'] as $role) $this->internal('GET', 'events', ['actor_id' => $row['actor_id']], array_replace($principal, ['role' => $role]))->assertForbidden();
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_ingestion_deduplicates_and_enforces_reader_roles_and_junta(): void
    {
        $org = '11111111-1111-4111-8111-111111111111';
        $event = ['id' => '22222222-2222-4222-8222-222222222222', 'organization_id' => $org, 'actor_id' => null, 'service' => 'records', 'action' => 'person.created', 'resource_id' => null, 'result' => 'success', 'correlation_id' => '33333333-3333-4333-8333-333333333333', 'occurred_at' => now()->toDateTimeString(), 'event_version' => 1];
        $this->internal('POST', 'events', $event, [], 'records')->assertOk();
        $this->internal('POST', 'events', $event, [], 'records')->assertOk();
        $this->assertDatabaseCount('audit_events', 1);
        $this->internal('POST', 'events', $event, [], 'identity')->assertForbidden();
        foreach (Access::ROLES as $role) {
            $r = $this->internal('GET', 'events', [], ['organization_id' => $org, 'user_id' => $event['id'], 'role' => $role]);
            if (in_array($role, ['superadmin', 'admin', 'auditor'])) {
                $r->assertOk()->assertJsonPath('data.total', 1);
            } else {
                $r->assertForbidden();
            }
        }
        $this->internal('GET', 'events', [], ['organization_id' => $event['id'], 'user_id' => $event['id'], 'role' => 'admin'])->assertOk()->assertJsonPath('data.total',0);
    }
}
