<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\InternalClient;
use Srd\Outbox;
use Ramsey\Uuid\Uuid;

final class ChatService
{
    public function __construct(private InternalClient $identity, private ChatDeliveryService $delivery,
        private ChatSignal $signal) {}

    private function participant(array $principal, string $id, bool $lock = false): object
    {
        $query = DB::table('conversations')->where('id', $id)
            ->where('organization_id', $principal['organization_id'])
            ->where(fn ($q) => $q->where('user_a_id', $principal['user_id'])
                ->orWhere('user_b_id', $principal['user_id']));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function present(object $row, string $userId, ?string $activity = null): array
    {
        $isA = $row->user_a_id === $userId;
        return ['id' => $row->id, 'contact' => ['id' => $isA ? $row->user_b_id : $row->user_a_id,
            'name' => $isA ? $row->user_b_name : $row->user_a_name],
            'contact_activity' => $activity,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            'updated_at' => CarbonImmutable::parse($row->updated_at, 'UTC')->toIso8601String()];
    }

    private function message(object $row): array
    {
        return ['id' => $row->id, 'sequence' => (int) $row->sequence,
            'sender_id' => $row->sender_id, 'body' => $row->body,
            'reply_to_id' => $row->reply_to_id ?? null,
            'system_kind' => $row->system_kind ?? null,
            'call_duration_seconds' => $row->call_duration_seconds ?? null,
            'pinned' => ! empty($row->pinned_at),
            'attachment' => $row->attachment_id ? ['id' => $row->attachment_id, 'name' => $row->attachment_name,
                'mime' => $row->attachment_mime, 'bytes' => (int) $row->attachment_bytes] : null,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            'delivered_at' => $row->delivered_at ? CarbonImmutable::parse($row->delivered_at, 'UTC')->toIso8601String() : null,
            'read_at' => $row->read_at ? CarbonImmutable::parse($row->read_at, 'UTC')->toIso8601String() : null];
    }

    private function visibleMessages(array $principal, string $conversationId)
    {
        $cleared = (int) (DB::table('conversation_user_states')
            ->where('conversation_id', $conversationId)->where('user_id', $principal['user_id'])
            ->value('cleared_sequence') ?? 0);
        return DB::table('messages')->where('organization_id', $principal['organization_id'])
            ->where('conversation_id', $conversationId)->where('sequence', '>', $cleared)
            ->whereNull('deleted_for_all_at')
            ->whereNotIn('id', DB::table('message_user_states')->select('message_id')
                ->where('organization_id', $principal['organization_id'])
                ->where('conversation_id', $conversationId)->where('user_id', $principal['user_id'])
                ->whereNotNull('hidden_at'));
    }

    public function search(array $principal, string $conversationId, string $term): array
    {
        $this->participant($principal, $conversationId);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term));
        $rows = $this->visibleMessages($principal, $conversationId)
            ->where(fn ($q) => $q->whereRaw("body LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->orWhereRaw("attachment_name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']))
            ->orderByDesc('sequence')->limit(50)->get();
        return ['data' => ['items' => $rows->map(fn ($row) => $this->message($row))->all()]];
    }

    public function pinned(array $principal, string $conversationId): array
    {
        $this->participant($principal, $conversationId);
        $ids = DB::table('message_user_states')->where('organization_id', $principal['organization_id'])
            ->where('conversation_id', $conversationId)->where('user_id', $principal['user_id'])
            ->whereNotNull('pinned_at')->whereNull('hidden_at')->pluck('message_id');
        $rows = $this->visibleMessages($principal, $conversationId)->whereIn('id', $ids)
            ->orderByDesc('sequence')->limit(25)->get();
        return ['data' => ['items' => $rows->map(function ($row) {
            $row->pinned_at = true;
            return $this->message($row);
        })->all()]];
    }

    public function pin(array $principal, string $conversationId, string $messageId): array
    {
        $conversation = $this->participant($principal, $conversationId);
        abort_unless($this->visibleMessages($principal, $conversationId)->where('id', $messageId)->exists(), 404);
        $key = ['message_id' => $messageId, 'user_id' => $principal['user_id']];
        $pinned = DB::transaction(function () use ($principal, $conversationId, $key, $messageId) {
            $state = DB::table('message_user_states')->where($key)->lockForUpdate()->first();
            $pinned = ! $state || $state->pinned_at === null;
            DB::table('message_user_states')->updateOrInsert($key, [
                'organization_id' => $principal['organization_id'], 'conversation_id' => $conversationId,
                'pinned_at' => $pinned ? now() : null, 'hidden_at' => null,
            ]);
            Outbox::record($pinned ? 'chat.message_pinned' : 'chat.message_unpinned',
                $principal['organization_id'], $principal['user_id'], $messageId);
            return $pinned;
        });
        $this->signal->changed($principal['organization_id'], $conversation->user_a_id, $conversation->user_b_id);
        return ['data' => ['pinned' => $pinned]];
    }

    public function hide(array $principal, string $conversationId, string $messageId): array
    {
        $conversation = $this->participant($principal, $conversationId);
        $message = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)
            ->where('organization_id', $principal['organization_id'])->first();
        abort_unless($message, 404);
        if ($message->sender_id === $principal['user_id'] && $message->system_kind === null) {
            DB::transaction(function () use ($principal, $conversationId, $messageId) {
                $row = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)
                    ->where('organization_id', $principal['organization_id'])->lockForUpdate()->first();
                abort_unless($row, 404);
                if ($row->deleted_for_all_at !== null) return;
                DB::table('messages')->where('id', $messageId)->update(['deleted_for_all_at' => now()]);
                DB::table('chat_delivery_jobs')->where('message_id', $messageId)->whereNull('delivered_at')->delete();
                Outbox::record('chat.message_deleted_for_all', $principal['organization_id'], $principal['user_id'], $messageId);
            });
            $this->signal->changed($principal['organization_id'], $conversation->user_a_id, $conversation->user_b_id);
            return ['data' => []];
        }
        DB::transaction(function () use ($principal, $conversationId, $messageId) {
            $key = ['message_id' => $messageId, 'user_id' => $principal['user_id']];
            $state = DB::table('message_user_states')->where($key)->lockForUpdate()->first();
            if ($state && $state->hidden_at !== null) return;
            DB::table('message_user_states')->updateOrInsert($key, [
                'organization_id' => $principal['organization_id'], 'conversation_id' => $conversationId,
                'pinned_at' => null, 'hidden_at' => now(),
            ]);
            Outbox::record('chat.message_hidden', $principal['organization_id'], $principal['user_id'], $messageId);
        });
        return ['data' => []];
    }

    public function clear(array $principal, string $conversationId): array
    {
        DB::transaction(function () use ($principal, $conversationId) {
            $this->participant($principal, $conversationId, true);
            $latest = (int) (DB::table('messages')->where('conversation_id', $conversationId)
                ->where('organization_id', $principal['organization_id'])->max('sequence') ?? 0);
            $key = ['conversation_id' => $conversationId, 'user_id' => $principal['user_id']];
            $previous = DB::table('conversation_user_states')->where($key)->lockForUpdate()->value('cleared_sequence');
            if ($latest <= (int) ($previous ?? 0)) return;
            DB::table('conversation_user_states')->updateOrInsert($key, [
                'organization_id' => $principal['organization_id'], 'cleared_sequence' => $latest,
            ]);
            Outbox::record('chat.conversation_cleared', $principal['organization_id'], $principal['user_id'], $conversationId);
        });
        return ['data' => []];
    }

    public function list(array $principal, int $page): array
    {
        $query = DB::table('conversations')->where('organization_id', $principal['organization_id'])
            ->where(fn ($q) => $q->where('user_a_id', $principal['user_id'])
                ->orWhere('user_b_id', $principal['user_id']));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, 25)->get();
        $ids = $rows->pluck('id')->all();
        $activities = $ids ? DB::table('conversation_activities')->whereIn('conversation_id', $ids)
            ->where('user_id', '!=', $principal['user_id'])->where('expires_at', '>', now())
            ->pluck('kind', 'conversation_id') : collect();
        $calling = $ids ? DB::table('voice_calls')->whereIn('conversation_id', $ids)
            ->where('status', 'ringing')->pluck('conversation_id')->flip() : collect();
        return ['data' => ['items' => $rows->map(fn ($row) => $this->present($row, $principal['user_id'],
            $calling->has($row->id) ? 'calling' : ($activities[$row->id] ?? null)))->all(),
            'page' => $page, 'page_size' => 25, 'total' => $total]];
    }

    public function show(array $principal, string $id): array
    {
        $row = $this->participant($principal, $id);
        $activity = DB::table('conversation_activities')->where('conversation_id', $id)
            ->where('user_id', '!=', $principal['user_id'])->where('expires_at', '>', now())->value('kind');
        if (DB::table('voice_calls')->where('conversation_id', $id)->where('status', 'ringing')->exists()) $activity = 'calling';
        return ['data' => $this->present($row, $principal['user_id'], $activity)];
    }

    public function activity(array $principal, string $id, string $kind): array
    {
        $row = $this->participant($principal, $id);
        $key = ['conversation_id' => $id, 'user_id' => $principal['user_id']];
        if ($kind === 'idle') DB::table('conversation_activities')->where($key)->delete();
        else DB::table('conversation_activities')->updateOrInsert($key, [
            'organization_id' => $principal['organization_id'], 'kind' => $kind,
            'expires_at' => now()->addSeconds(6),
        ]);
        $this->signal->changed($principal['organization_id'], $row->user_a_id, $row->user_b_id);
        return ['data' => ['kind' => $kind]];
    }

    public function start(array $principal, string $contactId): array
    {
        $contact = $this->identity->call('identity', 'POST', 'chat-contacts/resolve',
            ['user_id' => $contactId], ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id']]);
        $userId = $principal['user_id'];
        $a = strcmp($userId, $contactId) < 0 ? $userId : $contactId;
        $b = $a === $userId ? $contactId : $userId;
        $name = trim((string) ($principal['name'] ?? ''));
        abort_unless($name !== '', 403);
        $row = DB::transaction(function () use ($principal, $contact, $userId, $a, $b, $name) {
            $org = $principal['organization_id'];
            $id = (string) Str::uuid();
            $created = DB::table('conversations')->insertOrIgnore([
                'id' => $id, 'organization_id' => $org, 'user_a_id' => $a, 'user_b_id' => $b,
                'user_a_name' => $a === $userId ? $name : $contact['name'],
                'user_b_name' => $b === $userId ? $name : $contact['name'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($created) Outbox::record('chat.conversation_started', $org, $userId, $id);
            return DB::table('conversations')->where('organization_id', $org)
                ->where('user_a_id', $a)->where('user_b_id', $b)->first();
        });
        $this->signal->changed($principal['organization_id'], $row->user_a_id, $row->user_b_id);
        return ['data' => $this->present($row, $userId)];
    }

    public function messages(array $principal, string $id, ?int $before, ?int $after, array $receiptIds, array $visibleIds = []): array
    {
        $this->participant($principal, $id);
        $query = $this->visibleMessages($principal, $id);
        if ($before !== null) $query->where('sequence', '<', $before);
        if ($after !== null) $query->where('sequence', '>', $after);
        $items = $query->orderBy('sequence', $after !== null ? 'asc' : 'desc')->limit(25)
            ->get(['sequence', 'id', 'sender_id', 'body', 'reply_to_id', 'attachment_id', 'attachment_name', 'attachment_mime', 'system_kind', 'call_duration_seconds',
                'attachment_bytes', 'created_at', 'delivered_at', 'read_at']);
        $pins = DB::table('message_user_states')->where('organization_id', $principal['organization_id'])
            ->where('conversation_id', $id)->where('user_id', $principal['user_id'])
            ->whereNotNull('pinned_at')->whereIn('message_id', $items->pluck('id'))
            ->pluck('pinned_at', 'message_id');
        foreach ($items as $item) $item->pinned_at = $pins[$item->id] ?? null;
        $receiptRows = collect();
        if ($receiptIds) {
            $receiptRows = DB::table('messages')->where('organization_id', $principal['organization_id'])
                ->where('conversation_id', $id)->where('sender_id', $principal['user_id'])
                ->whereIn('id', $receiptIds)->get(['id', 'delivered_at', 'read_at'])->keyBy('id');
            if ($receiptRows->count() !== count($receiptIds)) {
                throw ValidationException::withMessages(['receipt_ids' => 'Selecciona únicamente mensajes enviados por ti en esta conversación.']);
            }
        }
        $stillVisible = $visibleIds ? $this->visibleMessages($principal, $id)->whereIn('id', $visibleIds)->pluck('id')->all() : [];
        return ['data' => [
            'items' => ($after === null ? $items->reverse()->values() : $items)->map(fn ($row) => $this->message($row))->all(),
            'next_before' => $after === null && $items->count() === 25 ? $items->last()->sequence : null,
            'next_after' => $after !== null && $items->count() === 25 ? $items->last()->sequence : null,
            'removed_ids' => array_values(array_diff($visibleIds, $stillVisible)),
            'receipts' => array_map(fn ($messageId) => [
                'id' => $messageId,
                'delivered_at' => $receiptRows[$messageId]->delivered_at ? CarbonImmutable::parse($receiptRows[$messageId]->delivered_at, 'UTC')->toIso8601String() : null,
                'read_at' => $receiptRows[$messageId]->read_at ? CarbonImmutable::parse($receiptRows[$messageId]->read_at, 'UTC')->toIso8601String() : null,
            ], $receiptIds),
        ]];
    }

    public function send(array $principal, string $id, array $data): array
    {
        $attachment = $data['attachment'] ?? null;
        $sourceHash = null;
        if ($attachment !== null) {
            $source = base64_decode($attachment['content'], true);
            if (!is_string($source) || $source === '' || strlen($source) > 10 * 1024 * 1024
                || base64_encode($source) !== $attachment['content']) {
                throw ValidationException::withMessages(['attachment' => 'Usa un archivo válido de hasta 10 MB.']);
            }
            $sourceHash = hash('sha256', $source);
            unset($source);
        }
        if (trim($data['body']) === '' && $attachment === null) {
            throw ValidationException::withMessages(['body' => 'Escribe un mensaje.']);
        }
        $conversation = $this->participant($principal, $id);
        $replyToId = $data['reply_to_id'] ?? null;
        $existing = DB::table('messages')->where('organization_id', $principal['organization_id'])
            ->where('conversation_id', $id)->where('sender_id', $principal['user_id'])
            ->where('client_id', $data['client_id'])->first();
        if ($existing) {
            abort_if($existing->deleted_for_all_at !== null, 409);
            abort_unless($existing->body === $data['body'] && $existing->attachment_source_sha256 === $sourceHash
                && $existing->reply_to_id === $replyToId, 409);
            return ['data' => $this->message($existing)];
        }
        if ($replyToId !== null) {
            abort_unless($this->visibleMessages($principal, $id)->where('id', $replyToId)->exists(), 404);
        }
        $contactId = $conversation->user_a_id === $principal['user_id'] ? $conversation->user_b_id : $conversation->user_a_id;
        $this->identity->call('identity', 'POST', 'chat-contacts/resolve', ['user_id' => $contactId],
            ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id']]);
        $uploaded = null;
        if ($attachment !== null) {
            $attachmentId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL,
                implode(':', [$principal['organization_id'], $id, $principal['user_id'], $data['client_id']]));
            $uploaded = $this->identity->call('files', 'POST', 'chat-attachments', [
                'id' => $attachmentId, 'conversation_id' => $id, 'name' => $attachment['name'],
                'content' => $attachment['content'],
            ], ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id']], 75);
        }
        $row = DB::transaction(function () use ($principal, $id, $data, $contactId, $uploaded, $sourceHash, $replyToId) {
            $this->participant($principal, $id, true);
            $org = $principal['organization_id'];
            $user = $principal['user_id'];
            $existing = DB::table('messages')->where('conversation_id', $id)
                ->where('sender_id', $user)->where('client_id', $data['client_id'])->first();
            if ($existing) {
                abort_if($existing->deleted_for_all_at !== null, 409);
                abort_unless($existing->body === $data['body'] && $existing->attachment_source_sha256 === $sourceHash
                    && $existing->reply_to_id === $replyToId, 409);
                return $existing;
            }
            $messageId = (string) Str::uuid();
            $sequence = DB::table('messages')->insertGetId(['id' => $messageId,
                'organization_id' => $org, 'conversation_id' => $id, 'sender_id' => $user,
                'client_id' => $data['client_id'], 'body' => $data['body'], 'reply_to_id' => $replyToId,
                'attachment_id' => $uploaded['id'] ?? null, 'attachment_name' => $uploaded['name'] ?? null,
                'attachment_mime' => $uploaded['mime'] ?? null, 'attachment_bytes' => $uploaded['bytes'] ?? null,
                'attachment_source_sha256' => $sourceHash, 'created_at' => now()], 'sequence');
            DB::table('conversations')->where('id', $id)->where('organization_id', $org)->update(['updated_at' => now()]);
            Outbox::record('chat.message_sent', $org, $user, $messageId);
            $this->delivery->enqueue($org, $id, $messageId, $contactId);
            return DB::table('messages')->where('sequence', $sequence)->first();
        });
        $this->signal->changed($principal['organization_id'], $conversation->user_a_id, $conversation->user_b_id);
        return ['data' => $this->message($row)];
    }

    public function attachment(array $principal, string $conversationId, string $messageId): array
    {
        $this->participant($principal, $conversationId);
        $message = $this->visibleMessages($principal, $conversationId)->where('id', $messageId)
            ->first(['attachment_id']);
        abort_unless($message && $message->attachment_id, 404);
        return ['data' => $this->identity->call('files', 'GET', 'chat-attachments/'.$message->attachment_id,
            ['conversation_id' => $conversationId],
            ['organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id']])];
    }

    public function confirmReceipt(array $principal, string $id, string $kind, array $messageIds): array
    {
        $conversation = $this->participant($principal, $id);
        $result = DB::transaction(function () use ($principal, $id, $kind, $messageIds) {
            $this->participant($principal, $id, true);
            $query = DB::table('messages')->where('organization_id', $principal['organization_id'])
                ->where('conversation_id', $id)->whereIn('id', $messageIds)
                ->whereNull('deleted_for_all_at')
                ->where('sender_id', '!=', $principal['user_id']);
            if ((clone $query)->count() !== count($messageIds)) {
                throw ValidationException::withMessages(['message_ids' => 'Selecciona únicamente mensajes recibidos de esta conversación.']);
            }
            $at = now();
            if ($kind === 'delivered') {
                $changed = (clone $query)->whereNull('delivered_at')->update(['delivered_at' => $at]);
            } else {
                (clone $query)->whereNull('delivered_at')->update(['delivered_at' => $at]);
                $changed = (clone $query)->whereNull('read_at')->update(['read_at' => $at]);
            }
            if ($changed) {
                Outbox::record($kind === 'read' ? 'chat.messages_read' : 'chat.messages_delivered',
                    $principal['organization_id'], $principal['user_id'], $id);
            }
            $rows = (clone $query)->get(['id', 'delivered_at', 'read_at'])->keyBy('id');
            return ['data' => ['items' => array_map(fn ($messageId) => [
                'id' => $messageId,
                'delivered_at' => $rows[$messageId]->delivered_at ? CarbonImmutable::parse($rows[$messageId]->delivered_at, 'UTC')->toIso8601String() : null,
                'read_at' => $rows[$messageId]->read_at ? CarbonImmutable::parse($rows[$messageId]->read_at, 'UTC')->toIso8601String() : null,
            ], $messageIds)]];
        });
        $this->signal->changed($principal['organization_id'], $conversation->user_a_id, $conversation->user_b_id);
        return $result;
    }
}
