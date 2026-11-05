<?php
namespace Tests\Feature;

use Srd\Testing\SignedRequests;
use SrdFiles\{PhotoStorage, PhotoNotFound, PhotoConflict, ImageRejected, ScannerUnavailable, PhotoQuotaExceeded};
use Tests\TestCase;

final class PhotoApiTest extends TestCase
{
    use SignedRequests;
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const PATH = 'persons/'.self::ID.'/photos';
    private array $principal = ['organization_id' => self::ID, 'user_id' => self::ID, 'session_id' => self::ID, 'role' => 'admin'];

    public function test_unsigned_other_issuers_and_invalid_context_never_resolve_storage(): void
    {
        $this->app->bind(PhotoStorage::class, fn () => throw new \RuntimeException('Storage must not be resolved'));
        $this->getJson('/internal/v1/'.self::PATH)->assertUnauthorized();
        foreach (['records', 'identity', 'configuration', 'audit'] as $issuer) {
            $this->internal('GET', self::PATH, [], $this->principal, $issuer)->assertForbidden();
        }
        foreach (['organization_id', 'user_id', 'session_id'] as $key) {
            $p = $this->principal; unset($p[$key]);
            $this->internal('GET', self::PATH, [], $p)->assertForbidden();
        }
        $this->internal('GET', self::PATH, [], $this->principal, 'files')->assertUnauthorized();
    }

    public function test_role_permissions_apply_before_storage_and_body_cannot_override_context(): void
    {
        $storage = $this->mock(PhotoStorage::class);
        $storage->shouldReceive('list')->times(6)->withArgs(fn ($p, $id) => $p['organization_id'] === self::ID && $id === self::ID)->andReturn([]);
        foreach (\Srd\Access::ROLES as $role) {
            $p = array_replace($this->principal, ['role' => $role]);
            $this->internal('GET', self::PATH, ['organization_id' => 'forged'], $p)->assertOk();
            if (!in_array($role, ['superadmin', 'admin', 'registrar'])) {
                $this->internal('PUT', self::PATH.'/person', ['role' => 'admin'], $p)->assertForbidden();
                $this->internal('DELETE', self::PATH.'/person', ['confirmed' => true, 'version' => 1], $p)->assertForbidden();
            }
        }
    }

    public function test_upload_decodes_canonical_base64_and_passes_trusted_context_and_version(): void
    {
        $storage = $this->mock(PhotoStorage::class);
        $storage->shouldReceive('put')->once()->withArgs(function ($p, $id, $slot, $name, $bytes, $version) {
            return $p['organization_id'] === self::ID && $p['role'] === 'admin' && isset($p['correlation_id'])
                && $id === self::ID && $slot === 'document' && $name === 'test.png' && $bytes === 'synthetic' && $version === 3;
        })->andReturn(['slot' => 'document', 'version' => 4, 'present' => true]);
        $this->internal('PUT', self::PATH.'/document', [
            'name' => 'test.png', 'content' => base64_encode('synthetic'), 'version' => 3,
            'organization_id' => 'forged', 'principal' => ['role' => 'superadmin'],
        ], $this->principal)->assertOk()->assertJsonPath('data.version', 4);
    }

    public function test_validation_rejects_invalid_base64_size_version_slot_and_unconfirmed_deletion(): void
    {
        $this->mock(PhotoStorage::class)->shouldNotReceive('put');
        foreach (['!!', 'YQ', "YQ==\n", '', base64_encode(str_repeat('x', 5 * 1024 * 1024 + 1))] as $content) {
            $this->internal('PUT', self::PATH.'/person', ['name' => 'a.png', 'content' => $content, 'version' => 0], $this->principal)->assertUnprocessable();
        }
        $this->internal('PUT', self::PATH.'/person', ['name' => 'a.png', 'content' => 'YQ==', 'version' => -1], $this->principal)->assertUnprocessable();
        $this->internal('DELETE', self::PATH.'/person', ['confirmed' => false, 'version' => 1], $this->principal)->assertUnprocessable();
        $this->internal('GET', self::PATH.'/unknown', [], $this->principal)->assertNotFound();
        $this->internal('PUT', self::PATH.'/person', ['padding' => str_repeat('x', 7 * 1024 * 1024)], $this->principal)->assertStatus(413);
    }

    public function test_read_omits_internal_metadata_and_is_not_cacheable(): void
    {
        $this->mock(PhotoStorage::class)->shouldReceive('read')->once()->andReturn(['content' => 'synthetic', 'version' => 2, 'mime' => 'image/png', 'sha256' => 'private', 'blob_id' => 'private']);
        $response = $this->internal('GET', self::PATH.'/property', [], $this->principal)->assertOk();
        $this->assertSame(['content' => base64_encode('synthetic'), 'mime' => 'image/png', 'version' => 2], $response->json('data'));
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_confirmed_delete_preserves_optimistic_version(): void
    {
        $this->mock(PhotoStorage::class)->shouldReceive('delete')->once()->withArgs(fn ($p, $id, $slot, $version, $confirmed) => $id === self::ID && $slot === 'person' && $version === 8 && $confirmed === true)->andReturn(['version' => 9, 'present' => false]);
        $this->internal('DELETE', self::PATH.'/person', ['confirmed' => true, 'version' => 8], $this->principal)->assertOk()->assertJsonPath('data.version', 9);
    }

    public function test_domain_errors_have_stable_status_and_never_disclose_internal_messages(): void
    {
        foreach ([PhotoNotFound::class => 404, PhotoConflict::class => 409, ImageRejected::class => 422, ScannerUnavailable::class => 503, PhotoQuotaExceeded::class => 507, \RuntimeException::class => 500] as $class => $status) {
            $storage = \Mockery::mock(PhotoStorage::class);
            $storage->shouldReceive('list')->once()->andThrow(new $class('PRIVATE-PATH-OR-CONTENT'));
            $this->app->instance(PhotoStorage::class, $storage);
            $response = $this->internal('GET', self::PATH, [], $this->principal)->assertStatus($status)->assertJsonPath('error.code', 'HTTP_'.$status);
            $this->assertStringNotContainsString('PRIVATE-PATH-OR-CONTENT', $response->getContent());
        }
    }
}
