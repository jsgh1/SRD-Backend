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

        $path = "/apps/{$appId}/users/{$sessionId}/terminate_connections";
        $body = '{}';
        $query = ['auth_key' => $key, 'auth_timestamp' => time(), 'auth_version' => '1.0',
            'body_md5' => md5($body)];
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$canonicalQuery}", $secret);
        $url = rtrim(config('srd.chat_reverb_url'), '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = Http::connectTimeout(1)->timeout(2)->withBody($body, 'application/json')->post($url);
            if (! $response->successful()) {
                Log::warning('Reverb no confirmó el cierre de una sesión de Chat.', ['status' => $response->status()]);
            }
            if (! $response->successful()) {
                return false;
            }

            // New channel authorizations use an opaque user ID. Also close the raw ID during
            // the transition so sessions connected before this change are not left active.
            $broadcastUserId = $this->publicUserIdForSession($sessionId);
            if (! is_string($broadcastUserId)) return false;
            $mappedPath = "/apps/{$appId}/users/{$broadcastUserId}/terminate_connections";
            $mappedQuery = ['auth_key' => $key, 'auth_timestamp' => time(), 'auth_version' => '1.0',
                'body_md5' => md5($body)];
            ksort($mappedQuery);
            $mappedCanonicalQuery = http_build_query($mappedQuery, '', '&', PHP_QUERY_RFC3986);
            $mappedQuery['auth_signature'] = hash_hmac(
                'sha256', "POST\n{$mappedPath}\n{$mappedCanonicalQuery}", $secret);
            $mappedUrl = rtrim(config('srd.chat_reverb_url'), '/').$mappedPath.'?'
                .http_build_query($mappedQuery, '', '&', PHP_QUERY_RFC3986);
            $mappedResponse = Http::connectTimeout(1)->timeout(2)->withBody($body, 'application/json')->post($mappedUrl);
            if (! $mappedResponse->successful()) {
                Log::warning('Reverb did not confirm a Chat connection termination.', ['status' => $mappedResponse->status()]);
            }

            return $mappedResponse->successful();
        } catch (\Throwable $e) {
            // Revocation in Identity has already committed. The client and REST checks remain active.
            Log::warning('No se pudo cerrar una sesión de Chat en Reverb.', ['exception' => $e::class]);
            return false;
        }
    }
}
