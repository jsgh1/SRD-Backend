<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\Access;
use Srd\InternalClient;
use Srd\Outbox;

final class CalendarService
{
    public function __construct(private InternalClient $identity, private DeliveryService $deliveries) {}

    private function reminder(string $organizationId, string $eventId, string $userId, string $title, CarbonImmutable $start, bool $remind24h = true, bool $remind1h = true, ?string $titleEn = null): void
    {
        $this->deliveries->enqueueReminders($organizationId, $eventId, $userId, $title, $start, $remind24h, $remind1h, $titleEn);
    }

    private const TZ = 'America/Bogota';
    private const DELEGABLE = ['registrar', 'treasurer', 'auditor', 'viewer'];

    private function settings(string $organizationId, bool $lock = false): array
    {
        if ($lock) DB::table('calendar_settings')->upsert([[
            'organization_id' => $organizationId, 'version' => 0, 'editor_roles' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]], ['organization_id'], ['organization_id']);
        $query = DB::table('calendar_settings')->where('organization_id', $organizationId);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();

        return $row ? ['version' => (int) $row->version, 'editor_roles' => json_decode($row->editor_roles, true, 512, JSON_THROW_ON_ERROR)]
            : ['version' => 0, 'editor_roles' => []];
    }

    public function readSettings(array $principal): array
    {
        $value = $this->settings($principal['organization_id']);
        $value['can_manage'] = Access::allows($principal['role'], 'calendar.manage');
        $value['can_edit'] = $value['can_manage'] || in_array($principal['role'], $value['editor_roles'], true);

        return ['data' => $value];
    }

