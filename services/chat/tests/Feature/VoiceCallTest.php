<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class VoiceCallTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private string $org = '11111111-1111-4111-8111-111111111111';
    private string $caller = '22222222-2222-4222-8222-222222222222';
    private string $callee = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response(['data' => ['id' => $this->callee, 'name' => 'Receptor']]));
    }

    private function principal(?string $user = null, ?string $org = null): array
    {
        return ['user_id' => $user ?? $this->caller, 'organization_id' => $org ?? $this->org,
            'role' => 'viewer', 'name' => 'Prueba'];
    }

    private function begin(): array
    {
        $conversation = $this->internal('POST', 'conversations', ['user_id' => $this->callee], $this->principal())->assertOk()->json('data.id');
        $call = $this->internal('POST', 'conversations/'.$conversation.'/calls', [], $this->principal())->assertOk()->json('data');
        return [$conversation, $call['id']];
    }

    public function test_only_participants_can_signal_and_terminal_calls_delete_sdp(): void
    {
        [$conversation, $call] = $this->begin();
        $base = 'conversations/'.$conversation.'/calls/'.$call;
        $this->assertDatabaseHas('chat_delivery_jobs', ['message_id' => $call, 'kind' => 'chat_call', 'user_id' => $this->callee]);
        $this->assertNotNull(DB::table('chat_delivery_jobs')->where('message_id', $call)
            ->where('kind', 'chat_call')->value('delivered_at'));
        $this->internal('GET', $base, [], $this->principal($this->callee))->assertOk()->assertJsonPath('data.status', 'ringing');
        $this->internal('GET', $base, [], $this->principal(null, '99999999-9999-4999-8999-999999999999'))->assertNotFound();
        $this->internal('GET', $base, [], $this->principal('44444444-4444-4444-8444-444444444444'))->assertNotFound();
        $this->internal('POST', $base.'/signal', ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'stolen']],
            $this->principal('44444444-4444-4444-8444-444444444444'))->assertNotFound();
        $this->internal('POST', $base.'/transition', ['action' => 'accept'], $this->principal())->assertForbidden();
        $this->internal('POST', $base.'/signal', ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'private-sdp']], $this->principal())
            ->assertOk();
        $this->internal('GET', $base.'/signals', [], $this->principal($this->callee))->assertOk()
            ->assertJsonPath('data.0.payload.sdp', 'private-sdp');
        $this->internal('GET', $base.'/signals', [], $this->principal())->assertOk()->assertJsonCount(0, 'data');
        $this->internal('POST', $base.'/signal', ['kind' => 'candidate', 'payload' => [
            'candidate' => 'candidate:1 1 udp 1 127.0.0.1 9999 typ host', 'sdpMid' => '0', 'sdpMLineIndex' => 0,
            'extra' => 'must not persist',
        ]], $this->principal())->assertOk();
        $this->assertStringNotContainsString('must not persist', DB::table('voice_call_signals')->where('kind', 'candidate')->value('payload'));
        $this->internal('POST', $base.'/signal', ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'different']], $this->principal())->assertConflict();
        $this->internal('POST', $base.'/transition', ['action' => 'accept'], $this->principal($this->callee))
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->internal('POST', $base.'/signal', ['kind' => 'mute', 'payload' => ['muted' => true]], $this->principal())
            ->assertOk();
        $this->internal('GET', $base.'/signals', ['after' => 2], $this->principal($this->callee))
            ->assertJsonPath('data.0.kind', 'mute')->assertJsonPath('data.0.payload.muted', true);
        $this->internal('POST', $base.'/signal', ['kind' => 'answer', 'payload' => ['type' => 'answer', 'sdp' => 'answer-sdp']], $this->principal($this->callee))->assertOk();
        $this->internal('POST', $base.'/transition', ['action' => 'end'], $this->principal())
            ->assertOk()->assertJsonPath('data.status', 'ended');
        $this->assertDatabaseCount('voice_call_signals', 0);
        $this->internal('GET', $base.'/signals', [], $this->principal())->assertConflict();
        $this->assertDatabaseHas('outbox_events', ['action' => 'chat.call_ended', 'resource_id' => $call]);
        $this->internal('GET', "conversations/$conversation/messages", [], $this->principal($this->callee))
            ->assertJsonPath('data.items.0.system_kind', 'call_ended');
        $this->assertSame(1, DB::table('voice_calls')->where('id', $call)->count());
    }

    public function test_microphone_state_can_be_shared_while_ringing(): void
    {
        [$conversation, $call] = $this->begin();
        $base = 'conversations/'.$conversation.'/calls/'.$call;
        $this->internal('POST', $base.'/signal', ['kind' => 'mute', 'payload' => ['muted' => true]], $this->principal())
            ->assertOk();
        $this->internal('GET', $base.'/signals', [], $this->principal($this->callee))
            ->assertJsonPath('data.0.kind', 'mute')->assertJsonPath('data.0.payload.muted', true);
    }

    public function test_rejected_and_missed_calls_have_outcomes_and_new_call_can_start(): void
    {
        [$conversation, $call] = $this->begin();
        $base = 'conversations/'.$conversation.'/calls/'.$call;
        $this->internal('POST', 'conversations/'.$conversation.'/calls', [], $this->principal())->assertConflict();
        $this->internal('POST', $base.'/transition', ['action' => 'reject'], $this->principal($this->callee))
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $second = $this->internal('POST', 'conversations/'.$conversation.'/calls', [], $this->principal())->assertOk()->json('data.id');
        DB::table('voice_calls')->where('id', $second)->update(['expires_at' => now()->subSecond()]);
        $this->internal('GET', 'voice-calls', [], $this->principal($this->callee))->assertOk()->assertJsonCount(0, 'data');
        $this->internal('GET', 'conversations/'.$conversation.'/calls/'.$second, [], $this->principal())
            ->assertOk()->assertJsonPath('data.status', 'missed');
        $this->assertDatabaseHas('outbox_events', ['action' => 'chat.call_missed', 'resource_id' => $second]);
        $this->internal('GET', "conversations/$conversation/messages", [], $this->principal($this->callee))
            ->assertJsonPath('data.items.1.system_kind', 'call_missed');
        $this->assertDatabaseHas('chat_delivery_jobs', ['message_id' => $second, 'kind' => 'chat_missed']);
    }

    public function test_abandoned_accepted_call_expires_and_removes_private_signals(): void
    {
        [$conversation, $call] = $this->begin();
        $base = 'conversations/'.$conversation.'/calls/'.$call;
        $this->internal('POST', $base.'/signal', ['kind' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => 'temporary-sdp']], $this->principal())->assertOk();
        $this->internal('POST', $base.'/transition', ['action' => 'accept'], $this->principal($this->callee))
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        DB::table('voice_calls')->where('id', $call)->update(['expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(\App\Application\VoiceCallService::class)->expireBatch());
        $this->assertSame(0, app(\App\Application\VoiceCallService::class)->expireBatch());
        $this->internal('GET', $base, [], $this->principal())->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('voice_call_signals', 0);
        $this->assertDatabaseHas('outbox_events', ['action' => 'chat.call_failed', 'resource_id' => $call, 'actor_id' => null]);
        $this->internal('POST', 'conversations/'.$conversation.'/calls', [], $this->principal())->assertOk();
    }

    public function test_callee_can_accept_before_caller_posts_offer(): void
    {
        [$conversation, $call] = $this->begin();
        $base = 'conversations/'.$conversation.'/calls/'.$call;
        $this->internal('POST', $base.'/transition', ['action' => 'accept'], $this->principal($this->callee))
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $sdp = "v=0\r\na=ssrc:1 msid:track stream\r\n";
        $this->internal('POST', $base.'/signal', ['kind' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => $sdp]], $this->principal())->assertOk();
        $this->internal('GET', $base.'/signals', [], $this->principal($this->callee))
            ->assertOk()->assertJsonPath('data.0.payload.sdp', $sdp);
        $this->internal('POST', $base.'/signal', ['kind' => 'answer',
            'payload' => ['type' => 'answer', 'sdp' => 'answer']], $this->principal($this->callee))->assertOk();
        $this->internal('POST', $base.'/signal', ['kind' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => 'duplicate']], $this->principal())->assertConflict();
    }
}
