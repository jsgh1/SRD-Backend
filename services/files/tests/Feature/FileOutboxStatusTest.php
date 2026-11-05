<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class FileOutboxStatusTest extends TestCase
{
    use SignedRequests;

    public function test_status_is_limited_to_the_signed_junta_and_audit_readers(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('action');
            $table->unsignedInteger('attempts');
            $table->dateTime('occurred_at');
            $table->dateTime('next_attempt_at');
            $table->dateTime('published_at')->nullable();
        });
        $org = '11111111-1111-4111-8111-111111111111';
        $foreign = '33333333-3333-4333-8333-333333333333';
        $principal = ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'auditor'];
        $rows = [];
        for ($i = 1; $i <= 21; $i++) $rows[] = $this->row($org, $i, 4, null, now()->subHour());
        $rows[] = $this->row($org, 22, 0, null, now()->subMinute());
        $rows[] = $this->row($org, 23, 1, null, now()->addHour());
        $rows[] = $this->row($org, 24, 0, now()->subMinute(), now()->subHour());
        $rows[] = $this->row($foreign, 25, 4, null, now()->subHour());
        DB::table('outbox_events')->insert($rows);

        $this->getJson('/internal/v1/outbox-status')->assertUnauthorized();
        $this->internal('GET', 'outbox-status', [], $principal, 'identity')->assertForbidden();
        foreach (['registrar', 'treasurer', 'viewer'] as $role) {
            $this->internal('GET', 'outbox-status', ['organization_id' => $foreign], array_replace($principal, ['role' => $role]))->assertForbidden();
        }
        foreach (['superadmin', 'admin', 'auditor'] as $role) {
            $response = $this->internal('GET', 'outbox-status', ['organization_id' => $foreign], array_replace($principal, ['role' => $role]))
                ->assertOk()->assertJsonPath('data.service', 'files')->assertJsonPath('data.published', 1)
                ->assertJsonPath('data.pending', 23)->assertJsonPath('data.due', 1)
                ->assertJsonPath('data.deferred', 1)->assertJsonPath('data.exhausted', 21)
                ->assertJsonCount(20, 'data.exhausted_events');
            $this->assertNotContains($rows[24]['id'], array_column($response->json('data.exhausted_events'), 'id'));
        }
        $this->internal('GET', 'outbox-status', [], array_replace($principal, ['organization_id' => $foreign]))
            ->assertOk()->assertJsonPath('data.published', 0)->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.exhausted', 1);
    }

    private function row(string $org, int $number, int $attempts, ?\DateTimeInterface $published, \DateTimeInterface $next): array
    {
        return [
            'id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $number), 'organization_id' => $org,
            'action' => 'photo.created', 'attempts' => $attempts,
            'occurred_at' => now()->subDay()->toDateTimeString(),
            'next_attempt_at' => $next->format('Y-m-d H:i:s'),
            'published_at' => $published?->format('Y-m-d H:i:s'),
        ];
    }
}
