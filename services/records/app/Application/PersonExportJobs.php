<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\ExportFilename;
use Srd\InternalClient;
use Srd\Outbox;

final class PersonExportJobs
{
    public function __construct(private PersonService $persons, private InternalClient $client) {}

    public function create(array $principal, array $filters, ?string $name, bool $confirmed, string $language, string $format = 'xlsx'): array
    {
        return DB::transaction(function () use ($principal, $filters, $name, $confirmed, $language, $format) {
        DB::table('privacy_erasure_guards')->insertOrIgnore(['organization_id' => $principal['organization_id']]);
        DB::table('privacy_erasure_guards')->where('organization_id', $principal['organization_id'])
            ->lockForUpdate()->first();
        $filename = $format === 'pdf'
            ? ExportFilename::pdf('personas', $name, $confirmed)
            : ExportFilename::xlsx('personas', $name, $confirmed);
        // Capturar las filas completas ahora evita que cambios posteriores alteren este trabajo.
        $rows = $this->persons->snapshotExportRows($principal, $filters);
        if ($rows->count() <= 500) {
            throw ValidationException::withMessages(['export' => 'La exportación en segundo plano requiere más de 500 personas.']);
        }
        $id = (string) Str::uuid();
        $now = now('UTC');
        $snapshot = $format === 'pdf' ? ['rows' => $rows,
            'logo_data' => DB::table('planilla_settings')->where('organization_id', $principal['organization_id'])->value('logo_data')] : $rows;
        DB::table('person_export_jobs')->insert([
            'id' => $id, 'organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id'],
            'status' => 'queued', 'format' => $format, 'filename' => $filename, 'language' => $language,
            'row_count' => $rows->count(), 'cutoff_at' => $now,
            'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Outbox::record('person.export_queued', $principal['organization_id'], $principal['user_id'], $id);
        return ['data' => ['id' => $id, 'status' => 'queued', 'format' => $format,
            'count' => $rows->count(), 'cutoff_at' => $now->toIso8601String()]];
        });
    }

    public function status(array $principal, string $id): array
    {
        $job = $this->owned($principal, $id);
        $available = $job->status === 'ready' && $job->content_base64 !== null
            && $job->file_expires_at > now('UTC')->toDateTimeString();
        $data = ['id' => $job->id, 'status' => $job->status === 'ready' && !$available ? 'expired' : $job->status,
            'count' => (int) $job->row_count, 'format' => $job->format,
            'cutoff_at' => $job->cutoff_at, 'filename' => $job->filename];
        if ($available) {
            $expires = now('UTC')->addMinutes(15)->timestamp;
            $data['ticket'] = $expires.'.'.hash_hmac('sha256', $id.'|'.$principal['organization_id'].'|'.$principal['user_id'].'|'.$expires, config('app.key'));
        }
        return ['data' => $data];
    }

    public function download(array $principal, string $id, string $ticket): array
    {
        $job = $this->owned($principal, $id);
        [$expires, $signature] = explode('.', $ticket, 2) + [null, null];
        $expected = hash_hmac('sha256', $id.'|'.$principal['organization_id'].'|'.$principal['user_id'].'|'.$expires, config('app.key'));
        abort_unless($job->status === 'ready' && $job->content_base64 !== null
            && $job->file_expires_at > now('UTC')->toDateTimeString()
            && ctype_digit((string) $expires) && (int) $expires >= now('UTC')->timestamp
            && is_string($signature) && hash_equals($expected, $signature), 404);
        Outbox::record('person.export_download', $principal['organization_id'], $principal['user_id'], $id);
        if ($job->format === 'pdf') {
            $bytes = base64_decode($job->content_base64, true);
            if (is_string($bytes) && str_starts_with($bytes, '%PDF-')) {
                return ['data' => ['filename' => $job->filename, 'mime' => 'application/pdf',
                    'content' => $job->content_base64, 'count' => (int) $job->row_count]];
            }
            // Los trabajos PDF listos antes de este cambio conservan su formato anterior.
            return ['data' => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)];
        }
        return ['data' => ['filename' => $job->filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $job->content_base64, 'count' => (int) $job->row_count]];
    }

