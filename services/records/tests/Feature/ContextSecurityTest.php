<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContextSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_binds_audience_body_and_path_and_rejects_replay(): void
    {
        $uri = '/internal/v1/persons';
        $body = '[]';
        $claims = ['iss' => 'gateway', 'aud' => 'records', 'iat' => time(), 'exp' => time() + 30, 'nonce' => 'unique-test-nonce', 'method' => 'GET', 'path' => $uri, 'hash' => hash('sha256', $body), 'context' => ['user_id' => '33333333-3333-4333-8333-333333333333', 'organization_id' => '11111111-1111-4111-8111-111111111111', 'role' => 'viewer']];
        $send = function ($claims, $content = '[]', $path = '/internal/v1/persons') {
            $e = base64_encode(json_encode($claims));

            return $this->call('GET', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SRD_CONTEXT' => $e.'.'.hash_hmac('sha256', $e, config('srd.internal_key'))], $content);
        };
        $send($claims)->assertOk();
        $send($claims)->assertUnauthorized();
        $claims['nonce'] = 'second';
        $send($claims, '{"role":"superadmin"}')->assertUnauthorized();
        $claims['aud'] = 'identity';
        $send($claims)->assertUnauthorized();
        $claims['aud'] = 'records';
        $claims['exp'] = time() - 1;
        $send($claims)->assertUnauthorized();
        $claims['exp'] = time() + 30;
        $send($claims, '[]', '/internal/v1/dashboard')->assertUnauthorized();
    }
}
