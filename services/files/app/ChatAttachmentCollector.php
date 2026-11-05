<?php

namespace SrdFiles;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Srd\InternalClient;
use Srd\Outbox;

final class ChatAttachmentCollector
{
    private const GRACE_HOURS = 24;

    public function __construct(private InternalClient $chat) {}

    public function collect(int $limit = 25): array
    {
        $cutoff = now()->subHours(self::GRACE_HOURS);
        $candidates = DB::table('chat_attachments')->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')->limit(max(1, min($limit, 100)))
            ->get(['id', 'organization_id', 'sender_id', 'created_at']);
        $removed = 0;
        $deferred = 0;
        foreach ($candidates as $candidate) {
            try {
                $reference = $this->chat->call('chat', 'GET', 'chat-attachment-references/'.$candidate->id,
                    [], ['organization_id' => $candidate->organization_id, 'user_id' => $candidate->sender_id], 5);
            } catch (\Throwable) {
                // An unavailable Chat service can never authorize deletion.
                $deferred++;
                continue;
            }
            if (!array_key_exists('referenced', $reference) || !is_bool($reference['referenced'])) {
                $deferred++;
                continue;
            }
            if ($reference['referenced']) continue;

            $removed += DB::transaction(function () use ($candidate, $cutoff): int {
                $quota = DB::table('file_quotas')->where('organization_id', $candidate->organization_id)
                    ->lockForUpdate()->first();
                $row = DB::table('chat_attachments')->where('id', $candidate->id)
                    ->where('organization_id', $candidate->organization_id)->lockForUpdate()->first();
                // A repeat upload renews created_at under the same quota lock.
                if (!$row || !$quota || $row->created_at !== $candidate->created_at
                    || Carbon::parse($row->created_at)->greaterThan($cutoff)
                    || (int) $quota->reserved_bytes < (int) $row->bytes) return 0;
                DB::table('chat_attachments')->where('id', $row->id)->delete();
                DB::table('file_quotas')->where('organization_id', $candidate->organization_id)
                    ->decrement('reserved_bytes', (int) $row->bytes);
                Outbox::record('chat.attachment_collected', $candidate->organization_id, $row->sender_id, $row->id);
                return 1;
            });
        }
        return ['removed' => $removed, 'deferred' => $deferred];
    }
}
