<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private function principal(string $user = '22222222-2222-4222-8222-222222222222', string $org = '11111111-1111-4111-8111-111111111111'): array
    {
        return ['organization_id' => $org, 'user_id' => $user, 'role' => 'viewer'];
    }

    private function delivery(): array
    {
        return ['organization_id' => '11111111-1111-4111-8111-111111111111',
            'user_id' => '22222222-2222-4222-8222-222222222222',
            'event_id' => '33333333-3333-4333-8333-333333333333',
            'kind' => 'invitation', 'title' => 'Reunión de prueba',
            'delivery_key' => str_repeat('a', 64)];
    }

    public function test_calendar_notice_keeps_both_authored_titles(): void
    {
        $principal = $this->principal();
        $this->internal('POST', 'deliveries', $this->delivery() + ['title_en' => 'Test meeting'], $principal, 'calendar')
            ->assertOk();
        $this->internal('GET', 'notifications', [], $principal)->assertOk()
            ->assertJsonPath('data.items.0.title_en', 'Test meeting')
            ->assertJsonPath('data.items.0.title', 'Reunión de prueba');
        $this->internal('POST', 'deliveries', $this->delivery() + ['title_en' => 'x'], $principal, 'calendar')
            ->assertUnprocessable();
    }

    public function test_chat_notices_include_sender_and_call_outcomes_without_message_content(): void
    {
        $principal = $this->principal();
        $base = ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id'],
            'conversation_id' => '33333333-3333-4333-8333-333333333333',
            'sender_id' => '44444444-4444-4444-8444-444444444444', 'sender_name' => 'Ana Gómez'];
        $messageId = '55555555-5555-4555-8555-555555555555';
        $this->internal('POST', 'deliveries', $base + ['kind' => 'chat_message', 'message_id' => $messageId,
            'delivery_key' => str_repeat('c', 64)], $principal, 'chat')->assertOk();
        $this->internal('POST', 'deliveries', $base + ['kind' => 'chat_call',
            'delivery_key' => str_repeat('d', 64)], $principal, 'chat')->assertOk();
        $this->internal('POST', 'deliveries', $base + ['kind' => 'chat_missed',
            'delivery_key' => str_repeat('e', 64)], $principal, 'chat')->assertOk();
        $items = $this->internal('GET', 'notifications', [], $principal)->assertOk()->json('data.items');
        $titles = array_column($items, 'title');
        sort($titles);
        $this->assertSame(['Llamada entrante de Ana Gómez', 'Llamada perdida de Ana Gómez', 'Nuevo mensaje de Ana Gómez'], $titles);
        $message = collect($items)->firstWhere('kind', 'chat_message');
        $this->assertSame($messageId, $message['message_id']);
        $this->assertSame($base['sender_id'], $message['sender_id']);
        $this->assertSame('Ana Gómez', $message['sender_name']);
    }

    public function test_delivery_is_idempotent_private_and_read_dismiss_are_scoped(): void
    {
        $principal = $this->principal();
        $item = $this->internal('POST', 'deliveries', $this->delivery(), $principal, 'calendar')->assertOk()->json('data');
        $this->internal('POST', 'deliveries', $this->delivery(), $principal, 'calendar')->assertOk()->assertJsonPath('data.id', $item['id']);
        $this->internal('GET', 'notifications', [], $principal)->assertOk()->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.items.0.title', 'Reunión de prueba');
        $other = $this->principal('44444444-4444-4444-8444-444444444444');
        $foreign = $this->principal($principal['user_id'], '55555555-5555-4555-8555-555555555555');
        $this->internal('GET', 'notifications', [], $other)->assertJsonPath('data.total', 0);
        $this->internal('GET', 'notifications', [], $foreign)->assertJsonPath('data.total', 0);
        $this->internal('POST', 'notifications/'.$item['id'].'/read', [], $other)->assertNotFound();
        $this->internal('DELETE', 'notifications/'.$item['id'], [], $foreign)->assertNotFound();
        $this->internal('POST', 'notifications/'.$item['id'].'/read', [], $principal)->assertOk();
        $this->internal('POST', 'notifications/'.$item['id'].'/read', [], $principal)->assertOk();
        $this->internal('GET', 'notifications', [], $principal)->assertJsonPath('data.unread', 0);
        $this->internal('DELETE', 'notifications/'.$item['id'], [], $principal)->assertOk();
        $this->internal('GET', 'notifications', [], $principal)->assertJsonPath('data.total', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('outbox_events', 3);
    }

    public function test_only_authorized_services_can_deliver_and_context_must_match(): void
    {
        $this->internal('POST', 'deliveries', $this->delivery(), $this->principal(), 'gateway')->assertForbidden();
        $this->internal('POST', 'deliveries', $this->delivery(), $this->principal(org: '55555555-5555-4555-8555-555555555555'), 'calendar')->assertForbidden();
        $this->internal('GET', 'notifications', [], $this->principal(), 'calendar')->assertUnauthorized();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_records_export_notice_is_idempotent_bilingual_and_private(): void
    {
        $principal = $this->principal();
        $delivery = ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id'],
            'export_job_id' => '66666666-6666-4666-8666-666666666666',
            'kind' => 'export_ready', 'delivery_key' => str_repeat('b', 64)];
        $id = $this->internal('POST', 'deliveries', $delivery, $principal, 'records')->assertOk()->json('data.id');
        $this->internal('POST', 'deliveries', $delivery, $principal, 'records')->assertOk()->assertJsonPath('data.id', $id);
        $this->internal('GET', 'notifications', [], $principal)->assertOk()
            ->assertJsonPath('data.items.0.export_job_id', $delivery['export_job_id'])
            ->assertJsonPath('data.items.0.title_en', 'Your people export is ready');
        $this->internal('GET', 'notifications', [], $this->principal('44444444-4444-4444-8444-444444444444'))
            ->assertJsonPath('data.total', 0);
        $this->internal('POST', 'deliveries', $delivery, $principal, 'gateway')->assertForbidden();
        $this->internal('POST', 'deliveries', $delivery,
            $this->principal(org: '55555555-5555-4555-8555-555555555555'), 'records')->assertForbidden();
    }

    public function test_preferences_are_private_and_suppress_only_optional_calendar_notices(): void
    {
        $owner = $this->principal();
        $other = $this->principal('44444444-4444-4444-8444-444444444444');
        $this->internal('GET', 'preferences', [], $owner)->assertOk()
            ->assertJsonPath('data.event_changes', true)->assertJsonPath('data.reminders', true);
        $this->internal('PUT', 'preferences', ['event_changes' => false, 'reminders' => false], $owner)->assertOk()
            ->assertJsonPath('data.reminders', false);
        $this->internal('GET', 'preferences', [], $other)->assertJsonPath('data.reminders', true);
        $this->internal('PUT', 'preferences', ['event_changes' => false], $owner)->assertUnprocessable();

        foreach (['invitation', 'event_changed', 'event_cancelled', 'reminder_24h', 'reminder_1h'] as $index => $kind) {
            $delivery = $this->delivery();
            $delivery['kind'] = $kind;
            $delivery['delivery_key'] = str_repeat((string) ($index + 1), 64);
            $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertOk();
            $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertOk();
        }
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.unread', 2);
        $this->assertDatabaseCount('notifications', 5);
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('notifications')->whereNotNull('dismissed_at')->count());
        $this->internal('PUT', 'preferences', ['event_changes' => true, 'reminders' => true], $owner)->assertOk();
        $delivery = $this->delivery();
        $delivery['kind'] = 'reminder_24h';
        $delivery['delivery_key'] = str_repeat('f', 64);
        $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertOk();
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.total', 3);
        $delivery['kind'] = 'reminder_1h';
        $delivery['delivery_key'] = str_repeat('e', 64);
        $oneHour = $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertOk()->json('data.id');
        $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertOk()->assertJsonPath('data.id', $oneHour);
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.total', 4)->assertJsonPath('data.unread', 4);
        $this->internal('GET', 'notifications', [], $other)->assertJsonPath('data.total', 0);
        $this->internal('GET', 'preferences', [], $other)->assertJsonPath('data.event_changes', true);
    }

    public function test_quiet_hours_are_optional_private_validated_and_preserved_for_legacy_clients(): void
    {
        $owner = $this->principal();
        $other = $this->principal('44444444-4444-4444-8444-444444444444');
        $foreign = $this->principal(org: '55555555-5555-4555-8555-555555555555');
        $this->internal('GET', 'preferences', [], $owner)->assertOk()
            ->assertJsonPath('data.quiet_start', null)->assertJsonPath('data.quiet_end', null);
        $base = ['event_changes' => true, 'reminders' => true];
        $this->internal('PUT', 'preferences', $base + ['quiet_start' => '22:00', 'quiet_end' => '07:00'], $owner)
            ->assertOk()->assertJsonPath('data.quiet_start', '22:00')->assertJsonPath('data.quiet_end', '07:00');
        foreach ([$other, $foreign] as $principal) {
            $this->internal('GET', 'preferences', [], $principal)->assertJsonPath('data.quiet_start', null);
        }
        foreach ([['quiet_start' => '24:00', 'quiet_end' => '07:00'], ['quiet_start' => '22:00', 'quiet_end' => '07:60'],
            ['quiet_start' => '9:00', 'quiet_end' => '10:00'], ['quiet_start' => '22:00'], ['quiet_end' => null],
            ['quiet_start' => null, 'quiet_end' => '07:00'], ['quiet_start' => '22:00', 'quiet_end' => '22:00']] as $invalid) {
            $this->internal('PUT', 'preferences', $base + $invalid, $owner)->assertUnprocessable();
        }
        $this->internal('PUT', 'preferences', ['event_changes' => false, 'reminders' => true], $owner)
            ->assertOk()->assertJsonPath('data.quiet_start', '22:00')->assertJsonPath('data.event_changes', false);
        $this->internal('PUT', 'preferences', $base + ['quiet_start' => '08:30', 'quiet_end' => '12:00'], $owner)->assertOk();
        $this->internal('POST', 'deliveries', $this->delivery(), $owner, 'calendar')->assertOk();
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.total', 1)->assertJsonPath('data.unread', 1);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('notifications')->whereNotNull('dismissed_at')->count());
        $this->internal('PUT', 'preferences', $base + ['quiet_start' => null, 'quiet_end' => null], $owner)
            ->assertOk()->assertJsonPath('data.quiet_start', null)->assertJsonPath('data.quiet_end', null);
        $this->internal('PUT', 'preferences', $base, $owner, 'calendar')->assertUnauthorized();
        $events = \Illuminate\Support\Facades\DB::table('outbox_events')->where('action', 'notifications.preferences_updated')->get();
        $this->assertCount(4, $events);
        $this->assertStringNotContainsString('22:00', json_encode($events));
        $this->assertStringNotContainsString('quiet_start', json_encode($events));
    }

    public function test_chat_notice_is_private_idempotent_and_respects_optional_preference(): void
    {
        $owner = $this->principal();
        $conversation = '44444444-4444-4444-8444-444444444444';
        $delivery = [
            'organization_id' => $owner['organization_id'], 'user_id' => $owner['user_id'],
            'conversation_id' => $conversation, 'kind' => 'chat_message',
            'delivery_key' => str_repeat('b', 64),
        ];
        $this->internal('POST', 'deliveries', $delivery, $owner, 'calendar')->assertUnprocessable();
        $this->internal('POST', 'deliveries', $this->delivery(), $owner, 'chat')->assertUnprocessable();
        $this->internal('POST', 'deliveries', $delivery, $this->principal(org: '55555555-5555-4555-8555-555555555555'), 'chat')->assertForbidden();
        $first = $this->internal('POST', 'deliveries', $delivery, $owner, 'chat')->assertOk()->json('data.id');
        $this->internal('POST', 'deliveries', $delivery, $owner, 'chat')->assertOk()->assertJsonPath('data.id', $first);
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.items.0.kind', 'chat_message')
            ->assertJsonPath('data.items.0.conversation_id', $conversation)
            ->assertJsonPath('data.items.0.event_id', null)
            ->assertJsonPath('data.items.0.title', 'Nuevo mensaje');
        $this->internal('GET', 'notifications', [], $this->principal('66666666-6666-4666-8666-666666666666'))
            ->assertJsonPath('data.total', 0);
        $this->internal('GET', 'preferences', [], $owner)->assertJsonPath('data.chat_messages', true);

        $this->internal('PUT', 'preferences', ['event_changes' => true, 'reminders' => true, 'chat_messages' => false], $owner)
            ->assertOk()->assertJsonPath('data.chat_messages', false);
        $delivery['delivery_key'] = str_repeat('c', 64);
        $this->internal('POST', 'deliveries', $delivery, $owner, 'chat')->assertOk();
        $this->internal('GET', 'notifications', [], $owner)->assertJsonPath('data.total', 1);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('notifications')->where('delivery_key', $delivery['delivery_key'])->value('dismissed_at'));
        $this->internal('PUT', 'preferences', ['event_changes' => true, 'reminders' => true], $owner)
            ->assertOk()->assertJsonPath('data.chat_messages', false);
        $events = \Illuminate\Support\Facades\DB::table('outbox_events')->get();
        $this->assertStringNotContainsString('Texto', json_encode($events));
    }
}
