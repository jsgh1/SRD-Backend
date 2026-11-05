<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class CalendarTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private function principal(string $role = 'admin', string $organization = '11111111-1111-4111-8111-111111111111'): array
    {
        return ['organization_id' => $organization, 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => $role];
    }

    private function event(array $changes = []): array
    {
        return array_replace(['type' => 'meeting', 'title' => 'Reunion de junta', 'starts_at' => '2026-09-24T09:00:00-05:00', 'ends_at' => '2026-09-24T10:00:00-05:00'], $changes);
    }

    public function test_event_text_versions_are_saved_searched_and_preserved_for_legacy_updates(): void
    {
        $admin = $this->principal();
        $created = $this->internal('POST', 'events', $this->event([
            'title_en' => 'Council meeting', 'location' => 'Salon comunal', 'location_en' => 'Community hall',
            'description' => 'Orden del dia', 'description_en' => 'Agenda',
        ]), $admin)->assertOk()->assertJsonPath('data.title_en', 'Council meeting')
            ->assertJsonPath('data.location_en', 'Community hall')->json('data');
        $this->internal('GET', 'events/search', [], $admin, 'gateway', ['q' => 'Council meeting'])
            ->assertOk()->assertJsonPath('data.items.0.id', $created['id']);
        $this->internal('GET', 'events/search', [], $admin, 'gateway', ['q' => 'Community hall'])
            ->assertOk()->assertJsonPath('data.items.0.id', $created['id']);
        $this->internal('PATCH', 'events/'.$created['id'], $this->event(['version' => 1, 'title' => 'Reunion editada']), $admin)
            ->assertOk()->assertJsonPath('data.title_en', 'Council meeting');
        $this->internal('GET', 'events/'.$created['id'], [], $admin)
            ->assertOk()->assertJsonPath('data.description_en', 'Agenda');
        $this->internal('POST', 'events', $this->event(['title_en' => 'x']), $admin)->assertUnprocessable();
    }

    public function test_delegation_is_immediate_and_only_managers_can_change_it(): void
    {
        $admin = $this->principal();
        $viewer = $this->principal('viewer');
        $this->internal('GET', 'settings', [], $viewer)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->internal('POST', 'events', $this->event(), $viewer)->assertForbidden();
        $this->internal('PUT', 'settings', ['version' => 0, 'editor_roles' => ['viewer']], $viewer)->assertForbidden();
        $this->internal('PUT', 'settings', ['version' => 0, 'editor_roles' => ['viewer']], $admin)->assertOk()->assertJsonPath('data.can_manage', true);
        $this->internal('POST', 'events', $this->event(), $viewer)->assertOk();
        $this->internal('PUT', 'settings', ['version' => 1, 'editor_roles' => []], $admin)->assertOk();
        $this->internal('POST', 'events', $this->event(), $viewer)->assertForbidden();
        $this->internal('PUT', 'settings', ['version' => 1, 'editor_roles' => ['viewer']], $admin)->assertStatus(409);
        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertDatabaseCount('outbox_events', 3);
    }

    public function test_events_are_tenant_scoped_versioned_and_cancel_is_terminal(): void
    {
        $admin = $this->principal();
        $other = $this->principal('admin', '33333333-3333-4333-8333-333333333333');
        $created = $this->internal('POST', 'events', $this->event(), $admin)->assertOk()->json('data');
        $id = $created['id'];
        $this->internal('GET', 'events/'.$id, [], $other)->assertNotFound();
        $this->internal('PATCH', 'events/'.$id, $this->event(['version' => 1]), $other)->assertNotFound();
        $this->internal('GET', 'events', ['from' => '2026-09-24', 'to' => '2026-09-24'], $other)->assertJsonCount(0, 'data.items');
        $this->internal('PATCH', 'events/'.$id, $this->event(['version' => 1, 'title' => 'Reunion editada']), $admin)->assertOk()->assertJsonPath('data.version', 2);
        $this->internal('PATCH', 'events/'.$id, $this->event(['version' => 1]), $admin)->assertStatus(409);
        $this->internal('POST', 'events/'.$id.'/cancel', ['version' => 2], $admin)->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->internal('POST', 'events/'.$id.'/cancel', ['version' => 2], $admin)->assertStatus(409);
        $this->internal('PATCH', 'events/'.$id, $this->event(['version' => 3]), $admin)->assertStatus(409);
        $this->assertDatabaseCount('outbox_events', 3);
    }

    public function test_local_date_window_and_computed_states(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T14:00:00Z');
        try {
            $admin = $this->principal();
            $this->internal('POST', 'events', $this->event(['starts_at' => '2026-09-23T23:30:00-05:00', 'ends_at' => '2026-09-24T00:30:00-05:00']), $admin)->assertOk()->assertJsonPath('data.state', 'upcoming');
            $this->internal('GET', 'events', ['from' => '2026-09-23', 'to' => '2026-09-23'], $admin)->assertOk()->assertJsonCount(1, 'data.items');
            $this->internal('GET', 'events', ['from' => '2026-09-24', 'to' => '2026-09-24'], $admin)->assertOk()->assertJsonCount(1, 'data.items');
            $this->internal('GET', 'events', ['from' => '2026-09-25', 'to' => '2026-09-25'], $admin)->assertOk()->assertJsonCount(0, 'data.items');
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_states_use_24_hours_and_exact_start_end_boundaries_without_writing(): void
    {
        CarbonImmutable::setTestNow('2026-09-25T14:00:00Z');
        try {
            $p = $this->principal();
            $event = $this->internal('POST', 'events', $this->event([
                'starts_at' => '2026-09-26T09:00:00-05:00', 'ends_at' => '2026-09-26T10:00:00-05:00',
            ]), $p)->assertOk()->assertJsonPath('data.state', 'upcoming')->json('data');
            $beforeEvents = \Illuminate\Support\Facades\DB::table('outbox_events')->count();
            $viewer = $this->principal('viewer');
            foreach ([['2026-09-25T13:59:59Z', 'scheduled'], ['2026-09-25T14:00:00Z', 'upcoming'],
                ['2026-09-26T13:59:59Z', 'upcoming'], ['2026-09-26T14:00:00Z', 'in_progress'],
                ['2026-09-26T14:59:59Z', 'in_progress'], ['2026-09-26T15:00:00Z', 'finished'],
                ['2026-09-27T14:00:00Z', 'finished']] as [$now, $state]) {
                CarbonImmutable::setTestNow($now);
                $this->internal('GET', 'events/'.$event['id'], [], $viewer)->assertOk()->assertJsonPath('data.state', $state);
                $this->internal('GET', 'events', [], $viewer, 'gateway', ['from' => '2026-09-26', 'to' => '2026-09-26'])
                    ->assertOk()->assertJsonPath('data.items.0.state', $state);
                $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => 'Reunion de junta'])
                    ->assertOk()->assertJsonPath('data.items.0.state', $state);
            }
            $this->assertSame($beforeEvents, \Illuminate\Support\Facades\DB::table('outbox_events')->count());
            $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'version' => 1, 'cancelled_at' => null]);
            $this->internal('POST', 'events/'.$event['id'].'/cancel', ['version' => 1], $p)->assertOk()->assertJsonPath('data.state', 'cancelled');
            foreach (['2026-09-25T13:59:59Z', '2026-09-26T14:00:00Z', '2026-09-27T14:00:00Z'] as $now) {
                CarbonImmutable::setTestNow($now);
                $this->internal('GET', 'events/'.$event['id'], [], $viewer)->assertOk()->assertJsonPath('data.state', 'cancelled');
            }
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_bad_intervals_and_foreign_issuers_cannot_write(): void
    {
        $admin = $this->principal();
        $this->internal('POST', 'events', $this->event(['ends_at' => '2026-09-24T08:00:00-05:00']), $admin)->assertUnprocessable();
        $this->internal('POST', 'events', $this->event(), $admin, 'records')->assertForbidden();
        $this->internal('GET', 'events', ['from' => '2026-01-01', 'to' => '2026-09-24'], $admin)->assertUnprocessable();
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_participants_are_saved_atomically_and_omitted_updates_preserve_them(): void
    {
        $person = '44444444-4444-4444-8444-444444444444';
        Http::fake(fn () => Http::response(['data' => ['items' => [['user_id' => $person, 'name' => 'Integrante de prueba']]]]));
        $admin = $this->principal();
        $created = $this->internal('POST', 'events', $this->event(['participants' => [$person]]), $admin)
            ->assertOk()->assertJsonPath('data.participants.0.name', 'Integrante de prueba')->json('data');
        $this->assertDatabaseHas('calendar_participants', ['event_id' => $created['id'], 'organization_id' => $admin['organization_id'], 'user_id' => $person]);
        $this->internal('PATCH', 'events/'.$created['id'], $this->event(['version' => 1]), $admin)
            ->assertOk()->assertJsonCount(1, 'data.participants');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['data' => ['items' => []]]));
        $this->internal('PATCH', 'events/'.$created['id'], $this->event(['version' => 2, 'participants' => []]), $admin)
            ->assertOk()->assertJsonCount(0, 'data.participants');
        $this->assertDatabaseCount('calendar_participants', 0);
        $this->internal('POST', 'events', $this->event(['participants' => [$person, $person]]), $admin)->assertUnprocessable();
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_identity_rejection_does_not_create_an_event(): void
    {
        Http::fake(fn () => Http::response(['error' => ['fields' => ['participants' => ['Integrante ajeno.']]]], 422));
        $this->internal('POST', 'events', $this->event(['participants' => ['44444444-4444-4444-8444-444444444444']]), $this->principal())
            ->assertUnprocessable();
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_invited_member_can_respond_and_editor_changes_preserve_the_answer(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $admin = $this->principal();
            $memberId = '44444444-4444-4444-8444-444444444444';
            $member = array_replace($admin, ['user_id' => $memberId, 'role' => 'viewer']);
            $outsider = array_replace($member, ['user_id' => '55555555-5555-4555-8555-555555555555']);
            $foreign = array_replace($member, ['organization_id' => '33333333-3333-4333-8333-333333333333']);
            Http::fake(fn () => Http::response(['data' => ['items' => [['user_id' => $memberId, 'name' => 'Integrante invitado']]]]));
            $event = $this->internal('POST', 'events', $this->event(['participants' => [$memberId]]), $admin)
                ->assertOk()->assertJsonPath('data.participants.0.response', 'pending')->json('data');
            $id = $event['id'];
            $this->internal('GET', 'invitations', [], $member)->assertOk()->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.items.0.response', 'pending');
            $this->internal('GET', 'invitations', [], $outsider)->assertJsonPath('data.total', 0);
            $this->internal('POST', 'invitations/'.$id.'/respond', ['response' => 'accepted', 'version' => 1], $outsider)->assertNotFound();
            $this->internal('POST', 'invitations/'.$id.'/respond', ['response' => 'accepted', 'version' => 1], $foreign)->assertNotFound();
            $this->internal('POST', 'invitations/'.$id.'/respond', ['response' => 'accepted', 'version' => 1], $member)
                ->assertOk()->assertJsonPath('data.participants.0.response', 'accepted')->assertJsonPath('data.participants.0.response_version', 2);
            $this->internal('POST', 'invitations/'.$id.'/respond', ['response' => 'declined', 'version' => 1], $member)->assertStatus(409);
            $this->internal('PATCH', 'events/'.$id, $this->event(['version' => 1, 'participants' => [$memberId]]), $admin)
                ->assertOk()->assertJsonPath('data.participants.0.response', 'accepted');
            $this->internal('GET', 'invitations', [], $member)->assertJsonPath('data.items.0.response', 'accepted');
            $this->internal('POST', 'events/'.$id.'/cancel', ['version' => 2], $admin)->assertOk();
            $this->internal('POST', 'invitations/'.$id.'/respond', ['response' => 'declined', 'version' => 2], $member)->assertStatus(409);
            $this->internal('GET', 'invitations', [], $member)->assertJsonPath('data.total', 0);
            $this->assertDatabaseCount('outbox_events', 4);
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_notification_jobs_are_due_once_and_cancellation_replaces_pending_reminders(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $member = '44444444-4444-4444-8444-444444444444';
            $admin = $this->principal();
            Http::fake(fn () => Http::response(['data' => ['items' => [['user_id' => $member, 'name' => 'Integrante de prueba']]]]));
            $event = $this->internal('POST', 'events', $this->event(['participants' => [$member]]), $admin)->assertOk()->json('data');
            $this->assertDatabaseCount('calendar_delivery_jobs', 3);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'invitation']);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'reminder_24h']);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'reminder_1h']);
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response(['error' => 'Servicio temporalmente no disponible'], 503));
            $this->artisan('srd:notification-deliveries')->assertExitCode(0);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'invitation', 'attempts' => 1, 'delivered_at' => null]);
            CarbonImmutable::setTestNow('2026-09-23T12:02:00Z');
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response(['data' => ['id' => '66666666-6666-4666-8666-666666666666']]));
            $this->artisan('srd:notification-deliveries')->assertExitCode(0);
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('calendar_delivery_jobs')->whereNotNull('delivered_at')->count());
            $this->artisan('srd:notification-deliveries')->assertExitCode(0);
            $this->assertSame(1, \Illuminate\Support\Facades\DB::table('calendar_delivery_jobs')->whereNotNull('delivered_at')->count());
            $this->internal('POST', 'events/'.$event['id'].'/cancel', ['version' => 1], $admin)->assertOk();
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'reminder_24h']);
            $this->assertDatabaseMissing('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'reminder_1h']);
            $this->assertDatabaseHas('calendar_delivery_jobs', ['event_id' => $event['id'], 'kind' => 'event_cancelled']);
            $this->artisan('srd:notification-deliveries')->assertExitCode(0);
            $this->assertSame(2, \Illuminate\Support\Facades\DB::table('calendar_delivery_jobs')->whereNotNull('delivered_at')->count());
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_search_finds_events_across_dates_with_literal_text_and_tenant_isolation(): void
    {
        $admin = $this->principal();
        $viewer = $this->principal('viewer');
        $wanted = $this->internal('POST', 'events', $this->event([
            'title' => 'Reunión 50%_! especial', 'location' => 'Salón histórico',
            'starts_at' => '2024-01-10T09:00:00-05:00', 'ends_at' => '2024-01-10T10:00:00-05:00',
        ]), $admin)->assertOk()->json('data');
        $this->internal('POST', 'events', $this->event(['title' => 'Reunión 50ABC! distinta']), $admin)->assertOk();
        $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => '50%_!'])
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $wanted['id'])
            ->assertJsonPath('data.page_size', 25);
        $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => 'histórico'])
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $wanted['id']);
        $this->internal('GET', 'events/search', [], $this->principal(organization: '33333333-3333-4333-8333-333333333333'), 'gateway', ['q' => '50%_!'])
            ->assertJsonPath('data.total', 0);
        $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => '   '])->assertUnprocessable();
        $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => str_repeat('x', 121)])->assertUnprocessable();
        $this->internal('GET', 'events/search', [], $viewer, 'gateway', ['q' => '50%_!', 'page' => 0])->assertUnprocessable();
        $this->internal('GET', 'events/search', [], $viewer, 'records', ['q' => '50%_!'])->assertForbidden();
    }
}
