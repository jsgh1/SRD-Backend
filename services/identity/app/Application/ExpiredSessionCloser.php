<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Srd\InternalClient;

final class ExpiredSessionCloser
{
    public function __construct(private InternalClient $client) {}

    public function run(): int
    {
        // Close recent expirations first, even when older test or outage rows form a backlog.
        $ids = DB::table('auth_sessions')->whereNull('revoked_at')
            ->where(function ($query) {
                $query->where('expires_at', '<=', now())
                    ->orWhere('last_activity_at', '<=', now()->subMinutes(30));
            })->orderByDesc('last_activity_at')->orderByDesc('created_at')->limit(100)->pluck('id');
        $closed = 0;
        foreach ($ids as $id) {
            try {
                $this->client->call('gateway', 'POST', 'chat/terminate-session', ['session_id' => $id]);
            } catch (\Throwable $e) {
                // Keep the expired row pending so the next scheduled run can retry it.
                Log::warning('No se pudo confirmar el cierre de sockets de sesiones vencidas.', ['exception' => $e::class]);
                break;
            }
            DB::table('auth_sessions')->where('id', $id)->whereNull('revoked_at')
                ->where(function ($query) {
                    $query->where('expires_at', '<=', now())
                        ->orWhere('last_activity_at', '<=', now()->subMinutes(30));
                })->update(['revoked_at' => now()]);
            $closed++;
        }

        return $closed;
    }
}
