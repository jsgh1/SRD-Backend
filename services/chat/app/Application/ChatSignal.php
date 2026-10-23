<?php

namespace App\Application;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

final class ChatSignal
{
    public function changed(string $organizationId, string $userA, string $userB): void
    {
        if (config('broadcasting.default') !== 'reverb') return;

        foreach (array_unique([$userA, $userB]) as $userId) {
            try {
                // The socket receives no message body, ID, or conversation metadata.
                // The browser must fetch changes from the authorized REST API.
                Broadcast::presence("chat.{$organizationId}.{$userId}")
                    ->as('chat.changed')->with([])->sendNow();
            } catch (\Throwable $e) {
                // The durable message/receipt remains committed; polling recovers the signal.
                Log::warning('No se pudo emitir una señal de Chat.', ['exception' => $e::class]);
            }
        }
    }
}
