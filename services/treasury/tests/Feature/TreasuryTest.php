<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class TreasuryTest extends TestCase
{
    use RefreshDatabase, SignedRequests;

    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private function principal(string $role = 'treasurer', string $org = self::ORG): array
    {
        return ['organization_id' => $org, 'user_id' => '22222222-2222-4222-8222-222222222222',
            'role' => $role, 'name' => 'Tesorera de prueba'];
    }

    private function movement(string $amount, string $key, string $kind = 'income'): array
    {
        return ['amount' => $amount, 'kind' => $kind, 'effective_date' => '2026-09-23',
            'concept' => 'Movimiento de prueba', 'idempotency_key' => $key];
    }

    public function test_opening_movements_idempotency_and_reversals_preserve_ledger(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $p = $this->principal();
            $opening = $this->movement('100.00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            unset($opening['kind']);
            $this->internal('GET', 'treasury', [], $p)->assertJsonPath('data.opened', false);
            $first = $this->internal('POST', 'treasury/opening', $opening, $p)->assertOk()->json('data');
            $this->assertSame('TES-000001', $first['receipt']);
            $this->internal('POST', 'treasury/opening', $opening, $p)->assertJsonPath('data.id', $first['id']);
            $this->internal('POST', 'treasury/opening', array_replace($opening, ['idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']), $p)->assertStatus(409);
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_balance_duplicate_keys_insufficient_funds_and_reversal(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $p = $this->principal();
            $opening = $this->movement('100.00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            unset($opening['kind']);
            $this->internal('POST', 'treasury/opening', $opening, $p)->assertOk();
            $income = $this->movement('25.50', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
            $credit = $this->internal('POST', 'treasury/movements', $income, $p)->assertOk()->json('data');
            $this->internal('POST', 'treasury/movements', $income, $p)->assertJsonPath('data.id', $credit['id']);
            $this->internal('POST', 'treasury/movements', array_replace($income, ['amount' => '26.00']), $p)->assertStatus(409);
            $expense = $this->movement('20.25', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'expense');
            $debit = $this->internal('POST', 'treasury/movements', $expense, $p)->assertOk()->json('data');
            $this->assertSame('105.25', $debit['balance_after']);
            $this->internal('POST', 'treasury/movements', $this->movement('106.00', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'expense'), $p)->assertUnprocessable();
            $this->internal('GET', 'treasury', [], $p)->assertJsonPath('data.balance', '105.25')
                ->assertJsonPath('data.opening', '100.00')->assertJsonPath('data.total', 3);
            $reversal = ['reason' => 'Corrección del egreso de prueba', 'idempotency_key' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'];
            $undo = $this->internal('POST', 'treasury/movements/'.$debit['id'].'/reverse', $reversal, $p)
                ->assertOk()->json('data');
            $this->assertSame('125.50', $undo['balance_after']);
            $this->assertSame($debit['id'], $undo['reverses_id']);
            $this->internal('POST', 'treasury/movements/'.$debit['id'].'/reverse', $reversal, $p)
                ->assertJsonPath('data.id', $undo['id']);
            $this->internal('POST', 'treasury/movements/'.$debit['id'].'/reverse', array_replace($reversal, ['idempotency_key' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']), $p)->assertStatus(409);
            $this->internal('POST', 'treasury/movements/'.$undo['id'].'/reverse', $reversal, $p)->assertStatus(409);
            $this->internal('GET', 'treasury/movements/'.$debit['id'], [], $p)->assertJsonPath('data.reversed_by_id', $undo['id']);
            $this->assertSame(12550, (int) DB::table('treasury_accounts')->value('balance_cents'));
            $this->assertSame(12550, (int) DB::table('treasury_movements')->selectRaw('SUM(sign * amount_cents) AS total')->value('total'));
            $this->assertDatabaseCount('treasury_movements', 4);
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_reversing_income_cannot_make_balance_negative_and_records_rejection(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $p = $this->principal();
            $opening = $this->movement('0.00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            unset($opening['kind']);
            $this->internal('POST', 'treasury/opening', $opening, $p)->assertOk();
            $credit = $this->internal('POST', 'treasury/movements', $this->movement('50.00', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), $p)->assertOk()->json('data');
            $this->internal('POST', 'treasury/movements', $this->movement('40.00', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'expense'), $p)->assertOk();
            $this->internal('POST', 'treasury/movements/'.$credit['id'].'/reverse',
                ['reason' => 'Ingreso duplicado por error', 'idempotency_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'], $p)->assertUnprocessable();
            $this->internal('GET', 'treasury', [], $p)->assertJsonPath('data.balance', '10.00');
            $this->assertDatabaseHas('outbox_events', ['action' => 'treasury.reversal_rejected', 'result' => 'rejected']);
            $this->assertDatabaseCount('treasury_movements', 3);
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_roles_tenants_and_unopened_accounts_are_enforced(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $p = $this->principal();
            foreach (['viewer', 'registrar', 'auditor'] as $role) {
                $this->internal('GET', 'treasury', [], $this->principal($role))->assertForbidden();
            }
            $this->internal('POST', 'treasury/movements', $this->movement('1.00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), $p)->assertStatus(409);
            $opening = $this->movement('10.00', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
            unset($opening['kind']);
            $this->internal('POST', 'treasury/opening', $opening, $p)->assertOk();
            $this->internal('GET', 'treasury', [], $this->principal(org: self::OTHER))->assertJsonPath('data.opened', false);
            $movement = $this->internal('POST', 'treasury/movements', $this->movement('1.00', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'), $p)->assertOk()->json('data');
            $this->internal('GET', 'treasury/movements/'.$movement['id'], [], $this->principal(org: self::OTHER))->assertNotFound();
            $this->internal('POST', 'treasury/movements/'.$movement['id'].'/reverse',
                ['reason' => 'Prueba de reverso ajeno', 'idempotency_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'], $this->principal(org: self::OTHER))->assertNotFound();
            $this->internal('GET', 'treasury', [], $p, 'calendar')->assertUnauthorized();
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_searches_literal_concepts_and_exact_receipts_with_tenant_and_role_isolation(): void
    {
        CarbonImmutable::setTestNow('2026-09-23T12:00:00Z');
        try {
            $p = $this->principal();
            $opening = $this->movement('0.00', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            unset($opening['kind']);
            $this->internal('POST', 'treasury/opening', $opening, $p)->assertOk();
            $one = $this->movement('12.00', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
            $one['concept'] = 'Aporte 50%_! único';
            $saved = $this->internal('POST', 'treasury/movements', $one, $p)->assertOk()->json('data');
            $two = $this->movement('13.00', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');
            $two['concept'] = 'Aporte 50ABC! distinto';
            $this->internal('POST', 'treasury/movements', $two, $p)->assertOk();
            $this->internal('GET', 'treasury', [], $p, 'gateway', ['q' => '50%_!'])
                ->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $saved['id']);
            $this->internal('GET', 'treasury', [], $p, 'gateway', ['q' => 'TES-000002'])
                ->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $saved['id']);
            $this->internal('GET', 'treasury', [], $p, 'gateway', ['q' => 'TES-000002x'])->assertJsonPath('data.total', 0);
            $this->internal('GET', 'treasury', [], $this->principal(org: self::OTHER), 'gateway', ['q' => '50%_!'])->assertJsonPath('data.total', 0);
            $this->internal('GET', 'treasury', [], $this->principal('viewer'), 'gateway', ['q' => '50%_!'])->assertForbidden();
            $this->internal('GET', 'treasury', [], $p, 'gateway', ['q' => str_repeat('x', 121)])->assertUnprocessable();
        } finally { CarbonImmutable::setTestNow(); }
    }
}
