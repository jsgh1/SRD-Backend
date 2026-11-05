<?php

namespace App\Http\Controllers;

use App\Application\CalendarService;
use Illuminate\Http\Request;
use Srd\Access;

final class CalendarController
{
    public function __construct(private CalendarService $calendar) {}

    private function principal(string $permission): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);

        return Access::require($permission);
    }

    public function index(Request $request): array
    {
        $principal = $this->principal('calendar.read');
        $dates = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return $this->calendar->list($principal, $dates);
    }

    public function search(Request $request): array
    {
        $principal = $this->principal('calendar.read');
        $data = $request->validate([
            'q' => 'required|string|max:120',
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);

        return $this->calendar->search($principal, trim($data['q']), (int) ($data['page'] ?? 1));
    }

    public function show(string $id): array
    {
        return $this->calendar->show($this->principal('calendar.read'), $id);
    }

    public function invitations(Request $request): array
    {
        $principal = $this->principal('calendar.read');
        $data = $request->validate(['page' => 'sometimes|integer|min:1']);
        return $this->calendar->invitations($principal, (int) ($data['page'] ?? 1));
    }

    public function respond(Request $request, string $id): array
    {
        $principal = $this->principal('calendar.read');
        $data = $request->validate([
            'response' => 'required|in:accepted,declined',
            'version' => 'required|integer|min:1',
        ]);
        return $this->calendar->respond($principal, $id, $data);
    }

    private function input(Request $request, bool $update): array
    {
        return $request->validate([
            'type' => 'required|in:meeting,appointment,activity,important_date',
            'title' => 'required|string|min:2|max:160',
            'title_en' => 'sometimes|nullable|string|min:2|max:160',
            'description' => 'nullable|string|max:4000',
            'description_en' => 'sometimes|nullable|string|max:4000',
            'location' => 'nullable|string|max:160',
            'location_en' => 'sometimes|nullable|string|max:160',
            'starts_at' => 'required|date_format:Y-m-d\TH:i:sP',
            'ends_at' => 'required|date_format:Y-m-d\TH:i:sP',
            'participants' => 'sometimes|array|list|max:50',
            'participants.*' => 'required|uuid|distinct:strict',
            'remind_24h' => 'sometimes|boolean',
            'remind_1h' => 'sometimes|boolean',
            ...($update ? ['version' => 'required|integer|min:1'] : []),
        ]);
    }

    public function create(Request $request): array
    {
        return $this->calendar->save($this->principal('calendar.read'), $this->input($request, false));
    }

    public function update(Request $request, string $id): array
    {
        return $this->calendar->save($this->principal('calendar.read'), $this->input($request, true), $id);
    }

    public function cancel(Request $request, string $id): array
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return $this->calendar->cancel($this->principal('calendar.read'), $id, $data['version']);
    }

    public function settings(): array
    {
        return $this->calendar->readSettings($this->principal('calendar.read'));
    }

    public function updateSettings(Request $request): array
    {
        $principal = $this->principal('calendar.manage');
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'editor_roles' => 'present|array|list|max:4',
            'editor_roles.*' => 'required|in:registrar,treasurer,auditor,viewer|distinct:strict',
        ]);

        return $this->calendar->updateSettings($principal, $data);
    }
}
