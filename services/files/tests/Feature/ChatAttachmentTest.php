<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use SrdFiles\{AudioGate, ChatAttachmentStore, ImageGate, Mp3Gate, PdfGate, OfficeDocumentGate, Scanner};
use Tests\TestCase;

final class ChatAttachmentTest extends TestCase
{
    use SignedRequests;

    private array $p = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '22222222-2222-4222-8222-222222222222'];
    private string $conversation = '33333333-3333-4333-8333-333333333333';
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_11_000001_chat_attachments.php'))->up();
        Schema::create('file_quotas', function (Blueprint $t) { $t->uuid('organization_id')->primary(); $t->unsignedBigInteger('reserved_bytes')->default(0); });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->uuid('actor_id');
            $t->string('action'); $t->uuid('resource_id'); $t->string('result');
            $t->uuid('correlation_id'); $t->dateTime('occurred_at');
            $t->integer('attempts'); $t->dateTime('next_attempt_at');
        });
        $this->dir = storage_path('app/chat-attachment-test-'.Str::uuid());
        mkdir($this->dir, 0700, true);
        $scanner = new class implements Scanner { public int $calls = 0; public function assertClean(string $path): void { $this->calls++; } };
        $this->app->instance(ChatAttachmentStore::class, new ChatAttachmentStore(
            new ImageGate($this->dir, $scanner), new PdfGate($scanner, $this->dir),
            new OfficeDocumentGate($scanner, $this->dir), new AudioGate($scanner, $this->dir),
            new Mp3Gate($scanner, $this->dir), 100000));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) unlink($path);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return $bytes;
    }

    private function wav(): string
    {
        $samples = str_repeat("\0", 320);
        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }

    public function test_voice_note_is_scanned_and_downloaded_only_through_chat(): void
    {
        $id = (string) Str::uuid();
        $content = base64_encode($this->wav());
        $data = ['id' => $id, 'conversation_id' => $this->conversation,
            'name' => 'nota-de-voz.wav', 'content' => $content];
        $this->internal('POST', 'chat-attachments', $data, $this->p, 'chat')->assertOk()
            ->assertJsonPath('data.mime', 'audio/wav');
        $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => $this->conversation], $this->p, 'chat')
            ->assertOk()->assertJsonPath('data.content', $content);
        $this->internal('POST', 'chat-attachments', array_replace($data, ['name' => 'bad.wav', 'content' => base64_encode('RIFFbad')]), $this->p, 'chat')
            ->assertUnprocessable();
        $this->assertDatabaseCount('chat_attachments', 1);
    }

    public function test_mp3_voice_note_removes_tags_and_retries_without_extra_quota(): void
    {
        $scanner = new class implements Scanner { public function assertClean(string $path): void {} };
        $this->app->instance(ChatAttachmentStore::class, new ChatAttachmentStore(
            new ImageGate($this->dir, $scanner), new PdfGate($scanner, $this->dir),
            new OfficeDocumentGate($scanner, $this->dir), new AudioGate($scanner, $this->dir),
            new Mp3Gate($scanner, $this->dir, static function (string $path): void {}), 100000));
        $source = file_get_contents(base_path('../../tools/browser-tests/silence.mp3'));
        $id = (string) Str::uuid();
        $data = ['id' => $id, 'conversation_id' => $this->conversation,
            'name' => 'nota.mp3', 'content' => base64_encode($source)];
        $stored = $this->internal('POST', 'chat-attachments', $data, $this->p, 'chat')->assertOk()
            ->assertJsonPath('data.mime', 'audio/mpeg')->json('data');
        $this->internal('POST', 'chat-attachments', $data, $this->p, 'chat')->assertOk()->assertJsonPath('data.id', $id);
        $download = $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => $this->conversation], $this->p, 'chat')
            ->assertOk()->json('data');
        $this->assertStringStartsWith("\xff\xfb", base64_decode($download['content']));
        $this->assertSame($stored['bytes'], (int) DB::table('file_quotas')->value('reserved_bytes'));
        $this->assertDatabaseCount('chat_attachments', 1);
    }

    public function test_scanned_image_is_private_idempotent_and_quota_accounted(): void
    {
        $id = (string) Str::uuid();
        $d = ['id' => $id, 'conversation_id' => $this->conversation,
            'name' => 'Prueba.png', 'content' => base64_encode($this->png())];
        $this->internal('POST', 'chat-attachments', $d, $this->p, 'gateway')->assertForbidden();
        $created = $this->internal('POST', 'chat-attachments', $d, $this->p, 'chat')->assertOk()
            ->assertJsonPath('data.mime', 'image/png')->json('data');
        $this->internal('POST', 'chat-attachments', $d, $this->p, 'chat')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame($created['bytes'], (int) DB::table('file_quotas')->value('reserved_bytes'));
        $this->assertDatabaseCount('chat_attachments', 1);
        $download = $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => $this->conversation], $this->p, 'chat')
            ->assertOk()->json('data');
        $this->assertSame('image/png', getimagesizefromstring(base64_decode($download['content']))['mime']);
        $this->assertSame($created['bytes'], strlen(base64_decode($download['content'])));
        $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => $this->conversation], $this->p, 'gateway')->assertForbidden();
        $other = array_replace($this->p, ['organization_id' => '44444444-4444-4444-8444-444444444444']);
        $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => $this->conversation], $other, 'chat')->assertNotFound();
        $this->internal('GET', 'chat-attachments/'.$id, ['conversation_id' => (string) Str::uuid()], $this->p, 'chat')->assertNotFound();
        $this->internal('POST', 'chat-attachments', array_replace($d, ['content' => base64_encode('different')]), $this->p, 'chat')->assertUnprocessable();
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'chat.attachment_stored')->count());
    }

    public function test_collector_keeps_referenced_or_uncertain_uploads_and_releases_only_confirmed_orphans(): void
    {
        $id = (string) Str::uuid();
        $this->internal('POST', 'chat-attachments', ['id' => $id, 'conversation_id' => $this->conversation,
            'name' => 'Prueba.png', 'content' => base64_encode($this->png())], $this->p, 'chat')->assertOk();
        $bytes = (int) DB::table('file_quotas')->value('reserved_bytes');
        $old = now()->subHours(25)->toDateTimeString();
        DB::table('chat_attachments')->where('id', $id)->update(['created_at' => $old]);

        Http::fake(fn () => Http::response(['data' => ['referenced' => true]]));
        $this->assertSame(['removed' => 0, 'deferred' => 0], app(\SrdFiles\ChatAttachmentCollector::class)->collect());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([], 503));
        $this->assertSame(['removed' => 0, 'deferred' => 1], app(\SrdFiles\ChatAttachmentCollector::class)->collect());

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function () use ($id) {
            DB::table('chat_attachments')->where('id', $id)->update(['created_at' => now()]);
            return Http::response(['data' => ['referenced' => false]]);
        });
        $this->assertSame(['removed' => 0, 'deferred' => 0], app(\SrdFiles\ChatAttachmentCollector::class)->collect());
        $this->assertSame($bytes, (int) DB::table('file_quotas')->value('reserved_bytes'));

        DB::table('chat_attachments')->where('id', $id)->update(['created_at' => $old]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response(['data' => ['referenced' => false]]));
        $this->assertSame(['removed' => 1, 'deferred' => 0], app(\SrdFiles\ChatAttachmentCollector::class)->collect());
        $this->assertDatabaseMissing('chat_attachments', ['id' => $id]);
        $this->assertSame(0, (int) DB::table('file_quotas')->value('reserved_bytes'));
        $this->assertSame(1, DB::table('outbox_events')->where('action', 'chat.attachment_collected')->count());
    }
}
