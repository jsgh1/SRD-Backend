<?php

namespace App\Http\Controllers;

use App\Application\InventoryService;
use Illuminate\Http\Request;
use Srd\Access;

final class InventoryController
{
    public function __construct(private InventoryService $inventory) {}

    private function principal(string $permission): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require($permission);
        if ($permission === 'inventory.write') {
            abort_unless(is_string($principal['name'] ?? null) && trim($principal['name']) !== '' && strlen($principal['name']) <= 120, 403);
        }
        return $principal;
    }

    public function index(Request $request): array
    {
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1|max:100000', 'type' => 'sometimes|in:real_estate,movable',
            'status' => 'sometimes|in:active,retired', 'q' => 'sometimes|string|max:120',
        ]);
        return $this->inventory->index($this->principal('inventory.read'), $data);
    }

    public function export(Request $request): array
    {
        return $this->exportRequest($request, false);
    }

    public function exportPdf(Request $request): array
    {
        return $this->exportRequest($request, true);
    }

    private function exportRequest(Request $request, bool $pdf): array
    {
        $principal = $this->principal('inventory.export');
        $data = $request->validate([
            'type' => 'sometimes|in:real_estate,movable',
            'status' => 'sometimes|in:active,retired',
            'q' => 'sometimes|string|max:120',
            'filename' => 'sometimes|nullable|string|max:100',
            'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        return $pdf ? $this->inventory->exportPdf($principal, $data) : $this->inventory->export($principal, $data);
    }

    public function store(Request $request): array
    {
        return $this->inventory->store($this->principal('inventory.write'), $request->validate([
            'code' => 'required|string|min:2|max:40|regex:/^[A-Za-z0-9._-]+$/',
            'type' => 'required|in:real_estate,movable', 'name' => 'required|string|min:2|max:160',
            'name_en' => 'sometimes|nullable|string|min:2|max:160',
            'category' => 'nullable|string|max:80', 'unit' => 'nullable|string|max:40',
            'category_en' => 'sometimes|nullable|string|max:80', 'unit_en' => 'sometimes|nullable|string|max:40',
            'description' => 'nullable|string|max:5000', 'location' => 'required|string|min:2|max:180',
            'description_en' => 'sometimes|nullable|string|max:5000', 'location_en' => 'sometimes|nullable|string|min:2|max:180',
            'condition' => 'required|string|min:2|max:80', 'responsible_name' => 'nullable|string|max:120',
            'condition_en' => 'sometimes|nullable|string|min:2|max:80',
            'quantity' => 'required|integer|min:0|max:1000000000', 'idempotency_key' => 'required|uuid',
        ]));
    }

    public function show(Request $request, string $id): array
    {
        $principal = $this->principal('inventory.read');
        $data = $this->movementFilters($request);
        return $this->inventory->show($principal, $id, (int) ($data['movement_page'] ?? 1), $data);
    }

    public function exportMovements(Request $request, string $id): array
    {
        return $this->movementExportRequest($request, $id, false);
    }

    public function exportMovementsPdf(Request $request, string $id): array
    {
        return $this->movementExportRequest($request, $id, true);
    }

    private function movementExportRequest(Request $request, string $id, bool $pdf): array
    {
        $principal = $this->principal('inventory.export');
        $data = $this->movementFilters($request) + $request->validate([
            'filename' => 'sometimes|nullable|string|max:100', 'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        return $this->inventory->exportMovements($principal, $id, $data, $pdf);
    }

    private function movementFilters(Request $request): array
    {
        $data = $request->validate([
            'movement_page' => 'sometimes|integer|min:1|max:100000',
            'movement_type' => 'sometimes|in:opening,in,out,adjust,retire',
            'movement_q' => 'sometimes|string|max:120',
            'movement_from' => 'sometimes|date_format:Y-m-d',
            'movement_to' => 'sometimes|date_format:Y-m-d',
        ]);
        if (isset($data['movement_from'], $data['movement_to']) && $data['movement_from'] > $data['movement_to']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['movement_to' => 'La fecha final no puede ser anterior a la inicial.']);
        }
        return $data;
    }

    public function update(Request $request, string $id): array
    {
        return $this->inventory->update($this->principal('inventory.write'), $id, $request->validate([
            'version' => 'required|integer|min:1', 'name' => 'required|string|min:2|max:160',
            'name_en' => 'sometimes|nullable|string|min:2|max:160',
            'category' => 'nullable|string|max:80', 'unit' => 'nullable|string|max:40',
            'category_en' => 'sometimes|nullable|string|max:80', 'unit_en' => 'sometimes|nullable|string|max:40',
            'description' => 'nullable|string|max:5000', 'location' => 'required|string|min:2|max:180',
            'description_en' => 'sometimes|nullable|string|max:5000', 'location_en' => 'sometimes|nullable|string|min:2|max:180',
            'condition' => 'required|string|min:2|max:80', 'responsible_name' => 'nullable|string|max:120',
            'condition_en' => 'sometimes|nullable|string|min:2|max:80',
        ]));
    }

    public function movement(Request $request, string $id): array
    {
        return $this->inventory->movement($this->principal('inventory.write'), $id, $request->validate([
            'type' => 'required|in:in,out,adjust', 'quantity' => 'required|integer|min:0|max:1000000000',
            'reason' => 'required|string|min:5|max:500', 'reason_en' => 'sometimes|nullable|string|min:5|max:500',
            'idempotency_key' => 'required|uuid',
        ]));
    }

    public function retire(Request $request, string $id): array
    {
        return $this->inventory->retire($this->principal('inventory.write'), $id, $request->validate([
            'version' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:500',
            'reason_en' => 'sometimes|nullable|string|min:5|max:500',
            'idempotency_key' => 'required|uuid',
        ]));
    }
}
