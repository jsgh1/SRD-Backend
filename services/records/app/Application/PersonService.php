<?php

namespace App\Application;

use App\Persistence\PersonRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Outbox;
use Srd\ExportFilename;
use Illuminate\Validation\ValidationException;

final class PersonService
{
    public function __construct(private PersonRepository $people, private PersonFields $fields) {}

    private function query(array $p)
    {
        return $this->people->forOrganization($p['organization_id']);
    }

    private function filters($q, array $filters): void
    {
        if (isset($filters['q']) && $filters['q'] !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $q->where(fn ($q) => $q->whereRaw("first_names LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("last_names LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("document_number LIKE ? ESCAPE '!'", [$needle]));
        }
        foreach (['status', 'zone', 'affiliated', 'document_type', 'gender', 'descriptive_role', 'position_code'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $q->where($field, $filters[$field]);
            }
        }
        if (isset($filters['birth_date_from'])) $q->whereBetween('birth_date', [$filters['birth_date_from'], $filters['birth_date_to']]);
        if (isset($filters['registered_from'])) {
            // Local calendar dates become a half-open UTC range; the last day is included.
            $start = \Carbon\CarbonImmutable::parse($filters['registered_from'], 'America/Bogota')->startOfDay()->utc();
            $end = \Carbon\CarbonImmutable::parse($filters['registered_to'], 'America/Bogota')->startOfDay()->addDay()->utc();
            $q->where('created_at', '>=', $start)->where('created_at', '<', $end);
        }
    }

    private function filteredQuery(array $p, array $filters)
    {
        if (isset($filters['position_code']) && $filters['position_code'] !== '') {
            $catalog = app(PersonPositions::class)->catalog($p['organization_id']);
            if (!in_array($filters['position_code'], array_column($catalog['items'], 'code'), true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['position_code' => 'El cargo no pertenece al catálogo de esta junta.']);
            }
        }
        $q = $this->query($p);
        $this->filters($q, $filters);
        $this->fields->filter($q, $p['organization_id'], $filters['custom_filters'] ?? []);
        return $q;
    }

    public function index(array $p, array $filters): array
    {
        $q = $this->filteredQuery($p, $filters);
        $total = (clone $q)->count();
        $size = (int) ($filters['page_size'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);

        return ['data' => ['items' => $q->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $size)->get(), 'page' => $page, 'page_size' => $size, 'total' => $total]];
    }

    public function export(array $p, array $filters, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $filename = ExportFilename::xlsx('personas', $requestedFilename, $confirmed);
        $rows = $this->exportRows($p, $filters);
        if ($rows->count() > 500) {
            throw ValidationException::withMessages(['export' => 'Para más de 500 personas, solicita una exportación en segundo plano.']);
        }
        $content = base64_encode(PersonWorkbook::create($rows, $language));
        Outbox::record('person.export', $p['organization_id'], $p['user_id'], null);
        return ['data' => [
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content,
            'count' => $rows->count(),
        ]];
    }

    public function exportIndividual(array $p, string $id, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $row = $this->query($p)->where('id', $id)->first();
        abort_unless($row, 404);
        $filename = ExportFilename::xlsx('ficha_persona', $requestedFilename, $confirmed);
        $content = base64_encode(PersonWorkbook::individual((array) $row, $this->fields->snapshots($p['organization_id'], $id), $language));
        Outbox::record('person.individual_export', $p['organization_id'], $p['user_id'], $id);
        return ['data' => ['filename' => $filename, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content, 'count' => 1]];
    }

    public function exportIndividualPdf(array $p, string $id, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $row = $this->query($p)->where('id', $id)->first();
        abort_unless($row, 404);
        $filename = ExportFilename::pdf('ficha_persona', $requestedFilename, $confirmed);
        $table = PersonWorkbook::individualTable((array) $row, $this->fields->snapshots($p['organization_id'], $id), $language);
        Outbox::record('person.individual_export', $p['organization_id'], $p['user_id'], $id);
        return ['data' => ['filename' => $filename, 'date' => now('America/Bogota')->toDateString(), 'count' => 1, 'language' => $language,
            'logo_data' => DB::table('planilla_settings')->where('organization_id', $p['organization_id'])->value('logo_data')] + $table];
    }

    public function exportPdf(array $p, array $filters, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $filename = ExportFilename::pdf('personas', $requestedFilename, $confirmed);
        $rows = $this->exportRows($p, $filters);
        $table = PersonWorkbook::table($rows, $language);
        Outbox::record('person.export', $p['organization_id'], $p['user_id'], null);
        return ['data' => ['filename' => $filename, 'date' => now('America/Bogota')->toDateString(), 'language' => $language,
            'logo_data' => DB::table('planilla_settings')->where('organization_id', $p['organization_id'])->value('logo_data'),
            'headers' => $table['headers'], 'rows' => $table['rows'], 'count' => $rows->count()]];
    }

    private function exportRows(array $p, array $filters)
    {
        $rows = $this->filteredQuery($p, $filters)
            ->select(['id', 'document_type', 'document_number', 'first_names', 'last_names',
                'status', 'affiliated', 'zone', 'birth_date', 'position_label', 'position_label_en', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(2001)->get();
        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['export' => 'La consulta supera 2000 personas. Acota los filtros antes de exportar.']);
        }
        return $rows;
    }

    public function snapshotExportRows(array $p, array $filters)
    {
        return $this->exportRows($p, $filters);
    }

    public function exportPlanilla(array $p, array $filters, array $columns, array $headings, string $language, ?string $requestedFilename, bool $confirmed): array
    {
        $filename = ExportFilename::xlsx('planilla', $requestedFilename, $confirmed);
        [$rows, $resolvedHeadings, $logo] = $this->planillaData($p, $filters, $columns, $headings, $language);
        $content = base64_encode(PlanillaWorkbook::create($rows, $columns, $resolvedHeadings, $language, $logo));
        Outbox::record('person.planilla_export', $p['organization_id'], $p['user_id'], null);
        return ['data' => [
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content,
            'count' => $rows->count(),
        ]];
    }

    public function previewPlanilla(array $p, array $filters, array $columns, array $headings, string $language, ?string $requestedFilename, bool $confirmed): array
    {
        $filename = ExportFilename::xlsx('planilla', $requestedFilename, $confirmed);
        [$rows, $resolvedHeadings, $logo] = $this->planillaData($p, $filters, $columns, $headings, $language);
        Outbox::record('person.planilla_preview', $p['organization_id'], $p['user_id'], null);
        return ['data' => ['title' => substr($filename, 0, -5)] + $this->planillaPayload($rows, $columns, $resolvedHeadings, $language, $logo)];
    }

    public function pdfPlanilla(array $p, array $filters, array $columns, array $headings, string $language, ?string $requestedFilename, bool $confirmed, bool $blankRows = false): array
    {
        $filename = ExportFilename::pdf('planilla', $requestedFilename, $confirmed);
        [$rows, $resolvedHeadings, $logo] = $this->planillaData($p, $filters, $columns, $headings, $language, $blankRows);
        Outbox::record('person.planilla_export', $p['organization_id'], $p['user_id'], null);
        return ['data' => ['filename' => $filename] + $this->planillaPayload($rows, $columns, $resolvedHeadings, $language, $logo)];
    }

    private function planillaPayload($rows, array $columns, array $resolvedHeadings, string $language, ?string $logo): array
    {
        $table = PlanillaWorkbook::table($rows, $columns, $language);
        $date = now('America/Bogota');
        return [
            'headings' => $resolvedHeadings,
            'logo_data' => $logo,
            'language' => $language,
            'date' => ['month' => $date->format('m'), 'day' => $date->format('d'), 'year' => $date->format('Y')],
            'headers' => $table['headers'], 'rows' => $table['rows'], 'count' => $rows->count(),
        ];
    }

    private function planillaData(array $p, array $filters, array $columns, array $headings, string $language, bool $blankRows = false): array
    {
        $settings = app(PlanillaSettings::class)->read($p);
        $allowed = $settings['allowed_columns'];
        if (count($columns) > 5 || count($columns) !== count(array_unique($columns))
            || array_diff($columns, $allowed)) {
            throw ValidationException::withMessages(['columns' => 'Selecciona hasta cinco columnas adicionales distintas y disponibles.']);
        }
        $rows = $blankRows ? collect([]) : $this->filteredQuery($p, $filters)
            ->select(array_merge(['id', 'first_names', 'last_names', 'document_type', 'document_number'], $columns,
                $language === 'en' && in_array('position_label', $columns, true) ? ['position_label_en'] : []))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(2001)->get();
        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['export' => 'La consulta supera 2000 personas. Acota los filtros antes de exportar.']);
        }
        return [$rows, array_replace([
            'h1' => $language === 'en' ? ($settings['h1_en'] ?: $settings['h1']) : $settings['h1'],
            'h2' => $language === 'en' ? ($settings['h2_en'] ?: $settings['h2']) : $settings['h2'],
            'h3' => $language === 'en' ? ($settings['h3_en'] ?: $settings['h3']) : $settings['h3'],
        ], $headings), $settings['logo_data']];
    }

    private function detail(array $p, string $id): array
    {
        $row = $this->query($p)->where('id', $id)->first();
        abort_unless($row, 404);
        $data = (array) $row;
        $data['custom_fields'] = (object) $this->fields->snapshots($p['organization_id'], $id);
        if (Access::allows($p['role'], 'persons.note')) {
            $data['note'] = DB::table('person_notes')->where('organization_id', $p['organization_id'])->where('person_id', $id)->value('body');
        }
        $data['authorization'] = DB::table('data_authorizations')->where('organization_id', $p['organization_id'])->where('person_id', $id)->first();
        Outbox::record('person.read', $p['organization_id'], $p['user_id'], $id);

        return $data;
    }

    public function show(array $p, string $id): array
    {
        return ['data' => $this->detail($p, $id)];
    }

    public function lookup(array $p, array $d): array
    {
        $id = $this->query($p)->where($d)->value('id');

        return ['data' => $id ? $this->detail($p, $id) : []];
    }

    public function save(array $p, array $d, ?string $id = null): array
    {
        return DB::transaction(function () use ($p, $d, $id) {
            $schema = $this->fields->schema($p['organization_id'], true);
            abort_unless((int) ($d['schema_version'] ?? 0) === $schema['version'], 409, 'Los campos de la junta cambiaron. Recarga el formulario antes de guardar.');
            $existing = $id ? $this->query($p)->where('id', $id)->lockForUpdate()->first() : null;
            $positions = app(PersonPositions::class);
            $catalog = $positions->catalog($p['organization_id'], true);
            abort_unless((int) ($d['positions_version'] ?? 0) === $catalog['version'], 409, 'Los cargos de la junta cambiaron. Recarga el formulario antes de guardar.');
            if ($id) {
                abort_unless($existing, 404);
                abort_unless($existing->version === $d['version'], 409);
            }
            $personId = $id ?? (string) Str::uuid();
            $d = array_replace($d, $positions->labels($catalog, array_key_exists('position_code', $d) ? $d['position_code'] : ($existing->position_code ?? null), $existing));
            unset($d['positions_version']);
            $values = $this->fields->validateValues($schema, $d['custom_values'] ?? [], $id ? $this->fields->snapshots($p['organization_id'], $id) : [], $d['status'] === 'complete');
            unset($d['custom_values'], $d['schema_version']);
            $note = $d['note'] ?? null;
            $basis = $d['authorization_basis'] ?? null;
            $purpose = $d['authorization_purpose'] ?? null;
            unset($d['note'],$d['authorization_basis'],$d['authorization_purpose']);
            $d['version'] = $existing ? $existing->version + 1 : 1;
            if (($d['zone'] ?? null) === 'rural') {
                $d['address'] = null;
                $d['neighborhood'] = null;
            }
            if (($d['zone'] ?? null) === 'urban') {
                $d['property_name'] = null;
            }
            if ($id) {
                $this->query($p)->where('id', $id)->update($d + ['updated_at' => now()]);
            } else {
                DB::table('persons')->insert($d + ['id' => $personId, 'organization_id' => $p['organization_id'], 'created_by' => $p['user_id'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($note !== null) {
                // Notes remain separate from additional fields and their read permissions.
                DB::table('person_notes')->updateOrInsert(['person_id' => $personId, 'organization_id' => $p['organization_id']], ['body' => $note, 'updated_by' => $p['user_id'], 'updated_at' => now()]);
            }
            if ($basis !== null && $purpose !== null) {
                DB::table('data_authorizations')->updateOrInsert(['person_id' => $personId, 'organization_id' => $p['organization_id']], ['basis' => $basis, 'purpose' => $purpose, 'captured_by' => $p['user_id'], 'captured_at' => now()]);
            }
            Outbox::record($id ? 'person.updated' : 'person.created', $p['organization_id'], $p['user_id'], $personId);
            $this->fields->persist($p['organization_id'], $personId, $values);

            return ['data' => ['id' => $personId, 'version' => $d['version']]];
        });
    }

    public function delete(array $p, array $d, string $id): array
    {
        DB::transaction(function () use ($p, $id, $d) {
            $row = $this->query($p)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->version === $d['version'], 409);
            abort_if(DB::table('privacy_requests')->where('organization_id', $p['organization_id'])
                ->where('person_id', $id)->where('kind', 'erasure')
                ->whereIn('status', ['open', 'in_review', 'approved_pending_erasure', 'erasure_in_progress'])
                ->exists(), 409, 'Resuelve la solicitud de supresión antes de borrar esta ficha.');
            $this->query($p)->where('id', $id)->delete();
            DB::table('photo_deletions')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $p['organization_id'],
                'person_id' => $id, 'actor_id' => $p['user_id'],
                'correlation_id' => request()->attributes->get('correlation_id', (string) Str::uuid()),
                'created_at' => now(), 'next_attempt_at' => now(),
            ]);
            Outbox::record('person.deleted', $p['organization_id'], $p['user_id'], $id);
        });

        return ['data' => []];
    }

    public function dashboard(array $p): array
    {
        return DB::transaction(function () use ($p) {
        $q = $this->query($p);
        $generated = now('America/Bogota');
        $today = $generated->clone()->startOfDay();
        $end = $today->clone()->addDay()->utc();
        $count = fn ($start) => (clone $q)->where('created_at', '>=', $start->clone()->utc())->where('created_at', '<', $end)->count();
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $start = $today->clone()->subDays($i);
            $days[] = ['date' => $start->toDateString(), 'count' => (clone $q)->where('created_at', '>=', $start->clone()->utc())->where('created_at', '<', $start->clone()->addDay()->utc())->count()];
        }

        return ['data' => ['date' => $today->toDateString(), 'generated_at' => $generated->clone()->utc()->toIso8601String(), 'timezone' => 'America/Bogota', 'total' => (clone $q)->count(), 'today' => $count($today), 'week' => $count($today->clone()->startOfWeek(\Carbon\CarbonInterface::MONDAY)), 'month' => $count($today->clone()->startOfMonth()), 'last_seven_days' => $days, 'gender' => (clone $q)->selectRaw('gender, count(*) as count')->groupBy('gender')->get(), 'document_types' => (clone $q)->selectRaw('document_type, count(*) as count')->groupBy('document_type')->get(), 'latest' => (clone $q)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()]];
        });
    }
}
