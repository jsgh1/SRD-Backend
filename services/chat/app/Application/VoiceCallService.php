<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\InternalClient;
use Srd\Outbox;

final class VoiceCallService
{
    public function __construct(private InternalClient $identity, private ChatSignal $signal,
        private ChatDeliveryService $delivery) {}

    private function conversation(array $principal, string $id, bool $lock = false): object
    {
        $query = DB::table('conversations')->where('id', $id)->where('organization_id', $principal['organization_id'])
            ->where(fn ($q) => $q->where('user_a_id', $principal['user_id'])->orWhere('user_b_id', $principal['user_id']));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function call(array $principal, string $conversationId, string $callId, bool $lock = false): object
    {
        $this->conversation($principal, $conversationId);
        $query = DB::table('voice_calls')->where('id', $callId)->where('conversation_id', $conversationId)
            ->where('organization_id', $principal['organization_id'])
            ->where(fn ($q) => $q->where('caller_id', $principal['user_id'])->orWhere('callee_id', $principal['user_id']));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function present(object $row): array
    {
        return ['id' => $row->id, 'conversation_id' => $row->conversation_id,
            'caller_id' => $row->caller_id, 'callee_id' => $row->callee_id, 'status' => $row->status,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            'answered_at' => $row->answered_at ? CarbonImmutable::parse($row->answered_at, 'UTC')->toIso8601String() : null,
            'ended_at' => $row->ended_at ? CarbonImmutable::parse($row->ended_at, 'UTC')->toIso8601String() : null];
    }

    private function expireStale(?string $conversationId = null, int $limit = 100): int
    {
        $query = DB::table('voice_calls')->whereIn('status', ['ringing', 'accepted'])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now());
        if ($conversationId !== null) $query->where('conversation_id', $conversationId);
        $rows = $query->orderBy('expires_at')->limit($limit)->lockForUpdate()->get();
        foreach ($rows as $row) $this->finish($row, $row->status === 'ringing' ? 'missed' : 'failed', null);
        return $rows->count();
    }

    public function expireBatch(): int
    {
        return DB::transaction(fn () => $this->expireStale());
    }

    private function finish(object $row, string $status, ?string $actor): void
    {
        DB::table('voice_calls')->where('id', $row->id)->update(['status' => $status,
            'ended_at' => now(), 'expires_at' => null]);
        DB::table('voice_call_signals')->where('call_id', $row->id)->delete();
        $kind = $row->answered_at === null ? 'call_missed' : 'call_ended';
        $duration = $row->answered_at ? max(0, (int) CarbonImmutable::parse($row->answered_at, 'UTC')->diffInSeconds(CarbonImmutable::now('UTC'))) : 0;
        DB::table('messages')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'organization_id' => $row->organization_id,
            'conversation_id' => $row->conversation_id, 'sender_id' => $row->caller_id,
            'client_id' => $row->id, 'body' => '', 'system_kind' => $kind,
            'call_duration_seconds' => $duration, 'created_at' => now(),
        ]);
        DB::table('conversations')->where('id', $row->conversation_id)->update(['updated_at' => now()]);
        if ($status === 'missed') $this->delivery->enqueueCall($row->organization_id, $row->conversation_id,
            $row->id, $row->callee_id, 'chat_missed');
        Outbox::record('chat.call_'.$status, $row->organization_id, $actor, $row->id);
    }

    public function list(array $principal): array
    {
        $ids = DB::table('voice_calls')->where('organization_id', $principal['organization_id'])
            ->where('callee_id', $principal['user_id'])->where('status', 'ringing')
            ->pluck('conversation_id')->unique();
        foreach ($ids as $id) DB::transaction(function () use ($id) { $this->expireStale($id); });
        return ['data' => DB::table('voice_calls')->where('organization_id', $principal['organization_id'])
            ->where('callee_id', $principal['user_id'])->where('status', 'ringing')
            ->orderByDesc('created_at')->limit(10)->get()->map(fn ($row) => $this->present($row))->all()];
    }

    public function start(array $principal, string $conversationId): array
    {
        $conversation = $this->conversation($principal, $conversationId);
        $callee = $conversation->user_a_id === $principal['user_id'] ? $conversation->user_b_id : $conversation->user_a_id;
        $this->identity->call('identity', 'POST', 'chat-contacts/resolve', ['user_id' => $callee],
            ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id']]);
        $row = DB::transaction(function () use ($principal, $conversationId, $callee) {
            $this->conversation($principal, $conversationId, true);
            $this->expireStale($conversationId);
            abort_if(DB::table('voice_calls')->where('conversation_id', $conversationId)
                ->whereIn('status', ['ringing', 'accepted'])->exists(), 409);
            $id = (string) Str::uuid();
            DB::table('voice_calls')->insert(['id' => $id, 'organization_id' => $principal['organization_id'],
                'conversation_id' => $conversationId, 'caller_id' => $principal['user_id'], 'callee_id' => $callee,
                'status' => 'ringing', 'created_at' => now(), 'expires_at' => now()->addSeconds(45)]);
            Outbox::record('chat.call_started', $principal['organization_id'], $principal['user_id'], $id);
            $this->delivery->enqueueCall($principal['organization_id'], $conversationId, $id, $callee, 'chat_call');
            return DB::table('voice_calls')->where('id', $id)->first();
        });
        $this->signal->changed($principal['organization_id'], $row->caller_id, $row->callee_id);
        try {
            $jobId = DB::table('chat_delivery_jobs')->where('message_id', $row->id)
                ->where('kind', 'chat_call')->value('id');
            if ($jobId) $this->delivery->deliverOne($jobId);
        } catch (\Throwable $error) {
            report($error); // The durable job remains available to the scheduler.
        }
        return ['data' => $this->present($row)];
    }

    public function show(array $principal, string $conversationId, string $callId): array
    {
        $row = DB::transaction(function () use ($principal, $conversationId, $callId) {
            $this->expireStale($conversationId);
            $row = $this->call($principal, $conversationId, $callId);
            if ($row->status === 'accepted') {
                DB::table('voice_calls')->where('id', $row->id)->update(['expires_at' => now()->addMinutes(5)]);
            }
            return $row;
        });
        return ['data' => $this->present($row)];
    }

    public function transition(array $principal, string $conversationId, string $callId, string $action): array
    {
        $row = DB::transaction(function () use ($principal, $conversationId, $callId, $action) {
            $this->conversation($principal, $conversationId, true);
            $this->expireStale($conversationId);
            $row = $this->call($principal, $conversationId, $callId, true);
            if ($action === 'accept' || $action === 'reject') {
                abort_unless($row->callee_id === $principal['user_id'], 403);
                abort_unless($row->status === 'ringing', 409);
                if ($action === 'accept') {
                    DB::table('voice_calls')->where('id', $row->id)->update(['status' => 'accepted',
                        'answered_at' => now(), 'expires_at' => now()->addMinutes(5)]);
                    Outbox::record('chat.call_accepted', $row->organization_id, $principal['user_id'], $row->id);
                } else $this->finish($row, 'rejected', $principal['user_id']);
            } else {
                abort_unless(in_array($row->status, ['ringing', 'accepted'], true), 409);
                $this->finish($row, $action === 'fail' ? 'failed' : 'ended', $principal['user_id']);
            }
            return DB::table('voice_calls')->where('id', $row->id)->first();
        });
        $this->signal->changed($principal['organization_id'], $row->caller_id, $row->callee_id);
        return ['data' => $this->present($row)];
    }

    public function signals(array $principal, string $conversationId, string $callId, int $after): array
    {
        $row = $this->call($principal, $conversationId, $callId);
        abort_unless(in_array($row->status, ['ringing', 'accepted'], true), 409);
        return ['data' => DB::table('voice_call_signals')->where('call_id', $callId)
            ->where('sender_id', '!=', $principal['user_id'])->where('sequence', '>', $after)
            ->orderBy('sequence')->limit(100)->get()->map(fn ($item) => [
                'sequence' => $item->sequence, 'kind' => $item->kind, 'payload' => json_decode($item->payload, true),
            ])->all()];
    }

    public function signal(array $principal, string $conversationId, string $callId, string $kind, array $payload): array
    {
        $row = DB::transaction(function () use ($principal, $conversationId, $callId, $kind, $payload) {
            $this->conversation($principal, $conversationId, true);
            $row = $this->call($principal, $conversationId, $callId, true);
            abort_unless(in_array($row->status, ['ringing', 'accepted'], true), 409);
            // The callee may accept as soon as the call appears, before the caller's offer arrives.
            abort_unless(($kind === 'offer' && $row->caller_id === $principal['user_id'] && in_array($row->status, ['ringing', 'accepted'], true))
                || ($kind === 'answer' && $row->callee_id === $principal['user_id'] && $row->status === 'accepted')
                || ($kind === 'candidate')
                || ($kind === 'mute' && in_array($row->status, ['ringing', 'accepted'], true)), 403);
            if (in_array($kind, ['offer', 'answer'], true)) {
                abort_if(DB::table('voice_call_signals')->where('call_id', $callId)->where('kind', $kind)->exists(), 409);
            } else {
                abort_if(DB::table('voice_call_signals')->where('call_id', $callId)->where('sender_id', $principal['user_id'])
                    ->where('kind', $kind)->count() >= ($kind === 'candidate' ? 256 : 120), 429);
            }
            $sequence = DB::table('voice_call_signals')->insertGetId(['call_id' => $callId,
                'sender_id' => $principal['user_id'], 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now()], 'sequence');
            return ['sequence' => $sequence, 'organization_id' => $row->organization_id,
                'caller_id' => $row->caller_id, 'callee_id' => $row->callee_id];
        });
        $this->signal->changed($row['organization_id'], $row['caller_id'], $row['callee_id']);
        return ['data' => ['sequence' => $row['sequence']]];
    }
}
