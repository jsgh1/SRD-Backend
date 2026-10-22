<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class SchedulerHealthTest extends TestCase
{
    use SignedRequests;

    public function test_signed_gateway_read_reports_missing_recent_and_stale_markers(): void
    {
        $directory = storage_path('framework/testing-scheduler-health-'.Str::uuid());
        mkdir($directory, 0777, true);
        config()->set('srd.scheduler_health_path', $directory);
        $cycle = $directory.'/scheduler-heartbeat';
        $outbox = $directory.'/outbox-heartbeat';

        try {
            $this->getJson('/internal/v1/scheduler-status')->assertUnauthorized();
            $this->internal('GET', 'scheduler-status', [], [], 'chat')->assertUnauthorized();
            $this->internal('GET', 'scheduler-status')->assertOk()
                ->assertJsonPath('data.available', false)
                ->assertJsonPath('data.cycle_recent', false)
                ->assertJsonPath('data.outbox_recent', false);

            touch($cycle);
            touch($outbox);
            $this->internal('GET', 'scheduler-status')->assertOk()
                ->assertJsonPath('data.available', true)
                ->assertJsonPath('data.cycle_recent', true)
                ->assertJsonPath('data.outbox_recent', true);

            touch($outbox, time() - 241);
            $this->internal('GET', 'scheduler-status')->assertOk()
                ->assertJsonPath('data.available', false)
                ->assertJsonPath('data.cycle_recent', true)
                ->assertJsonPath('data.outbox_recent', false);
        } finally {
            @unlink($cycle);
            @unlink($outbox);
            @rmdir($directory);
        }
    }
}
