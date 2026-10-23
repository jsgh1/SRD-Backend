<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\Access;
use Srd\Outbox;

final class NotificationController
{
    private function principal(): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);
        return Access::require('notifications.read');
    }

    public function deliver(Request $request): array
    {
        $issuer = $request->attributes->get('issuer');
        abort_unless(in_array($issuer, ['calendar', 'chat', 'records'], true), 403);
        $data = $request->validate($issuer === 'calendar' ? [
            'organization_id' => 'required|uuid', 'user_id' => 'required|uuid',
            'event_id' => 'required|uuid', 'title' => 'required|string|min:2|max:160',
            'title_en' => 'sometimes|nullable|string|min:2|max:160',
            'kind' => 'required|in:invitation,event_changed,event_cancelled,reminder_24h,reminder_1h',
            'delivery_key' => 'required|regex:/^[a-f0-9]{64}$/',
        ] : ($issuer === 'chat' ? [
            'organization_id' => 'required|uuid', 'user_id' => 'required|uuid',
            'conversation_id' => 'required|uuid', 'kind' => 'required|in:chat_message,chat_call,chat_missed',
            'sender_id' => 'sometimes|nullable|uuid', 'sender_name' => 'sometimes|nullable|string|max:120',
            'message_id' => 'sometimes|nullable|uuid',
            'delivery_key' => 'required|regex:/^[a-f0-9]{64}$/',
        ] : [
            'organization_id' => 'required|uuid', 'user_id' => 'required|uuid',
            'export_job_id' => 'required|uuid', 'kind' => 'required|in:export_ready',
            'delivery_key' => 'required|regex:/^[a-f0-9]{64}$/',
        ]));
        $data['event_id'] = $issuer === 'calendar' ? $data['event_id'] : null;
        $data['conversation_id'] = $issuer === 'chat' ? $data['conversation_id'] : null;
        $data['export_job_id'] = $issuer === 'records' ? $data['export_job_id'] : null;
        $name = trim((string) ($data['sender_name'] ?? ''));
        $data['sender_name'] = $issuer === 'chat' && $name !== '' ? $name : null;
        $data['sender_id'] = $issuer === 'chat' ? ($data['sender_id'] ?? null) : null;
        $data['message_id'] = $issuer === 'chat' && $data['kind'] === 'chat_message' ? ($data['message_id'] ?? null) : null;
        $chatTitles = [
            'chat_message' => ['Nuevo mensaje', 'New message'],
            'chat_call' => ['Llamada entrante', 'Incoming call'],
            'chat_missed' => ['Llamada perdida', 'Missed call'],
        ];
        $data['title'] = $issuer === 'chat' ? $chatTitles[$data['kind']][0].($name !== '' ? ' de '.$name : '')
            : ($issuer === 'records' ? 'Tu exportación de personas está lista' : $data['title']);
        $data['title_en'] = $issuer === 'chat' ? $chatTitles[$data['kind']][1].($name !== '' ? ' from '.$name : '')
            : ($issuer === 'records' ? 'Your people export is ready' : ($data['title_en'] ?? null));
        $principal = $request->attributes->get('principal', []);
        abort_unless(($principal['organization_id'] ?? null) === $data['organization_id'], 403);
        return DB::transaction(function () use ($data) {
            $id = (string) Str::uuid();
            $preferences = DB::table('notification_preferences')
                ->where('organization_id', $data['organization_id'])
                ->where('user_id', $data['user_id'])->first();
            $suppressed = ($data['kind'] === 'event_changed' && $preferences && ! $preferences->event_changes)
                || (in_array($data['kind'], ['reminder_24h', 'reminder_1h'], true) && $preferences && ! $preferences->reminders)
                || ($data['kind'] === 'chat_message' && $preferences && ! $preferences->chat_messages);
            $inserted = DB::table('notifications')->insertOrIgnore($data + [
                'id' => $id, 'dismissed_at' => $suppressed ? now() : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted) Outbox::record($suppressed ? 'notifications.suppressed' : 'notifications.created', $data['organization_id'], null, $id);
            else $id = DB::table('notifications')->where('delivery_key', $data['delivery_key'])
                ->where('organization_id', $data['organization_id'])->value('id');
            abort_unless($id, 409);
            return ['data' => ['id' => $id]];
        });
    }

    public function preferences(): array
    {
        $principal = $this->principal();
        $row = DB::table('notification_preferences')->where('organization_id', $principal['organization_id'])
            ->where('user_id', $principal['user_id'])->first();
        return ['data' => [
            'event_changes' => $row ? (bool) $row->event_changes : true,
            'reminders' => $row ? (bool) $row->reminders : true,
            'chat_messages' => $row ? (bool) $row->chat_messages : true,
            'quiet_start' => $row?->quiet_start,
            'quiet_end' => $row?->quiet_end,
        ]];
    }

    public function updatePreferences(Request $request): array
    {
        $principal = $this->principal();
        $data = $request->validate([
            'event_changes' => 'required|boolean', 'reminders' => 'required|boolean',
            'chat_messages' => 'sometimes|boolean',
            'quiet_start' => ['sometimes', 'nullable', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'quiet_end' => ['sometimes', 'nullable', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'],
        ]);
        $values = ['event_changes' => (bool) $data['event_changes'], 'reminders' => (bool) $data['reminders']];
        if (array_key_exists('chat_messages', $data)) $values['chat_messages'] = (bool) $data['chat_messages'];
        if (array_key_exists('quiet_start', $data) || array_key_exists('quiet_end', $data)) {
            if (!array_key_exists('quiet_start', $data) || !array_key_exists('quiet_end', $data)
                || (($data['quiet_start'] === null) !== ($data['quiet_end'] === null))) {
                throw ValidationException::withMessages(['quiet_start' => 'Indica inicio y fin, o desactiva ambos.']);
            }
            if ($data['quiet_start'] !== null && $data['quiet_start'] === $data['quiet_end']) {
                throw ValidationException::withMessages(['quiet_end' => 'El fin debe ser diferente del inicio.']);
            }
            $values['quiet_start'] = $data['quiet_start'];
            $values['quiet_end'] = $data['quiet_end'];
        }
        DB::transaction(function () use ($principal, $values) {
            DB::table('notification_preferences')->upsert([[
                'organization_id' => $principal['organization_id'], 'user_id' => $principal['user_id'],
                ...$values,
                'updated_at' => now(), 'created_at' => now(),
            ]], ['organization_id', 'user_id'], [...array_keys($values), 'updated_at']);
            Outbox::record('notifications.preferences_updated', $principal['organization_id'], $principal['user_id'], $principal['user_id']);
        });
        return $this->preferences();
    }

    public function index(Request $request): array
    {
        $principal = $this->principal();
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = (int) ($data['page'] ?? 1);
        $base = DB::table('notifications')->where('organization_id', $principal['organization_id'])
            ->where('user_id', $principal['user_id'])->whereNull('dismissed_at');
        $total = (clone $base)->count();
        $unread = (clone $base)->whereNull('read_at')->count();
        $items = $base->orderByDesc('created_at')->orderByDesc('id')->forPage($page, 25)
            ->get(['id', 'event_id', 'conversation_id', 'export_job_id', 'sender_id', 'sender_name', 'message_id', 'kind', 'title', 'title_en', 'created_at', 'read_at']);
        return ['data' => ['items' => $items->map(fn ($item) => [
            'id' => $item->id, 'event_id' => $item->event_id, 'conversation_id' => $item->conversation_id,
            'export_job_id' => $item->export_job_id, 'kind' => $item->kind,
            'sender_id' => $item->sender_id, 'sender_name' => $item->sender_name, 'message_id' => $item->message_id,
            'title' => $item->title, 'title_en' => $item->title_en,
            'created_at' => CarbonImmutable::parse($item->created_at, 'UTC')->toIso8601String(),
            'read_at' => $item->read_at ? CarbonImmutable::parse($item->read_at, 'UTC')->toIso8601String() : null,
        ])->all(), 'page' => $page, 'page_size' => 25,
            'total' => $total, 'unread' => $unread]];
    }

    public function read(string $id): array
    {
        return $this->change($id, 'read_at', 'notifications.read');
    }

    public function dismiss(string $id): array
    {
        return $this->change($id, 'dismissed_at', 'notifications.dismissed');
    }

    private function change(string $id, string $column, string $action): array
    {
        $principal = $this->principal();
        return DB::transaction(function () use ($principal, $id, $column, $action) {
            $query = DB::table('notifications')->where('id', $id)
                ->where('organization_id', $principal['organization_id'])
                ->where('user_id', $principal['user_id'])->whereNull('dismissed_at');
            $item = (clone $query)->lockForUpdate()->first();
            abort_unless($item, 404);
            if ($item->$column === null) {
                $query->update([$column => now(), 'updated_at' => now()]);
                Outbox::record($action, $principal['organization_id'], $principal['user_id'], $id);
            }
            return ['data' => ['id' => $id]];
        });
    }
}