    public function updateSettings(array $principal, array $data): array
    {
        return DB::transaction(function () use ($principal, $data) {
            $old = $this->settings($principal['organization_id'], true);
            abort_unless($old['version'] === $data['version'], 409, 'La delegación cambió. Recarga antes de guardar.');
            foreach ($data['editor_roles'] as $role) {
                if (!in_array($role, self::DELEGABLE, true)) throw ValidationException::withMessages(['editor_roles' => 'Rol no delegable.']);
            }
            DB::table('calendar_settings')->where('organization_id', $principal['organization_id'])->update([
                'version' => $old['version'] + 1,
                'editor_roles' => json_encode($data['editor_roles'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            Outbox::record('calendar.settings_updated', $principal['organization_id'], $principal['user_id'], $principal['organization_id']);

            return $this->readSettings($principal);
        });
    }

    private function requireEditor(array $principal): void
    {
        $settings = $this->settings($principal['organization_id'], true);
        abort_unless(Access::allows($principal['role'], 'calendar.manage') || in_array($principal['role'], $settings['editor_roles'], true), 403);
    }

    private function state(object $event, CarbonImmutable $now): string
    {
        if ($event->cancelled_at !== null) return 'cancelled';
        if (CarbonImmutable::parse($event->ends_at, 'UTC')->lessThanOrEqualTo($now)) return 'finished';
        $start = CarbonImmutable::parse($event->starts_at, 'UTC');
        if ($start->lessThanOrEqualTo($now)) return 'in_progress';
        return $start->lessThanOrEqualTo($now->addHours(24)) ? 'upcoming' : 'scheduled';
    }

    private function participants(array $eventIds, string $organizationId): array
    {
        if (!$eventIds) return [];
        $rows = DB::table('calendar_participants')->where('organization_id', $organizationId)
            ->whereIn('event_id', $eventIds)->orderBy('name')->orderBy('user_id')
            ->get(['event_id', 'user_id', 'name', 'response', 'response_version', 'responded_at']);
        $groups = [];
        foreach ($rows as $row) $groups[$row->event_id][] = [
            'user_id' => $row->user_id, 'name' => $row->name, 'response' => $row->response,
            'response_version' => (int) $row->response_version,
            'responded_at' => $row->responded_at ? CarbonImmutable::parse($row->responded_at, 'UTC')->toIso8601String() : null,
        ];
        return $groups;
    }

    private function present(object $event, CarbonImmutable $now, array $participants = []): array
    {
        $value = (array) $event;
        foreach (['starts_at', 'ends_at', 'cancelled_at'] as $field) {
            if ($value[$field] !== null) $value[$field] = CarbonImmutable::parse($value[$field], 'UTC')->toIso8601String();
        }
        $value['state'] = $this->state($event, $now);
        $value['timezone'] = self::TZ;
        $value['participants'] = $participants;
        $value['remind_24h'] = (bool) $event->remind_24h;
        $value['remind_1h'] = (bool) $event->remind_1h;

        return $value;
    }

    public function list(array $principal, array $dates): array
    {
        $start = CarbonImmutable::parse($dates['from'], self::TZ)->startOfDay();
        $end = CarbonImmutable::parse($dates['to'], self::TZ)->startOfDay()->addDay();
        if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > 62) {
            throw ValidationException::withMessages(['to' => 'El intervalo debe estar ordenado y no superar 62 días.']);
        }
        $events = DB::table('calendar_events')->where('organization_id', $principal['organization_id'])
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())
            ->orderBy('starts_at')->orderBy('id')->limit(501)->get();
        if ($events->count() > 500) throw ValidationException::withMessages(['from' => 'Hay más de 500 eventos. Acota el intervalo.']);
        $now = CarbonImmutable::now('UTC');
        $participants = $this->participants($events->pluck('id')->all(), $principal['organization_id']);

        return ['data' => ['items' => $events->map(fn ($event) => $this->present($event, $now, $participants[$event->id] ?? []))->all(), 'timezone' => self::TZ]];
    }

    public function search(array $principal, string $text, int $page): array
    {
        if ($text === '') throw ValidationException::withMessages(['q' => 'Escribe un texto para buscar.']);
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        $query = DB::table('calendar_events')->where('organization_id', $principal['organization_id'])
            ->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("title_en LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("location LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("location_en LIKE ? ESCAPE '!'", [$term]));
        $total = (clone $query)->count();
        $events = (clone $query)->orderByDesc('starts_at')->orderByDesc('id')->forPage($page, 25)->get();
        $now = CarbonImmutable::now('UTC');

        return ['data' => [
            'items' => $events->map(fn ($event) => $this->present($event, $now))->all(),
            'page' => $page, 'page_size' => 25, 'total' => $total, 'timezone' => self::TZ,
        ]];
    }

    public function show(array $principal, string $id): array
    {
        $event = DB::table('calendar_events')->where('organization_id', $principal['organization_id'])->where('id', $id)->first();
        abort_unless($event, 404);

        $participants = $this->participants([$id], $principal['organization_id']);
        return ['data' => $this->present($event, CarbonImmutable::now('UTC'), $participants[$id] ?? [])];
    }

    public function invitations(array $principal, int $page): array
    {
        $query = DB::table('calendar_participants as p')->join('calendar_events as e', 'e.id', '=', 'p.event_id')
            ->where('p.organization_id', $principal['organization_id'])
            ->where('e.organization_id', $principal['organization_id'])
            ->where('p.user_id', $principal['user_id'])
            ->whereNull('e.cancelled_at')->where('e.starts_at', '>', CarbonImmutable::now('UTC'));
        $total = (clone $query)->count();
        $items = $query->orderBy('e.starts_at')->orderBy('e.id')->forPage($page, 25)
            ->get(['e.id as event_id', 'e.title', 'e.title_en', 'e.starts_at', 'e.ends_at', 'e.location', 'e.location_en', 'p.response', 'p.response_version']);

        return ['data' => ['items' => $items->map(fn ($item) => [
            'event_id' => $item->event_id, 'title' => $item->title, 'title_en' => $item->title_en,
            'starts_at' => CarbonImmutable::parse($item->starts_at, 'UTC')->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($item->ends_at, 'UTC')->toIso8601String(),
            'location' => $item->location, 'location_en' => $item->location_en, 'response' => $item->response,
            'response_version' => (int) $item->response_version,
        ])->all(), 'page' => $page, 'page_size' => 25, 'total' => $total]];
    }

    public function respond(array $principal, string $id, array $data): array
    {
        return DB::transaction(function () use ($principal, $id, $data) {
            $event = DB::table('calendar_events')->where('organization_id', $principal['organization_id'])
                ->where('id', $id)->lockForUpdate()->first();
            abort_unless($event, 404);
            $participant = DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                ->where('event_id', $id)->where('user_id', $principal['user_id'])->lockForUpdate()->first();
            abort_unless($participant, 404);
            abort_unless($event->cancelled_at === null && CarbonImmutable::parse($event->starts_at, 'UTC')->isFuture(), 409, 'La invitación ya no admite respuestas.');
            abort_unless((int) $participant->response_version === $data['version'], 409, 'La respuesta cambió. Recarga antes de guardar.');
            DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                ->where('event_id', $id)->where('user_id', $principal['user_id'])->update([
                    'response' => $data['response'], 'response_version' => $participant->response_version + 1,
                    'responded_at' => now(), 'updated_at' => now(),
                ]);
            if ($data['response'] === 'declined') {
                $this->deliveries->clearPending($id, $principal['user_id'], ['reminder_24h', 'reminder_1h']);
            } else {
                $this->reminder($principal['organization_id'], $id, $principal['user_id'], $event->title,
                    CarbonImmutable::parse($event->starts_at, 'UTC'), (bool) $event->remind_24h, (bool) $event->remind_1h, $event->title_en);
            }
            Outbox::record('calendar.invitation_responded', $principal['organization_id'], $principal['user_id'], $id);

            return $this->show($principal, $id);
        });
    }

    public function save(array $principal, array $data, ?string $id = null): array
    {
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $end = CarbonImmutable::parse($data['ends_at'])->utc();
        if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > 7) {
            throw ValidationException::withMessages(['ends_at' => 'El final debe ser posterior al inicio y estar a menos de siete días.']);
        }

        $participants = null;
        if (array_key_exists('participants', $data)) {
            $settings = $this->settings($principal['organization_id']);
            abort_unless(Access::allows($principal['role'], 'calendar.manage') || in_array($principal['role'], $settings['editor_roles'], true), 403);
            $participants = $this->identity->call('identity', 'POST', 'calendar-participants/resolve',
                ['user_ids' => $data['participants']], $principal)['items'] ?? [];
            if (count($participants) !== count($data['participants'])) throw new \Srd\DependencyFailure;
        }

        return DB::transaction(function () use ($principal, $data, $id, $start, $end, $participants) {
            $this->requireEditor($principal);
            $existing = $id ? DB::table('calendar_events')->where('organization_id', $principal['organization_id'])->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($existing, 404);
                abort_unless($existing->version === $data['version'], 409);
                abort_if($existing->cancelled_at !== null, 409, 'El evento está cancelado.');
            }
            $eventId = $id ?? (string) Str::uuid();
            $values = [
                'type' => $data['type'], 'title' => $data['title'],
                'title_en' => array_key_exists('title_en', $data) ? $data['title_en'] : $existing?->title_en,
                'description' => $data['description'] ?? null,
                'description_en' => array_key_exists('description_en', $data) ? $data['description_en'] : $existing?->description_en,
                'location' => $data['location'] ?? null,
                'location_en' => array_key_exists('location_en', $data) ? $data['location_en'] : $existing?->location_en,
                'starts_at' => $start, 'ends_at' => $end,
                'remind_24h' => array_key_exists('remind_24h', $data) ? (bool) $data['remind_24h'] : (bool) ($existing->remind_24h ?? true),
                'remind_1h' => array_key_exists('remind_1h', $data) ? (bool) $data['remind_1h'] : (bool) ($existing->remind_1h ?? true),
                'version' => $existing ? $existing->version + 1 : 1,
                'updated_by' => $principal['user_id'], 'updated_at' => now(),
            ];
            if ($existing) DB::table('calendar_events')->where('organization_id', $principal['organization_id'])->where('id', $eventId)->update($values);
            else DB::table('calendar_events')->insert($values + [
                'id' => $eventId, 'organization_id' => $principal['organization_id'],
                'created_by' => $principal['user_id'], 'created_at' => now(),
            ]);
            $newIds = [];
            if ($participants !== null) {
                $assigned = DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                    ->where('event_id', $eventId)->pluck('user_id')->all();
                $wanted = array_column($participants, 'user_id');
                $removed = array_diff($assigned, $wanted);
                if ($removed) DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                    ->where('event_id', $eventId)->whereIn('user_id', $removed)->delete();
                $new = array_values(array_filter($participants, fn ($participant) => !in_array($participant['user_id'], $assigned, true)));
                $newIds = array_column($new, 'user_id');
                if ($new) DB::table('calendar_participants')->insert(array_map(fn ($participant) => [
                    'event_id' => $eventId, 'organization_id' => $principal['organization_id'],
                    'user_id' => $participant['user_id'], 'name' => $participant['name'],
                    'created_at' => now(), 'updated_at' => now(),
                ], $new));
            }
            if ($existing) $this->deliveries->clearPending($eventId);
            if ($start->isFuture()) {
                $assignedNow = DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                    ->where('event_id', $eventId)->get(['user_id', 'response']);
                foreach ($assignedNow as $participant) {
                    $kind = !$existing || in_array($participant->user_id, $newIds, true) ? 'invitation' : 'event_changed';
                    $this->deliveries->enqueue($principal['organization_id'], $eventId, $participant->user_id,
                        $kind, $data['title'], (string) $values['version'], CarbonImmutable::now('UTC'), null, $values['title_en']);
                    if ($participant->response !== 'declined') $this->reminder($principal['organization_id'], $eventId,
                        $participant->user_id, $data['title'], $start, $values['remind_24h'], $values['remind_1h'], $values['title_en']);
                }
            }
            Outbox::record($existing ? 'calendar.event_updated' : 'calendar.event_created', $principal['organization_id'], $principal['user_id'], $eventId);

            return $this->show($principal, $eventId);
        });
    }

    public function cancel(array $principal, string $id, int $version): array
    {
        return DB::transaction(function () use ($principal, $id, $version) {
            $this->requireEditor($principal);
            $event = DB::table('calendar_events')->where('organization_id', $principal['organization_id'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($event, 404);
            abort_unless($event->version === $version && $event->cancelled_at === null, 409);
            DB::table('calendar_events')->where('organization_id', $principal['organization_id'])->where('id', $id)->update([
                'cancelled_at' => now(), 'version' => $version + 1,
                'updated_by' => $principal['user_id'], 'updated_at' => now(),
            ]);
            $this->deliveries->clearPending($id);
            $assigned = DB::table('calendar_participants')->where('organization_id', $principal['organization_id'])
                ->where('event_id', $id)->pluck('user_id');
            foreach ($assigned as $userId) $this->deliveries->enqueue($principal['organization_id'], $id, $userId,
                'event_cancelled', $event->title, (string) ($version + 1), CarbonImmutable::now('UTC'), null, $event->title_en);
            Outbox::record('calendar.event_cancelled', $principal['organization_id'], $principal['user_id'], $id);

            return $this->show($principal, $id);
        });
    }
}
