<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class MailDeliveryStatusTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const ACTOR = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'admin'): array
    {
        return ['organization_id' => self::ORG, 'user_id' => self::ACTOR, 'role' => $role];
    }

    private function invitation(string $organization, int $attempts, int $nextMinutes, bool $expired = false, bool $sent = false): string
    {
        $id = (string) Str::uuid();
        DB::table('invitations')->insert([
            'id' => $id, 'organization_id' => $organization,
            'invited_by' => self::ACTOR, 'email' => 'privado@srd-e2e.test', 'role' => 'viewer',
            'secret_hash' => str_repeat('a', 64), 'secret_encrypted' => 'no-divulgar',
            'expires_at' => $expired ? now()->subMinute() : now()->addDay(),
            'sent_at' => $sent ? now() : null, 'delivery_attempts' => $attempts,
            'next_attempt_at' => now()->addMinutes($nextMinutes), 'created_at' => now(),
        ]);
        return $id;
    }

    private function notice(string $organization, int $attempts, int $nextMinutes, bool $sent = false): string
    {
        $id = (string) Str::uuid();
        DB::table('security_notices')->insert([
            'id' => $id, 'organization_id' => $organization,
            'user_id' => self::ACTOR, 'destination_encrypted' => 'no-divulgar',
            'kind' => 'email_changed', 'attempts' => $attempts,
            'next_attempt_at' => now()->addMinutes($nextMinutes),
            'sent_at' => $sent ? now() : null, 'created_at' => now(),
        ]);
        return $id;
    }

    public function test_mail_status_is_admin_only_scoped_and_contains_no_addresses(): void
    {
        $this->invitation(self::ORG, 0, -1);
        $this->invitation(self::ORG, 2, 5);
        $invitation = $this->invitation(self::ORG, 4, -1);
        $this->invitation(self::ORG, 0, -1, true);
        $this->invitation(self::ORG, 0, -1, false, true);
        $this->invitation(self::OTHER, 4, -1);
        $this->notice(self::ORG, 0, -1);
        $this->notice(self::ORG, 2, 5);
        $notice = $this->notice(self::ORG, 4, -1);
        $this->notice(self::ORG, 0, -1, true);
        $this->notice(self::OTHER, 4, -1);

        $this->getJson('/internal/v1/mail-delivery-status')->assertUnauthorized();
        $this->internal('GET', 'mail-delivery-status', [], $this->principal(), 'chat')->assertUnauthorized();
        foreach (['auditor', 'viewer', 'treasurer'] as $role)
            $this->internal('GET', 'mail-delivery-status', [], $this->principal($role))->assertForbidden();
        $response = $this->internal('GET', 'mail-delivery-status', [], $this->principal())
            ->assertOk()->assertJsonPath('data.invitations.pending', 4)
            ->assertJsonPath('data.invitations.due', 1)
            ->assertJsonPath('data.invitations.deferred', 1)
            ->assertJsonPath('data.invitations.exhausted', 1)
            ->assertJsonPath('data.invitations.exhausted_items.0.id', $invitation)
            ->assertJsonPath('data.invitations.exhausted_items.0.retryable', true)
            ->assertJsonPath('data.invitations.expired', 1)
            ->assertJsonPath('data.security_notices.pending', 3)
            ->assertJsonPath('data.security_notices.due', 1)
            ->assertJsonPath('data.security_notices.deferred', 1)
            ->assertJsonPath('data.security_notices.exhausted', 1)
            ->assertJsonPath('data.security_notices.exhausted_items.0.id', $notice)
            ->assertJsonPath('data.security_notices.exhausted_items.0.retryable', true);
        $this->assertStringNotContainsString('privado@srd-e2e.test', $response->getContent());
        $this->assertStringNotContainsString('no-divulgar', $response->getContent());
    }

    public function test_only_managers_can_retry_exhausted_mail_of_their_junta(): void
    {
        $invitation = $this->invitation(self::ORG, 4, -1);
        $notice = $this->notice(self::ORG, 4, -1);
        $otherInvitation = $this->invitation(self::OTHER, 4, -1);
        $otherNotice = $this->notice(self::OTHER, 4, -1);
        $pending = $this->invitation(self::ORG, 2, 5);
        $expired = $this->invitation(self::ORG, 4, -1, true);
        $sent = $this->notice(self::ORG, 4, -1, true);
        $missingSecret = $this->invitation(self::ORG, 4, -1);
        $missingDestination = $this->notice(self::ORG, 4, -1);
        DB::table('invitations')->where('id', $missingSecret)->update(['secret_encrypted' => null]);
        DB::table('security_notices')->where('id', $missingDestination)->update(['destination_encrypted' => null]);

        $this->postJson('/internal/v1/mail-delivery-status/invitations/'.$invitation.'/retry')->assertUnauthorized();
        $this->internal('POST', 'mail-delivery-status/invitations/'.$invitation.'/retry', [], $this->principal(), 'chat')->assertUnauthorized();
        foreach (['auditor', 'viewer', 'treasurer'] as $role)
            $this->internal('POST', 'mail-delivery-status/invitations/'.$invitation.'/retry', [], $this->principal($role))->assertForbidden();
        $this->internal('POST', 'mail-delivery-status/invitations/'.$otherInvitation.'/retry', [], $this->principal())->assertNotFound();
        $this->internal('POST', 'mail-delivery-status/security-notices/'.$otherNotice.'/retry', [], $this->principal())->assertNotFound();
        $this->internal('POST', 'mail-delivery-status/invitations/'.$pending.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'mail-delivery-status/invitations/'.$expired.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'mail-delivery-status/security-notices/'.$sent.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'mail-delivery-status/invitations/'.$missingSecret.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'mail-delivery-status/security-notices/'.$missingDestination.'/retry', [], $this->principal())->assertConflict();
        $this->internal('POST', 'mail-delivery-status/other/'.$invitation.'/retry', [], $this->principal())->assertNotFound();
        $status = $this->internal('GET', 'mail-delivery-status', [], $this->principal())->assertOk()->json('data');
        $invitationItem = collect($status['invitations']['exhausted_items'])->firstWhere('id', $missingSecret);
        $noticeItem = collect($status['security_notices']['exhausted_items'])->firstWhere('id', $missingDestination);
        $this->assertFalse($invitationItem['retryable']);
        $this->assertFalse($noticeItem['retryable']);

        $this->internal('POST', 'mail-delivery-status/invitations/'.$invitation.'/retry', [], $this->principal())
            ->assertOk()->assertJsonPath('data.queued', true);
        $this->assertDatabaseHas('invitations', ['id' => $invitation, 'delivery_attempts' => 0]);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG,
            'actor_id' => self::ACTOR, 'action' => 'mail.invitation_retry_requested', 'resource_id' => $invitation]);
        $this->internal('POST', 'mail-delivery-status/invitations/'.$invitation.'/retry', [], $this->principal())->assertConflict();

        $this->internal('POST', 'mail-delivery-status/security-notices/'.$notice.'/retry', [], $this->principal('superadmin'))
            ->assertOk()->assertJsonPath('data.queued', true);
        $this->assertDatabaseHas('security_notices', ['id' => $notice, 'attempts' => 0]);
        $this->assertDatabaseHas('outbox_events', ['organization_id' => self::ORG,
            'actor_id' => self::ACTOR, 'action' => 'mail.security_notice_retry_requested', 'resource_id' => $notice]);
        $this->internal('POST', 'mail-delivery-status/security-notices/'.$notice.'/retry', [], $this->principal())->assertConflict();
        $this->assertSame(2, DB::table('outbox_events')->whereIn('action', [
            'mail.invitation_retry_requested', 'mail.security_notice_retry_requested',
        ])->count());
    }
}
