<?php

namespace Srd;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class Correlation
{
    public function handle(Request $request, Closure $next)
    {
        $candidate = $request->header('X-Correlation-ID', '');
        $id = Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
        $request->attributes->set('correlation_id', $id);
        $response = $next($request);
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $payload = $response->getData(true);
            if (is_array($payload) && array_key_exists('data', $payload)) {
                $payload['meta'] = ['correlation_id' => $id];
                $response->setData($payload);
            }
        }
        $response->headers->set('X-Correlation-ID', $id);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
