<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class DeliveryStatusController
{
    public function __invoke(Request $request): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);
        $principal = Access::require('calendar.delivery.manage');
        $base = DB::table('calendar_delivery_jobs')->where('organization_id', $principal['organization_id']);
        $pending = (clone $base)->whereNull('delivered_at');
        $ready = (clone $pending)->where('attempts', '<', 8);

        return ['data' => [
            'delivered' => (clone $base)->whereNotNull('delivered_at')->count(),
            'pending' => (clone $pending)->count(),
            'due' => (clone $ready)->where('next_attempt_at', '<=', now())->count(),
            'deferred' => (clone $ready)->where('next_attempt_at', '>', now())->count(),
            'exhausted' => (clone $pending)->where('attempts', '>=', 8)->count(),
            'exhausted_jobs' => (clone $pending)->where('attempts', '>=', 8)
                ->orderBy('due_at')->orderBy('id')->limit(20)
                ->get(['id', 'kind', 'attempts', 'due_at']),
        ]];
    }
}
