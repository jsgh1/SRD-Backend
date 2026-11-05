<?php

namespace Tests\Feature;

use App\Application\PersonExportJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PersonExportJobsTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';
    private const USER = '22222222-2222-4222-8222-222222222222';

    private function principal(string $organization = self::ORG, string $user = self::USER): array
    {
        return ['organization_id' => $organization, 'user_id' => $user, 'role' => 'admin'];
    }

    public function test_async_export_keeps_snapshot_and_requires_current_owner_and_ticket(): void
    {
        for ($start = 1; $start <= 501; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, 502); $i++) {
                $rows[] = ['id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $i),
                    'organization_id' => self::ORG, 'document_type' => 'CC',
                    'document_number' => sprintf('DOC-%04d', $i),
                    'first_names' => 'Persona de prueba', 'status' => 'pending', 'version' => 1,
                    'created_by' => self::USER, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('persons')->insert($rows);
        }
        $id = $this->internal('POST', 'persons/export-jobs', ['language' => 'en'], $this->principal())
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.count', 501)->json('data.id');
        $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal(self::OTHER))->assertNotFound();
        $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal(self::ORG, '44444444-4444-4444-8444-444444444444'))->assertNotFound();
        DB::table('persons')->where('document_number', 'DOC-0501')->update(['first_names' => 'CAMBIO-POSTERIOR']);
        Http::fake(['http://notifications:8000/*' => Http::response(['data' => ['id' => '55555555-5555-4555-8555-555555555555']], 200)]);
        $this->assertSame(1, app(PersonExportJobs::class)->processBatch()['processed']);
        $this->assertDatabaseHas('person_export_jobs', ['id' => $id, 'notification_attempts' => 0]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deliveries')
            && $request['export_job_id'] === $id && $request['kind'] === 'export_ready');
        $ready = $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal())->assertOk()
            ->assertJsonPath('data.status', 'ready')->json('data');
        $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => time().'.'.str_repeat('0', 64)], $this->principal())->assertNotFound();
        $file = $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => $ready['ticket']], $this->principal())
            ->assertOk()->assertJsonPath('data.count', 501)->json('data.content');
        $bytes = base64_decode($file, true);
        $this->assertIsString($bytes);
        $this->assertStringContainsString('DOC-0501', $bytes);
        $this->assertStringNotContainsString('CAMBIO-POSTERIOR', $bytes);
        $expired = now('UTC')->subSecond()->timestamp;
        $signature = hash_hmac('sha256', $id.'|'.self::ORG.'|'.self::USER.'|'.$expired, config('app.key'));
        $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => $expired.'.'.$signature], $this->principal())->assertNotFound();
        DB::table('person_export_jobs')->where('id', $id)->update(['file_expires_at' => now('UTC')->subSecond()]);
        $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal())->assertOk()
            ->assertJsonPath('data.status', 'expired')->assertJsonMissingPath('data.ticket');
        $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => $ready['ticket']], $this->principal())->assertNotFound();
        DB::table('person_export_jobs')->where('id', $id)->update([
            'file_expires_at' => now('UTC')->addHour(), 'content_base64' => null,
        ]);
        $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal())->assertOk()
            ->assertJsonPath('data.status', 'expired')->assertJsonMissingPath('data.ticket');
        DB::table('persons')->where('document_number', 'DOC-0501')->delete();
        $this->internal('POST', 'persons/export-jobs', [], $this->principal())->assertUnprocessable();
    }

    public function test_pdf_job_prepares_binary_in_background_and_keeps_download_private(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lZkAAAAASUVORK5CYII=';
        $this->internal('PUT', 'planilla-settings', [
            'version' => 0, 'allowed_columns' => ['zone', 'phone'],
            'h1' => 'JUNTA COMUNAL', 'h2' => 'Sector norte', 'h3' => 'ASISTENCIA',
            'h1_en' => 'COMMUNITY BOARD', 'h2_en' => 'North area', 'h3_en' => 'ATTENDANCE',
            'logo_data' => $logo,
        ], $this->principal())->assertOk();
        for ($start = 1; $start <= 501; $start += 100) {
            $rows = [];
            for ($i = $start; $i < min($start + 100, 502); $i++) {
                $rows[] = ['id' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $i),
                    'organization_id' => self::ORG, 'document_type' => 'CC',
                    'document_number' => sprintf('PDF-%04d', $i),
                    'first_names' => 'Original', 'status' => 'pending', 'version' => 1,
                    'created_by' => self::USER, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('persons')->insert($rows);
        }
        $id = $this->internal('POST', 'persons/export-jobs', ['format' => 'pdf', 'language' => 'en'], $this->principal())
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.format', 'pdf')->json('data.id');
        DB::table('persons')->where('document_number', 'PDF-0501')->update(['first_names' => 'Changed later']);
        DB::table('planilla_settings')->where('organization_id', self::ORG)->update(['logo_data' => null]);
        Http::fake(['http://notifications:8000/*' => Http::response(['data' => ['id' => '55555555-5555-4555-8555-555555555555']], 200)]);
        $this->assertSame(1, app(PersonExportJobs::class)->processBatch()['processed']);
        $this->assertSame('[]', DB::table('person_export_jobs')->where('id', $id)->value('snapshot_json'));
        $ready = $this->internal('GET', 'persons/export-jobs/'.$id, [], $this->principal())->assertOk()
            ->assertJsonPath('data.format', 'pdf')->assertJsonPath('data.status', 'ready')->json('data');
        $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => $ready['ticket']],
            $this->principal(self::OTHER))->assertNotFound();
        $data = $this->internal('GET', 'persons/export-jobs/'.$id.'/download', ['ticket' => $ready['ticket']],
            $this->principal())->assertOk()->assertJsonPath('data.count', 501)
            ->assertJsonPath('data.mime', 'application/pdf')->json('data');
        $this->assertSame('personas_'.now('America/Bogota')->toDateString().'.pdf', $data['filename']);
        $this->assertSame(DB::table('person_export_jobs')->where('id', $id)->value('content_base64'), $data['content']);
        $this->assertStringStartsWith('%PDF-', base64_decode($data['content'], true));
        $this->assertGreaterThan(1000, strlen(base64_decode($data['content'], true)));
        $legacy = $this->internal('POST', 'persons/export-jobs', ['format' => 'pdf'], $this->principal())
            ->assertOk()->json('data.id');
        $snapshot = json_decode(DB::table('person_export_jobs')->where('id', $legacy)->value('snapshot_json'), true, 512, JSON_THROW_ON_ERROR);
        DB::table('person_export_jobs')->where('id', $legacy)->update(['snapshot_json' => json_encode($snapshot['rows'], JSON_THROW_ON_ERROR)]);
        $this->assertSame(1, app(PersonExportJobs::class)->processBatch()['processed']);
        $legacyReady = $this->internal('GET', 'persons/export-jobs/'.$legacy, [], $this->principal())->assertOk()->json('data');
        $this->internal('GET', 'persons/export-jobs/'.$legacy.'/download', ['ticket' => $legacyReady['ticket']], $this->principal())
            ->assertOk()->assertJsonPath('data.mime', 'application/pdf')->assertJsonPath('data.count', 501);
        $this->internal('POST', 'persons/export-jobs', ['format' => 'zip'], $this->principal())->assertUnprocessable();
    }
}
