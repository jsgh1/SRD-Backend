<?php

namespace Srd;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InternalClient
{
    public function call(string $service, string $method, string $path, array $data = [], array $context = [], ?int $timeout = null): array
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $uri = '/internal/v1/'.$path;
        $headers = $this->signedHeaders($service, $method, $uri, $body, $context);
        try {
            $response = Http::timeout($timeout ?? ($service === 'files' ? 75 : 15))->connectTimeout($timeout === null ? 3 : min(3, $timeout))->acceptJson()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')->send($method, config('srd.urls.'.$service).$uri);
        } catch (\Throwable) {
            throw new DependencyFailure;
        }
        if ($service === 'files' && $response->status() === 507) abort(507);
        if ($response->status() >= 500) {
            throw new DependencyFailure;
        }
        if (! $response->successful()) {
            if ($response->status() === 422) {
                throw ValidationException::withMessages($response->json('error.fields') ?: ['request' => 'Datos inválidos.']);
            }
            abort($response->status());
        }

        return $response->json('data') ?? [];
    }

    public function signedHeaders(string $service, string $method, string $uri, string $body = '', array $context = []): array
    {
        $claims = ['iss' => config('srd.service'), 'aud' => $service, 'iat' => time(), 'exp' => time() + 30,
            'nonce' => (string) Str::uuid(), 'method' => $method, 'path' => $uri,
            'hash' => hash('sha256', $body), 'context' => $context];
        $encoded = base64_encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $key = config('srd.internal_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new DependencyFailure;
        }
        $signature = hash_hmac('sha256', $encoded, $key);

        return ['X-SRD-Context' => $encoded.'.'.$signature,
            'X-Correlation-ID' => request()->attributes->get('correlation_id', (string) Str::uuid())];
    }
}
