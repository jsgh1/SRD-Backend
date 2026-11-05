<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Srd\InternalClient;

final class ChatSocketClosureQueue
{
    public function __construct(private InternalClient $client) {}

    // Call inside the same database transaction that revokes these sessions.
    public function enqueue(array $sessionIds): void
    {
        if ($sessionIds === []) return;
        DB::table('chat_socket_closures')->upsert(array_map(fn ($id) => [
            'session_id' => $id, 'created_at' => now(), 'confirmed_at' => null,
        ], $sessionIds), ['session_id'], ['created_at', 'confirmed_at']);
    }

    public function deliver(): int
    {
        $ids = DB::table('chat_socket_closures')->whereNull('confirmed_at')
            ->orderByDesc('created_at')->limit(100)->pluck('session_id');
        $closed = 0;
        foreach ($ids as $id) {
            try {
                $this->client->call('gateway', 'POST', 'chat/terminate-session', ['session_id' => $id]);
            } catch (\Throwable $e) {
                Log::warning('No se pudo confirmar un cierre pendiente de Chat.', ['exception' => $e::class]);
                break;
            }
            DB::table('chat_socket_closures')->where('session_id', $id)->whereNull('confirmed_at')
                ->update(['confirmed_at' => now()]);
            $closed++;
        }

        return $closed;
    }
}
