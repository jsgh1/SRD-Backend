<?php

namespace App\Http\Controllers;

use App\Application\AuditWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Srd\Access;
use Srd\ExportFilename;

final class AuditController
{
    public function ingest(Request $r): array
    {
        $d = $r->validate(['id' => 'required|uuid', 'organization_id' => 'nullable|uuid', 'actor_id' => 'nullable|uuid', 'service' => 'required|in:identity,configuration,records,files,calendar,notifications,treasury,inventory,chat', 'action' => 'required|string|max:100', 'resource_id' => 'nullable|uuid', 'result' => 'required|in:success,rejected,failed', 'correlation_id' => 'required|uuid', 'occurred_at' => 'required|date', 'event_version' => 'required|in:1']);
        abort_unless($r->attributes->get('issuer') === $d['service'], 403);
        DB::table('audit_events')->insertOrIgnore($d);

        return ['data' => ['accepted' => true]];
    }

    private function filters(Request $r, bool $pagination): array
    {
        return $r->validate([
            ...($pagination ? ['page' => 'sometimes|integer|min:1|max:1000000', 'page_size' => 'sometimes|integer|in:10,25,50'] : []),
            'action' => 'nullable|string|max:100',
            'result' => 'nullable|in:success,rejected,failed',
            'service' => 'nullable|in:identity,configuration,records,files,calendar,notifications,treasury,inventory,chat',
            'actor_id' => 'nullable|uuid',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ]);
    }

    private function query(string $organizationId, array $d)
    {
        $q = DB::table('audit_events')->where('organization_id', $organizationId);
        foreach (['action', 'result', 'service', 'actor_id'] as $f) {
            if (isset($d[$f]) && $d[$f] !== '') {
                $q->where($f, $d[$f]);
            }
        }
        // Inclusive UTC days, with an exclusive next-day upper bound for microseconds.
        if (! empty($d['date_from'])) $q->where('occurred_at', '>=', $d['date_from'].' 00:00:00');
        if (! empty($d['date_to'])) $q->where('occurred_at', '<', Carbon::createFromFormat('!Y-m-d', $d['date_to'], 'UTC')->addDay()->toDateTimeString());

        return $q;
    }

    public function index(Request $r): array
    {
        $p = Access::require('audit.read');
        $d = $this->filters($r, true);
        $q = $this->query($p['organization_id'], $d);
        $total = (clone $q)->count();
        $page = (int) ($d['page'] ?? 1);
        $size = (int) ($d['page_size'] ?? 25);

        return ['data' => ['items' => $q->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, $size)->get(), 'total' => $total, 'page' => $page, 'page_size' => $size]];
    }

    public function export(Request $r): array
    {
        return $this->exportRequest($r, false);
    }

    public function exportPdf(Request $r): array
    {
        return $this->exportRequest($r, true);
    }

    private function exportRequest(Request $r, bool $pdf): array
    {
        abort_unless($r->attributes->get('issuer') === 'gateway', 403);
        $p = Access::require('audit.export');
        $d = $this->filters($r, false);
        $fileOptions = $r->validate([
            'filename' => 'sometimes|nullable|string|max:100',
            'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        $language = $fileOptions['lang'] ?? 'es';
        $filename = $pdf
            ? ExportFilename::pdf('auditoria', $fileOptions['filename'] ?? null, (bool) ($fileOptions['confirm_filename'] ?? false))
            : ExportFilename::xlsx('auditoria', $fileOptions['filename'] ?? null, (bool) ($fileOptions['confirm_filename'] ?? false));
        $events = $this->query($p['organization_id'], $d)
            ->select(['id', 'occurred_at', 'service', 'action', 'result', 'actor_id', 'resource_id', 'correlation_id'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(2001)->get();
        if ($events->count() > 2000) {
            throw ValidationException::withMessages(['export' => 'La consulta supera 2000 eventos. Acota los filtros antes de exportar.']);
        }

        if ($pdf) return ['data' => ['filename' => $filename, 'date' => now('America/Bogota')->toDateString(),
            'count' => $events->count()] + AuditWorkbook::table($events, $language)];

        return ['data' => [
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => base64_encode(AuditWorkbook::create($events, $language)),
            'count' => $events->count(),
        ]];
    }
}
