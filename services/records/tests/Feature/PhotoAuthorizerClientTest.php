<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Srd\DependencyFailure;
use Srd\InternalClient;
use Tests\TestCase;

require_once __DIR__.'/../../../files/app/RecordsPhotoAuthorizer.php';

final class PhotoAuthorizerClientTest extends TestCase
{
    private const PERSON = '11111111-1111-4111-8111-111111111111';

    private function authorizer(): \SrdFiles\RecordsPhotoAuthorizer
    {
        config(['srd.service' => 'files', 'srd.urls.records' => 'http://records.test']);
        Http::preventStrayRequests();

        return new \SrdFiles\RecordsPhotoAuthorizer(new InternalClient);
    }

    public function test_grants_are_signed_minimal_and_never_cached(): void
    {
        $authorize = $this->authorizer();
        Http::fake(['records.test/*' => Http::sequence()->push(['data' => ['authorized' => true]])->push([], 404)]);
        $principal = ['organization_id' => self::PERSON, 'user_id' => self::PERSON, 'role' => 'viewer'];
        $this->assertTrue($authorize($principal, self::PERSON, 'persons.read'));
        $this->assertFalse($authorize($principal, self::PERSON, 'persons.read'));
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($principal) {
            [$encoded, $signature] = explode('.', $request->header('X-SRD-Context')[0]);
            $claims = json_decode(base64_decode($encoded), true);
            return $request->method() === 'POST'
                && $request->data() === ['action' => 'persons.read']
                && $claims['iss'] === 'files' && $claims['aud'] === 'records'
                && $claims['context'] === $principal
                && $claims['hash'] === hash('sha256', $request->body())
                && hash_equals(hash_hmac('sha256', $encoded, config('srd.internal_key')), $signature);
        });
    }

    public function test_denials_invalid_requests_and_non_boolean_grants_fail_closed(): void
    {
        $authorize = $this->authorizer();
        $this->assertFalse($authorize([], '../persons', 'persons.read'));
        $this->assertFalse($authorize([], self::PERSON, 'persons.delete'));
        Http::assertNothingSent();
        Http::fake(['records.test/*' => Http::sequence()->push([], 403)->push(['data' => ['authorized' => 'true']])->push(['data' => []])]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($authorize([], self::PERSON, 'persons.read'));
        }
    }

    public function test_outage_is_propagated_instead_of_becoming_authorization(): void
    {
        $authorize = $this->authorizer();
        Http::fake(['records.test/*' => Http::response([], 503)]);
        $this->expectException(DependencyFailure::class);
        $authorize([], self::PERSON, 'persons.read');
    }
}