    private function owned(array $principal, string $id): object
    {
        $job = DB::table('person_export_jobs')->where('id', $id)
            ->where('organization_id', $principal['organization_id'])->where('user_id', $principal['user_id'])->first();
        abort_unless($job, 404);
        return $job;
    }

    public function processBatch(): array
    {
        $done = 0;
        // Si el proceso muere, el siguiente ciclo recupera un trabajo interrumpido.
        DB::table('person_export_jobs')->where('status', 'processing')
            ->where('updated_at', '<', now('UTC')->subMinutes(10))->update(['status' => 'queued']);
        foreach (DB::table('person_export_jobs')->where('status', 'queued')->orderBy('created_at')->limit(2)->pluck('id') as $id) {
            $claimed = DB::table('person_export_jobs')->where('id', $id)->where('status', 'queued')
                ->update(['status' => 'processing', 'updated_at' => now('UTC')]);
            if (!$claimed) continue;
            try {
                $job = DB::table('person_export_jobs')->where('id', $id)->first();
                $snapshot = json_decode($job->snapshot_json, false, 512, JSON_THROW_ON_ERROR);
                // Los trabajos anteriores guardaron solo una lista de filas.
                $rows = collect(is_array($snapshot) ? $snapshot : $snapshot->rows);
                if ($job->format === 'pdf') {
                    $table = PersonWorkbook::table($rows, $job->language);
                    $content = base64_encode(PersonExportPdf::render($table['headers'], $table['rows'],
                        \Illuminate\Support\Carbon::parse($job->cutoff_at, 'UTC')->timezone('America/Bogota')->toDateString(),
                        $job->language, is_array($snapshot) ? null : ($snapshot->logo_data ?? null)));
                } else {
                    $content = base64_encode(PersonWorkbook::create($rows, $job->language));
                }
                DB::table('person_export_jobs')->where('id', $id)->update([
                    'status' => 'ready', 'snapshot_json' => '[]', 'content_base64' => $content,
                    'file_expires_at' => now('UTC')->addDay(), 'updated_at' => now('UTC'),
                ]);
                $done++;
            } catch (\Throwable $error) {
                report($error);
                DB::table('person_export_jobs')->where('id', $id)->update([
                    'status' => 'failed', 'snapshot_json' => '[]', 'error_code' => 'generation_failed',
                    'updated_at' => now('UTC'),
                ]);
            }
        }
        $notified = $this->notifyReady();
        DB::table('person_export_jobs')->where('file_expires_at', '<=', now('UTC'))
            ->whereNotNull('content_base64')->update(['content_base64' => null]);
        return ['processed' => $done, 'notified' => $notified];
    }

    private function notifyReady(): int
    {
        $sent = 0;
        $due = DB::table('person_export_jobs')->where('status', 'ready')->whereNull('notification_sent_at')
            ->where('file_expires_at', '>', now('UTC'))->where(function ($query) {
                $query->whereNull('notification_next_attempt_at')
                    ->orWhere('notification_next_attempt_at', '<=', now('UTC'));
            })->orderBy('created_at')->limit(5)->get();
        foreach ($due as $job) {
            try {
                $result = $this->client->call('notifications', 'POST', 'deliveries', [
                    'organization_id' => $job->organization_id, 'user_id' => $job->user_id,
                    'export_job_id' => $job->id, 'kind' => 'export_ready',
                    'delivery_key' => hash('sha256', 'person_export_ready|'.$job->id),
                ], ['organization_id' => $job->organization_id], 5);
                if (empty($result['id'])) throw new \RuntimeException('Notification was not confirmed.');
                DB::table('person_export_jobs')->where('id', $job->id)->update([
                    'notification_sent_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
                $sent++;
            } catch (\Throwable) {
                $attempts = (int) $job->notification_attempts + 1;
                DB::table('person_export_jobs')->where('id', $job->id)->update([
                    'notification_attempts' => $attempts,
                    'notification_next_attempt_at' => now('UTC')->addMinutes(min(60, 2 ** min($attempts - 1, 6))),
                    'updated_at' => now('UTC'),
                ]);
            }
        }
        return $sent;
    }
}
