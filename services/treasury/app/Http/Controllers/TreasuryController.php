<?php

namespace App\Http\Controllers;

use App\Application\LedgerService;
use Illuminate\Http\Request;
use Srd\Access;

final class TreasuryController
{
    public function __construct(private LedgerService $ledger) {}

    private function principal(string $permission): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require($permission);
        if ($permission === 'treasury.write') {
            abort_unless(is_string($principal['name'] ?? null) && strlen($principal['name']) <= 120
                && trim($principal['name']) !== '', 403);
        }
        return $principal;
    }

    public function index(Request $request): array
    {
        $principal = $this->principal('treasury.read');
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1|max:100000',
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d',
            'q' => 'sometimes|string|max:120',
        ]);
        return $this->ledger->summary($principal, (int) ($data['page'] ?? 1), $data['from'] ?? null, $data['to'] ?? null, $data['q'] ?? null);
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
        $principal = $this->principal('treasury.export');
        $data = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d',
            'q' => 'sometimes|string|max:120',
            'filename' => 'sometimes|nullable|string|max:100',
            'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        $method = $pdf ? 'exportPdf' : 'export';
        return $this->ledger->$method($principal, $data['from'] ?? null, $data['to'] ?? null, $data['q'] ?? null,
            $data['filename'] ?? null, (bool) ($data['confirm_filename'] ?? false), $data['lang'] ?? 'es');
    }

    private function movementData(Request $request, bool $opening): array
    {
        return $request->validate([
            ...($opening ? [] : ['kind' => 'required|in:income,expense']),
            'amount' => 'required|string|max:18',
            'effective_date' => 'required|date_format:Y-m-d',
            'concept' => 'required|string|min:3|max:500',
            'support_note' => 'nullable|string|max:500',
            'idempotency_key' => 'required|uuid',
        ]);
    }

    public function opening(Request $request): array
    {
        return $this->ledger->post($this->principal('treasury.write'), $this->movementData($request, true), true);
    }

    public function store(Request $request): array
    {
        return $this->ledger->post($this->principal('treasury.write'), $this->movementData($request, false));
    }

    public function show(string $id): array
    {
        return $this->ledger->show($this->principal('treasury.read'), $id);
    }

    public function receiptPdf(Request $request, string $id): array
    {
        $principal = $this->principal('treasury.export');
        $data = $request->validate([
            'filename' => 'sometimes|nullable|string|max:100',
            'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        return $this->ledger->exportReceiptPdf($principal, $id, $data['filename'] ?? null,
            (bool) ($data['confirm_filename'] ?? false), $data['lang'] ?? 'es');
    }

    public function reverse(Request $request, string $id): array
    {
        $principal = $this->principal('treasury.write');
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'idempotency_key' => 'required|uuid',
        ]);
        return $this->ledger->reverse($principal, $id, $data);
    }

    public function receiptXlsx(Request $request, string $id): array
    {
        $principal = $this->principal('treasury.export');
        $data = $request->validate([
            'filename' => 'sometimes|nullable|string|max:100',
            'confirm_filename' => 'sometimes|boolean',
            'lang' => 'sometimes|in:es,en',
        ]);
        return $this->ledger->exportReceiptXlsx($principal, $id, $data['filename'] ?? null,
            (bool) ($data['confirm_filename'] ?? false), $data['lang'] ?? 'es');
    }
}
