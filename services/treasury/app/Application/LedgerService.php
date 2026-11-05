<?php

namespace App\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;
use Srd\ExportFilename;

final class LedgerService
{
    private function cents(string $amount, bool $allowZero = false): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D', $amount, $match)) {
            throw ValidationException::withMessages(['amount' => 'Usa un importe COP positivo con máximo dos decimales.']);
        }
        $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
        if (!$allowZero && $cents === 0) throw ValidationException::withMessages(['amount' => 'El importe debe ser mayor que cero.']);
        return $cents;
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function date(string $value): string
    {
        $today = CarbonImmutable::today('America/Bogota')->toDateString();
        if ($value > $today) throw ValidationException::withMessages(['effective_date' => 'La fecha no puede ser futura.']);
        return $value;
    }

    private function account(string $org): object
    {
        DB::table('treasury_accounts')->insertOrIgnore([
            'organization_id' => $org, 'balance_cents' => 0, 'next_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('treasury_accounts')->where('organization_id', $org)->lockForUpdate()->first();
    }

    private function fingerprint(array $principal, string $kind, int $cents, string $date, string $concept, ?string $support, ?string $reversesId): string
    {
        return hash('sha256', json_encode([$principal['user_id'], $kind, $cents, $date, $concept, $support, $reversesId], JSON_THROW_ON_ERROR));
    }

    private function receipt(object $row): array
    {
        return [
            'id' => $row->id, 'number' => (int) $row->number,
            'receipt' => 'TES-'.str_pad((string) $row->number, 6, '0', STR_PAD_LEFT),
            'kind' => $row->kind, 'sign' => (int) $row->sign,
            'amount' => $this->money((int) $row->amount_cents),
            'balance_after' => $this->money((int) $row->balance_after_cents),
            'effective_date' => $row->effective_date, 'concept' => $row->concept,
            'support_note' => $row->support_note, 'actor_id' => $row->actor_id,
            'actor_name' => $row->actor_name, 'reverses_id' => $row->reverses_id,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
        ];
    }

    private function previous(string $org, string $key, string $fingerprint): ?array
    {
        $row = DB::table('treasury_movements')->where('organization_id', $org)
            ->where('idempotency_key', $key)->first();
        if (!$row) return null;
        abort_unless(hash_equals($row->payload_hash, $fingerprint), 409, 'La clave ya se usó con otros datos.');
        return ['data' => $this->receipt($row)];
    }

    private function append(object $account, array $principal, string $kind, int $amount, int $sign,
        string $date, string $concept, ?string $support, ?string $reversesId, string $key, string $fingerprint): array
    {
        $balance = (int) $account->balance_cents + $sign * $amount;
        if ($balance < 0) {
            Outbox::record($kind === 'reversal' ? 'treasury.reversal_rejected' : 'treasury.expense_rejected',
                $principal['organization_id'], $principal['user_id'], $reversesId, 'rejected');
            return ['rejected' => true];
        }
        $id = (string) Str::uuid();
        $number = (int) $account->next_number;
        DB::table('treasury_movements')->insert([
            'id' => $id, 'organization_id' => $principal['organization_id'], 'number' => $number,
            'kind' => $kind, 'sign' => $sign, 'amount_cents' => $amount,
            'balance_after_cents' => $balance, 'effective_date' => $date,
            'concept' => $concept, 'support_note' => $support,
            'actor_id' => $principal['user_id'], 'actor_name' => $principal['name'],
            'reverses_id' => $reversesId, 'idempotency_key' => $key, 'payload_hash' => $fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_accounts')->where('organization_id', $principal['organization_id'])->update([
            'balance_cents' => $balance, 'next_number' => $number + 1,
            'opened_at' => $kind === 'opening' ? now() : $account->opened_at,
            'updated_at' => now(),
        ]);
        Outbox::record('treasury.'.$kind.'_recorded', $principal['organization_id'], $principal['user_id'], $id);
        return ['data' => $this->receipt(DB::table('treasury_movements')->where('id', $id)->first())];
    }

    public function post(array $principal, array $data, bool $opening = false): array
    {
        $kind = $opening ? 'opening' : $data['kind'];
        $amount = $this->cents($data['amount'], $opening);
        $date = $this->date($data['effective_date']);
        $concept = trim($data['concept']);
        $support = isset($data['support_note']) ? trim($data['support_note']) : null;
        $fingerprint = $this->fingerprint($principal, $kind, $amount, $date, $concept, $support, null);
        $result = DB::transaction(function () use ($principal, $data, $opening, $kind, $amount, $date, $concept, $support, $fingerprint) {
            $account = $this->account($principal['organization_id']);
            $previous = $this->previous($principal['organization_id'], $data['idempotency_key'], $fingerprint);
            if ($previous) return $previous;
            abort_unless($opening ? $account->opened_at === null : $account->opened_at !== null, 409,
                $opening ? 'La cuenta ya tiene apertura.' : 'Primero registra la apertura de la cuenta.');
            return $this->append($account, $principal, $kind, $amount, $kind === 'expense' ? -1 : 1,
                $date, $concept, $support, null, $data['idempotency_key'], $fingerprint);
        }, 3);
        abort_if(isset($result['rejected']), 422, 'El egreso supera el saldo disponible.');
        return $result;
    }

    public function reverse(array $principal, string $id, array $data): array
    {
        $concept = trim($data['reason']);
        $result = DB::transaction(function () use ($principal, $id, $data, $concept) {
            $account = $this->account($principal['organization_id']);
            $original = DB::table('treasury_movements')->where('id', $id)
                ->where('organization_id', $principal['organization_id'])->first();
            abort_unless($original, 404);
            abort_unless(in_array($original->kind, ['income', 'expense'], true), 409, 'Este asiento no admite reverso.');
            $existing = DB::table('treasury_movements')->where('organization_id', $principal['organization_id'])
                ->where('idempotency_key', $data['idempotency_key'])->first();
            $date = $existing?->effective_date ?? CarbonImmutable::today('America/Bogota')->toDateString();
            $fingerprint = $this->fingerprint($principal, 'reversal', (int) $original->amount_cents,
                $date, $concept, null, $id);
            $previous = $this->previous($principal['organization_id'], $data['idempotency_key'], $fingerprint);
            if ($previous) return $previous;
            abort_if(DB::table('treasury_movements')->where('reverses_id', $id)->exists(), 409,
                'Este asiento ya tiene reverso.');
            return $this->append($account, $principal, 'reversal', (int) $original->amount_cents,
                - (int) $original->sign, $date, $concept, null, $id, $data['idempotency_key'], $fingerprint);
        }, 3);
        abort_if(isset($result['rejected']), 422, 'El reverso dejaría un saldo negativo.');
        return $result;
    }

    public function show(array $principal, string $id): array
    {
        $row = DB::table('treasury_movements')->where('id', $id)
            ->where('organization_id', $principal['organization_id'])->first();
        abort_unless($row, 404);
        $receipt = $this->receipt($row);
        $receipt['reversed_by_id'] = DB::table('treasury_movements')->where('reverses_id', $id)->value('id');
        return ['data' => $receipt];
    }

    public function exportReceiptPdf(array $principal, string $id, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $receipt = $this->show($principal, $id)['data'];
        $filename = trim($requestedFilename ?? '') === ''
            ? 'comprobante_'.$receipt['receipt'].'.pdf'
            : ExportFilename::pdf('comprobante', $requestedFilename, $confirmed);
        Outbox::record('treasury.receipt_export', $principal['organization_id'], $principal['user_id'], $id);
        return ['data' => ['filename' => $filename, 'receipt' => $receipt]];
    }

    public function exportReceiptXlsx(array $principal, string $id, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $receipt = $this->show($principal, $id)['data'];
        $filename = trim($requestedFilename ?? '') === ''
            ? 'comprobante_'.$receipt['receipt'].'.xlsx'
            : substr(ExportFilename::pdf('comprobante', $requestedFilename, $confirmed), 0, -4).'.xlsx';
        $receipt['organization_id'] = $principal['organization_id'];
        $content = base64_encode(TreasuryWorkbook::receipt($receipt, $language));
        Outbox::record('treasury.receipt_export', $principal['organization_id'], $principal['user_id'], $id);
        return ['data' => ['filename' => $filename, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content, 'count' => 1]];
    }

    public function summary(array $principal, int $page, ?string $from, ?string $to, ?string $text = null): array
    {
        abort_if($page > 100000, 422);
        $org = $principal['organization_id'];
        $account = DB::table('treasury_accounts')->where('organization_id', $org)->first();
        $base = $this->filteredMovements($principal, $from, $to, $text);
        $total = (clone $base)->count();
        $rows = (clone $base)->orderByDesc('effective_date')->orderByDesc('number')->forPage($page, 25)->get();
        $reversed = DB::table('treasury_movements')->where('organization_id', $org)
            ->whereIn('reverses_id', $rows->pluck('id')->all())->pluck('id', 'reverses_id');
        $items = $rows->map(function ($row) use ($reversed) {
            $receipt = $this->receipt($row);
            $receipt['reversed_by_id'] = $reversed[$row->id] ?? null;
            return $receipt;
        })->all();
        $sum = DB::table('treasury_movements')->where('organization_id', $org)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'opening' THEN amount_cents ELSE 0 END),0) AS opening")
            ->selectRaw("COALESCE(SUM(CASE WHEN sign = 1 AND kind <> 'opening' THEN amount_cents ELSE 0 END),0) AS credits")
            ->selectRaw("COALESCE(SUM(CASE WHEN sign = -1 THEN amount_cents ELSE 0 END),0) AS debits")->first();
        return ['data' => [
            'opened' => $account?->opened_at !== null,
            'balance' => $this->money((int) ($account?->balance_cents ?? 0)),
            'opening' => $this->money((int) $sum->opening),
            'credits' => $this->money((int) $sum->credits),
            'debits' => $this->money((int) $sum->debits),
            'items' => $items, 'page' => $page, 'page_size' => 25, 'total' => $total,
        ]];
    }

    private function filteredMovements(array $principal, ?string $from, ?string $to, ?string $text)
    {
        if ($from && $to) abort_if($from > $to, 422);
        $base = DB::table('treasury_movements')->where('organization_id', $principal['organization_id']);
        if ($from) $base->where('effective_date', '>=', $from);
        if ($to) $base->where('effective_date', '<=', $to);
        if ($text !== null && trim($text) !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($text)).'%';
            $receiptNumber = preg_match('/^TES-([0-9]{1,12})$/iD', trim($text), $match) ? (int) $match[1] : null;
            $base->where(function ($query) use ($term, $receiptNumber) {
                $query->whereRaw("concept LIKE ? ESCAPE '!'", [$term]);
                if ($receiptNumber !== null) $query->orWhere('number', $receiptNumber);
            });
        }
        return $base;
    }

    public function export(array $principal, ?string $from, ?string $to, ?string $text, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $filename = ExportFilename::xlsx('tesoreria', $requestedFilename, $confirmed);
        $rows = $this->exportRows($principal, $from, $to, $text);
        $content = base64_encode(TreasuryWorkbook::create($rows, $language));
        Outbox::record('treasury.export', $principal['organization_id'], $principal['user_id'], null);
        return ['data' => [
            'filename' => $filename,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $content, 'count' => $rows->count(),
        ]];
    }

    public function exportPdf(array $principal, ?string $from, ?string $to, ?string $text, ?string $requestedFilename, bool $confirmed, string $language = 'es'): array
    {
        $filename = ExportFilename::pdf('tesoreria', $requestedFilename, $confirmed);
        $rows = $this->exportRows($principal, $from, $to, $text);
        $table = TreasuryWorkbook::table($rows, $language);
        Outbox::record('treasury.export', $principal['organization_id'], $principal['user_id'], null);
        return ['data' => ['filename' => $filename, 'date' => now('America/Bogota')->toDateString(),
            'count' => $rows->count()] + $table];
    }

    private function exportRows(array $principal, ?string $from, ?string $to, ?string $text)
    {
        $rows = $this->filteredMovements($principal, $from, $to, $text)
            ->orderByDesc('effective_date')->orderByDesc('number')->limit(2001)->get();
        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['export' => 'La consulta supera 2000 asientos. Acota los filtros antes de exportar.']);
        }
        return $rows;
    }
}
