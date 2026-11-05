<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ChatConnectionTerminator
{
    private const SESSION_USER_DOMAIN = 'srd-reverb-session:';

    public function publicUserIdForSession(string $sessionId): ?string
    {
        $secret = config('srd.chat_broadcast_secret');
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId)
            || ! is_string($secret) || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', self::SESSION_USER_DOMAIN.$sessionId, $secret);
    }

    /**
     * Returns true only when Reverb accepts both legacy and opaque-user termination commands.
     * A successful response confirms command handling, not that a connection was present.
     */
    public function terminate(string $sessionId): bool
    {
        $appId = config('srd.chat_broadcast_app_id');
        $key = config('srd.chat_broadcast_key');
        $secret = config('srd.chat_broadcast_secret');
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId)
            || ! is_string($appId) || ! preg_match('/^[a-zA-Z0-9_-]+$/', $appId)
            || ! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        $broadcastUserId = $this->publicUserIdForSession($sessionId);
        if (! is_string($broadcastUserId)) {
            return false;
        }

        // Keep these attempts independent: a failure in one namespace must not prevent
        // trying the other. Identity revocation may already be committed by this point.
        $legacyConfirmed = $this->terminateUserId($appId, $sessionId, $key, $secret, 'legacy');
        $opaqueConfirmed = $this->terminateUserId($appId, $broadcastUserId, $key, $secret, 'opaque');

        return $legacyConfirmed && $opaqueConfirmed;
    }

    private function terminateUserId(
        string $appId,
        string $userId,
        string $key,
        string $secret,
        string $target
    ): bool {
        $path = "/apps/{$appId}/users/{$userId}/terminate_connections";
        $body = '{}';
        $query = [
            'auth_key' => $key,
            'auth_timestamp' => time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$canonicalQuery}", $secret);
        $url = rtrim(config('srd.chat_reverb_url'), '/').$path.'?'
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = Http::connectTimeout(1)->timeout(2)->withBody($body, 'application/json')->post($url);
        } catch (\Throwable $e) {
            Log::warning('No se pudo cerrar una sesión de Chat en Reverb.', [
                'target' => $target,
                'exception' => $e::class,
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Reverb did not confirm a Chat connection termination.', [
                'target' => $target,
                'status' => $response->status(),
            ]);
        }

        return $response->successful();
    }
}
