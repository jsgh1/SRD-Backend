<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Outbox;
use Srd\InternalClient;
use App\Application\PhotoDeletionPublisher;

final class PrivacyRequestController
{
    public function erasureStatus(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $p = Access::require('organization.manage');
        $requests = DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
            ->where('kind', 'erasure');
        $jobs = DB::table('photo_deletions')->where('organization_id', $p['organization_id'])
            ->whereNotNull('privacy_request_id')->whereNull('delivered_at');
        return ['data' => [
            'approved_pending' => (clone $requests)->where('status', 'approved_pending_erasure')->count(),
            'in_progress' => (clone $requests)->where('status', 'erasure_in_progress')->count(),
            'photo_jobs_pending' => (clone $jobs)->count(),
            'photo_jobs_due' => (clone $jobs)->where('next_attempt_at', '<=', now())->count(),
            'photo_jobs_stalled' => (clone $jobs)->where('created_at', '<', now()->subMinutes(15))->count(),
            'checked_at' => now()->toIso8601String(),
        ]];
    }

    public function index(Request $request): array
    {
        $p = Access::require('organization.manage');
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1|max:100000',
            'kind' => 'sometimes|in:access,correction,erasure',
            'status' => 'sometimes|in:open,in_review,resolved,rejected,approved_pending_erasure,erasure_in_progress,erased',
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = 25;
        $base = DB::table('privacy_requests')->where('organization_id', $p['organization_id']);
        if (isset($filters['kind'])) $base->where('kind', $filters['kind']);
        if (isset($filters['status'])) $base->where('status', $filters['status']);
        $total = (clone $base)->count();
        $items = DB::table('privacy_requests as r')
            ->leftJoin('persons as person', function ($join) {
                $join->on('person.id', '=', 'r.person_id')->on('person.organization_id', '=', 'r.organization_id');
            })
            ->where('r.organization_id', $p['organization_id'])
            ->when(isset($filters['kind']), fn ($query) => $query->where('r.kind', $filters['kind']))
            ->when(isset($filters['status']), fn ($query) => $query->where('r.status', $filters['status']))
            ->select('r.*', 'person.first_names', 'person.last_names', 'person.document_number')
            ->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->offset(($page - 1) * $pageSize)->limit($pageSize)->get();
        $history = DB::table('privacy_request_history')->where('organization_id', $p['organization_id'])
            ->whereIn('request_id', $items->pluck('id')->all())
            ->orderBy('created_at')->orderBy('id')->get(['request_id', 'actor_id', 'status', 'created_at'])
            ->groupBy('request_id');
        foreach ($items as $item) $item->history = $history->get($item->id, collect())->values();
        return ['data' => ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]];
    }

    public function store(Request $request): array
    {
        $p = Access::require('organization.manage');
        $d = $request->validate([
            'person_id' => 'required|uuid',
            'kind' => 'required|in:access,correction,erasure',
            'summary' => 'required|string|min:10|max:500',
        ]);
        return DB::transaction(function () use ($p, $d) {
            abort_unless(DB::table('persons')->where('organization_id', $p['organization_id'])->where('id', $d['person_id'])->exists(), 404);
            $id = (string) Str::uuid();
            $time = now();
            DB::table('privacy_requests')->insert([
                'id' => $id, 'organization_id' => $p['organization_id'], 'person_id' => $d['person_id'],
                'kind' => $d['kind'], 'status' => 'open', 'summary' => trim($d['summary']),
                'created_by' => $p['user_id'], 'version' => 1, 'created_at' => $time, 'updated_at' => $time,
            ]);
            $this->history($p, $id, 'open', null, $time);
            Outbox::record('privacy_request.created', $p['organization_id'], $p['user_id'], $id);
            return ['data' => ['id' => $id, 'status' => 'open', 'version' => 1]];
        });
    }

    public function update(Request $request, string $id): array
    {
        $p = Access::require('organization.manage');
        $d = $request->validate([
            'version' => 'required|integer|min:1',
            'status' => 'required|in:in_review,resolved,rejected,approved_pending_erasure',
            'decision' => 'nullable|string|max:500',
        ]);
        return DB::transaction(function () use ($p, $d, $id) {
            $row = DB::table('privacy_requests')->where('organization_id', $p['organization_id'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless((int) $row->version === (int) $d['version'], 409, 'La solicitud cambió. Recarga antes de guardar.');
            $next = $d['status'];
            $allowed = $row->status === 'open' ? ['in_review'] : ($row->status === 'in_review'
                ? ($row->kind === 'erasure' ? ['approved_pending_erasure', 'rejected'] : ['resolved', 'rejected']) : []);
            abort_unless(in_array($next, $allowed, true), 422, 'Cambio de estado no permitido.');
            abort_if($row->status === 'in_review' && $row->responsible_id !== $p['user_id'], 403, 'Solo la persona responsable puede decidir.');
            $decision = trim($d['decision'] ?? '');
            abort_if($next !== 'in_review' && mb_strlen($decision) < 10, 422, 'Escribe una decisión de al menos 10 caracteres.');
            abort_if($next === 'in_review' && $decision !== '', 422, 'La decisión se registra al resolver.');
            $time = now();
            DB::table('privacy_requests')->where('id', $id)->where('organization_id', $p['organization_id'])->update([
                'status' => $next, 'responsible_id' => $p['user_id'], 'decision' => $decision ?: null,
                'decided_at' => $next === 'in_review' ? null : $time,
                'version' => $row->version + 1, 'updated_at' => $time,
            ]);
            $this->history($p, $id, $next, $decision ?: null, $time);
            Outbox::record('privacy_request.updated', $p['organization_id'], $p['user_id'], $id);
            return ['data' => ['id' => $id, 'status' => $next, 'version' => $row->version + 1]];
        });
    }

    public function executeErasure(Request $request, string $id, InternalClient $client, PhotoDeletionPublisher $photos): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $p = Access::require('organization.manage');
        $d = $request->validate(['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        $photoDeletionId = DB::transaction(function () use ($p, $d, $id, $client, $request) {
            $row = DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
                ->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->kind === 'erasure'
                && in_array($row->status, ['approved_pending_erasure', 'erasure_in_progress'], true), 422);
            abort_unless($row->responsible_id === $p['user_id'], 403);
            abort_unless((int) $row->version === (int) $d['version'], 409);
            if ($row->status === 'erasure_in_progress') {
                return DB::table('photo_deletions')->where('organization_id', $p['organization_id'])
                    ->where('privacy_request_id', $id)->value('id');
            }
            abort_if(DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
                ->where('person_id', $row->person_id)->where('id', '!=', $id)
                ->where('status', 'erasure_in_progress')->exists(), 409);
            DB::table('privacy_erasure_guards')->insertOrIgnore(['organization_id' => $p['organization_id']]);
            DB::table('privacy_erasure_guards')->where('organization_id', $p['organization_id'])
                ->lockForUpdate()->first();
            $person = DB::table('persons')->where('organization_id', $p['organization_id'])
                ->where('id', $row->person_id)->lockForUpdate()->first();
            abort_unless($person, 409, 'La persona ya no existe. Revisa la solicitud antes de continuar.');
            // Identity detaches the person from memberships and revokes linked invitations/sessions.
            // The operation is idempotent so a retry after a Records rollback is safe.
            $result = $client->call('identity', 'POST', 'persons/'.$row->person_id.'/detach-for-erasure', [], [
                'organization_id' => $p['organization_id'], 'user_id' => $p['user_id'],
            ]);
            abort_unless(($result['detached'] ?? false) === true, 503);
            // Export snapshots and ready files can retain PII; invalidate every job of this junta.
            DB::table('person_export_jobs')->where('organization_id', $p['organization_id'])->delete();
            DB::table('persons')->where('organization_id', $p['organization_id'])
                ->where('id', $row->person_id)->delete();
            $relatedIds = DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
                ->where('person_id', $row->person_id)->pluck('id')->all();
            DB::table('privacy_requests')->whereIn('id', $relatedIds)->update([
                'summary' => 'Contenido eliminado tras supresión.', 'decision' => null,
            ]);
            DB::table('privacy_request_history')->where('organization_id', $p['organization_id'])
                ->whereIn('request_id', $relatedIds)->update(['decision' => null]);
            $photoDeletionId = (string) Str::uuid();
            DB::table('photo_deletions')->insert([
                'id' => $photoDeletionId, 'organization_id' => $p['organization_id'],
                'person_id' => $row->person_id, 'actor_id' => $p['user_id'],
                'privacy_request_id' => $id,
                'correlation_id' => $request->attributes->get('correlation_id', (string) Str::uuid()),
                'created_at' => now(), 'next_attempt_at' => now(),
            ]);
            DB::table('privacy_requests')->where('id', $id)->where('organization_id', $p['organization_id'])
                ->update(['status' => 'erasure_in_progress', 'version' => $row->version + 1, 'updated_at' => now()]);
            $this->history($p, $id, 'erasure_in_progress', null, now());
            Outbox::record('person.deleted', $p['organization_id'], $p['user_id'], $row->person_id);
            Outbox::record('privacy_request.updated', $p['organization_id'], $p['user_id'], $id);
            return $photoDeletionId;
        });
        if ($photoDeletionId) $photos->publishOne($photoDeletionId);
        $status = DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
            ->where('id', $id)->first(['status', 'version']);
        return ['data' => ['id' => $id, 'status' => $status->status, 'version' => $status->version]];
    }

    private function history(array $p, string $id, string $status, ?string $decision, $time): void
    {
        DB::table('privacy_request_history')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $p['organization_id'], 'request_id' => $id,
            'actor_id' => $p['user_id'], 'status' => $status, 'decision' => $decision, 'created_at' => $time,
        ]);
    }
}
