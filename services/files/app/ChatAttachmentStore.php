<?php

namespace SrdFiles;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;

final class ChatAttachmentStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private ImageGate $images, private PdfGate $pdfs, private OfficeDocumentGate $office,
        private AudioGate $audio, private Mp3Gate $mp3, private int $quota) {}

    public function put(array $p, array $d): array
    {
        $bytes = base64_decode($d['content'], true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES || base64_encode($bytes) !== $d['content']) {
            throw ValidationException::withMessages(['content' => 'Usa un archivo válido de hasta 10 MB.']);
        }
        $name = trim($d['name']);
        if ($name === '' || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) {
            throw ValidationException::withMessages(['name' => 'El nombre no puede contener rutas ni caracteres de control.']);
        }
        $sourceHash = hash('sha256', $bytes);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $image = $this->images->prepare($name, $bytes);
                $bytes = $image['content'];
                $name = pathinfo($name, PATHINFO_FILENAME).'.png';
                $checked = ['mime' => $image['mime'], 'sha256' => $image['sha256']];
            } elseif ($extension === 'pdf') {
                $checked = $this->pdfs->inspect($name, $bytes);
            } elseif (in_array($extension, ['docx', 'xlsx'], true)) {
                $checked = $this->office->inspect($name, $bytes);
            } elseif ($extension === 'wav') {
                $checked = $this->audio->inspect($name, $bytes);
            } elseif ($extension === 'mp3') {
                $mp3 = $this->mp3->prepare($name, $bytes);
                $bytes = $mp3['content'];
                $checked = ['mime' => $mp3['mime'], 'sha256' => $mp3['sha256']];
            } else {
                throw ValidationException::withMessages(['name' => 'Adjunta una imagen, PDF, DOCX, XLSX, WAV o MP3.']);
            }
        } catch (ImageRejected) {
            throw ValidationException::withMessages(['content' => 'La imagen o el análisis antimalware rechazó el archivo.']);
        }
        return DB::transaction(function () use ($p, $d, $bytes, $name, $checked, $sourceHash) {
            $org = $p['organization_id'];
            DB::table('file_quotas')->upsert([['organization_id' => $org, 'reserved_bytes' => 0]], ['organization_id'], ['organization_id']);
            $quota = DB::table('file_quotas')->where('organization_id', $org)->lockForUpdate()->first();
            $existing = DB::table('chat_attachments')->where('id', $d['id'])->first();
            if ($existing) {
                abort_unless($existing->organization_id === $org && $existing->conversation_id === $d['conversation_id']
                    && $existing->sender_id === $p['user_id'] && $existing->source_sha256 === $sourceHash
                    && $existing->name === $name, 409);
                // A retry may race the collector. Renew its grace period under the quota lock.
                DB::table('chat_attachments')->where('id', $d['id'])->update(['created_at' => now()]);
                return $this->metadata($existing);
            }
            if ((int) $quota->reserved_bytes + strlen($bytes) > $this->quota) abort(507);
            DB::table('chat_attachments')->insert([
                'id' => $d['id'], 'organization_id' => $org, 'conversation_id' => $d['conversation_id'],
                'sender_id' => $p['user_id'], 'name' => $name, 'mime' => $checked['mime'],
                'bytes' => strlen($bytes), 'source_sha256' => $sourceHash, 'sha256' => $checked['sha256'],
                'content_base64' => base64_encode($bytes), 'created_at' => now(),
            ]);
            DB::table('file_quotas')->where('organization_id', $org)->increment('reserved_bytes', strlen($bytes));
            Outbox::record('chat.attachment_stored', $org, $p['user_id'], $d['id']);
            return $this->metadata(DB::table('chat_attachments')->where('id', $d['id'])->first());
        });
    }

    public function read(array $p, string $id, string $conversationId): array
    {
        $row = DB::table('chat_attachments')->where('id', $id)->where('organization_id', $p['organization_id'])
            ->where('conversation_id', $conversationId)->first();
        abort_unless($row, 404);
        $content = base64_decode($row->content_base64, true);
        abort_unless(is_string($content) && strlen($content) === (int) $row->bytes
            && hash_equals($row->sha256, hash('sha256', $content)), 503);
        Outbox::record('chat.attachment_downloaded', $p['organization_id'], $p['user_id'], $id);
        return $this->metadata($row) + ['content' => base64_encode($content)];
    }

    private function metadata(object $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'mime' => $row->mime, 'bytes' => (int) $row->bytes];
    }
}
