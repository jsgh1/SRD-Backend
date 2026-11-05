<?php

namespace Srd\Testing;

use Illuminate\Support\Str;

trait SignedRequests
{
    protected function internal(string $method, string $path, array $body = [], array $principal = [], string $issuer = 'gateway', array $query = [])
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $uri = '/internal/v1/'.$path;
        $claims = ['iss' => $issuer, 'aud' => config('srd.service'), 'iat' => time(), 'exp' => time() + 30, 'nonce' => (string) Str::uuid(), 'method' => $method, 'path' => $uri, 'hash' => hash('sha256', $json), 'context' => $principal];
        $encoded = base64_encode(json_encode($claims));

        return $this->call($method, $uri.($query ? '?'.http_build_query($query) : ''), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SRD_CONTEXT' => $encoded.'.'.hash_hmac('sha256', $encoded, config('srd.internal_key'))], $json);
    }
}
