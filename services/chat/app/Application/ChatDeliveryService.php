<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\DependencyFailure;
use Srd\InternalClient;

final class ChatDeliveryService
{
    public function __construct(private InternalClient $client) {}

    public function enqueue(string $organizationId, string $conversationId, string $messageId, string $recipientId): void
    {
        DB::table('chat_delivery_jobs')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'organization_id' => $organizationId,
            'conversation_id' => $conversationId, 'message_id' => $messageId, 'user_id' => $recipientId,
            'kind' => 'chat_message',
            'delivery_key' => hash('sha256', implode('|', ['chat_message', $organizationId, $messageId, $recipientId])),
            'next_attempt_at' => now(), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function enqueueCall(string $organizationId, string $conversationId, string $callId, string $recipientId, string $kind): void
    {
        if (!in_array($kind, ['chat_call', 'chat_missed'], true)) throw new \InvalidArgumentException('Tipo de aviso de llamada inválido.');
        DB::table('chat_delivery_jobs')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'organization_id' => $organizationId,
            'conversation_id' => $conversationId, 'message_id' => $callId, 'user_id' => $recipientId,
            'kind' => $kind,
            'delivery_key' => hash('sha256', implode('|', [$kind, $organizationId, $callId, $recipientId])),
            'next_attempt_at' => now(), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function deliverOne(string $id): string
    {
        return DB::transaction(function () use ($id) {
            $job = DB::table('chat_delivery_jobs')->where('id', $id)->lockForUpdate()->first();
            if (!$job || $job->delivered_at !== null || $job->attempts >= 8
                || CarbonImmutable::parse($job->next_attempt_at, 'UTC')->isFuture()) return 'skipped';
            $message = $job->kind === 'chat_message' ? DB::table('messages')->where('id', $job->message_id)
                ->where('organization_id', $job->organization_id)->where('conversation_id', $job->conversation_id)->first() : null;
            $call = $job->kind !== 'chat_message' ? DB::table('voice_calls')->where('id', $job->message_id)
                ->where('organization_id', $job->organization_id)->where('conversation_id', $job->conversation_id)->first() : null;
            $conversation = DB::table('conversations')->where('id', $job->conversation_id)
                ->where('organization_id', $job->organization_id)->first();
            $senderId = $message?->sender_id ?? $call?->caller_id;
            $valid = $job->kind === 'chat_message' ? $message && $message->deleted_for_all_at === null
                : $call && $call->callee_id === $job->user_id && ($job->kind === 'chat_call' ? $call->status === 'ringing' : $call->status === 'missed');
            if (!$valid || !$conversation || !in_array($job->user_id, [$conversation->user_a_id, $conversation->user_b_id], true)
                || $senderId === $job->user_id) {
                DB::table('chat_delivery_jobs')->where('id', $id)->delete();
                return 'discarded';
            }
            try {
                $this->client->call('identity', 'POST', 'chat-contacts/resolve', ['user_id' => $job->user_id],
                    ['organization_id' => $job->organization_id, 'user_id' => $senderId]);
            } catch (ValidationException) {
                DB::table('chat_delivery_jobs')->where('id', $id)->delete();
                return 'discarded';
            } catch (\Throwable) {
                return $this->retry($job);
            }
            try {
                $result = $this->client->call('notifications', 'POST', 'deliveries', [
                    'organization_id' => $job->organization_id, 'conversation_id' => $job->conversation_id,
                    'user_id' => $job->user_id, 'kind' => $job->kind, 'delivery_key' => $job->delivery_key,
                    'sender_id' => $senderId,
                    'sender_name' => $senderId === $conversation->user_a_id ? $conversation->user_a_name : $conversation->user_b_name,
                    'message_id' => $job->kind === 'chat_message' ? $job->message_id : null,
                ], ['organization_id' => $job->organization_id]);
                if (empty($result['id'])) throw new DependencyFailure;
            } catch (\Throwable) {
                return $this->retry($job);
            }
            DB::table('chat_delivery_jobs')->where('id', $id)->update(['delivered_at' => now(), 'updated_at' => now()]);
            return 'delivered';
        });
    }

    private function retry(object $job): string
    {
        $attempt = (int) $job->attempts + 1;
        DB::table('chat_delivery_jobs')->where('id', $job->id)->update([
            'attempts' => $attempt, 'next_attempt_at' => now()->addMinutes(min(60, 2 ** ($attempt - 1))),
            'updated_at' => now(),
        ]);
        return $attempt === 8 ? 'exhausted' : 'retry';
    }

    public function deliverBatch(): array
    {
        $counts = ['delivered' => 0, 'retry' => 0, 'exhausted' => 0, 'discarded' => 0, 'skipped' => 0];
        $ids = DB::table('chat_delivery_jobs')->whereNull('delivered_at')->where('attempts', '<', 8)
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) $counts[$this->deliverOne($id)]++;
        return $counts;
    }
}
