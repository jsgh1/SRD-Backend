<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase, SignedRequests;
    private array $p = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'admin'];
    private function record(string $time, array $changes = []): string {
        $id = (string) Str::uuid();
        DB::table('persons')->insert(array_replace(['id' => $id, 'organization_id' => $this->p['organization_id'], 'document_type' => 'CC', 'document_number' => Str::random(12), 'first_names' => 'Indicador ficticio', 'status' => 'pending', 'created_by' => $this->p['user_id'], 'created_at' => $time, 'updated_at' => $time], $changes));
        return $id;
    }
    public function test_colombian_day_week_month_and_seven_day_boundaries(): void {
        $this->travelTo(\Carbon\Carbon::parse('2026-06-01T05:30:00Z'));
        $ids = [];
        foreach (['2026-05-26 04:59:59', '2026-05-26 05:00:00', '2026-06-01 04:59:59', '2026-06-01 05:00:00', '2026-06-01 05:10:00', '2026-06-02 05:00:00'] as $i => $time) {
            $ids[] = $this->record($time, ['gender' => ['male', 'female', null][$i % 3], 'document_type' => $i % 2 ? 'TI' : 'CC']);
        }
        $this->record('2026-06-01 05:00:00', ['organization_id' => (string) Str::uuid()]);
        $data = $this->internal('GET', 'dashboard', [], $this->p)->assertOk()
            ->assertJsonPath('data.date', '2026-06-01')->assertJsonPath('data.timezone', 'America/Bogota')
            ->assertJsonPath('data.total', 6)->assertJsonPath('data.today', 2)->assertJsonPath('data.week', 2)->assertJsonPath('data.month', 2)
            ->assertJsonCount(7, 'data.last_seven_days')->json('data');
        $this->assertSame(['2026-05-26', '2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30', '2026-05-31', '2026-06-01'], array_column($data['last_seven_days'], 'date'));
        $this->assertSame([1, 0, 0, 0, 0, 1, 2], array_column($data['last_seven_days'], 'count'));
        $this->assertSame(6, array_sum(array_column($data['gender'], 'count')));
        $this->assertSame(6, array_sum(array_column($data['document_types'], 'count')));
        $this->assertSame('2026-06-01T05:30:00+00:00', $data['generated_at']);
        DB::table('persons')->where('id', $ids[3])->delete();
        $this->internal('GET', 'dashboard', [], $this->p)->assertOk()->assertJsonPath('data.total', 5)->assertJsonPath('data.today', 1)->assertJsonPath('data.week', 1)->assertJsonPath('data.month', 1);
    }
    public function test_sunday_still_uses_monday_and_latest_ties_are_stable_for_all_roles(): void {
        $this->travelTo(\Carbon\Carbon::parse('2026-06-07T12:00:00Z'));
        $ids = [];
        for ($i = 0; $i < 12; $i++) $ids[] = $this->record('2026-06-01 05:00:00');
        $newest = $this->record('2026-06-07 05:00:00');
        $this->record('2026-05-31 05:00:00');
        rsort($ids);
        foreach (Access::ROLES as $role) {
            $data = $this->internal('GET', 'dashboard', [], array_replace($this->p, ['role' => $role]))->assertOk()
                ->assertJsonPath('data.total', 14)->assertJsonPath('data.week', 13)->assertJsonPath('data.today', 1)
                ->assertJsonCount(10, 'data.latest')->assertJsonMissingPath('data.latest.0.note')->json('data');
            $this->assertSame([$newest, ...array_slice($ids, 0, 9)], array_column($data['latest'], 'id'));
        }
    }
}
