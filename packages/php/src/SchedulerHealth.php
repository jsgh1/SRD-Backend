<?php

namespace Srd;

final class SchedulerHealth
{
    public function status(): array
    {
        $directory = config('srd.scheduler_health_path') ?: storage_path('framework/scheduler-health');
        $cycle = $this->recent($directory.'/scheduler-heartbeat');
        $outbox = $this->recent($directory.'/outbox-heartbeat');

        return ['available' => $cycle && $outbox,
            'cycle_recent' => $cycle, 'outbox_recent' => $outbox];
    }

    private function recent(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return false;
        }
        $age = time() - filemtime($path);

        return $age >= 0 && $age <= 180;
    }
}
