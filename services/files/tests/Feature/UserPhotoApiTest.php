<?php
namespace Tests\Feature;

use Srd\Testing\SignedRequests;
use SrdFiles\UserPhotoStorage;
use Tests\TestCase;

final class UserPhotoApiTest extends TestCase
{
    use SignedRequests;
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const PATH = 'users/'.self::ID.'/photos';
    private array $principal = ['organization_id' => self::ID, 'user_id' => self::ID, 'session_id' => self::ID, 'role' => 'viewer'];

    public function test_user_routes_require_gateway_context_and_allow_self_profile_roles(): void
    {
        $this->app->bind(UserPhotoStorage::class, fn () => throw new \RuntimeException('Storage should not resolve'));
        $this->getJson('/internal/v1/'.self::PATH)->assertUnauthorized();
        $this->internal('GET', self::PATH, [], $this->principal, 'identity')->assertForbidden();
        $this->internal('GET', self::PATH.'/wrong', [], $this->principal)->assertNotFound();
    }

    public function test_read_upload_and_delete_pass_validated_context_to_storage(): void
    {
        $storage = $this->mock(UserPhotoStorage::class);
        $storage->shouldReceive('list')->once()->withArgs(fn ($p, $id) => $id === self::ID && $p['organization_id'] === self::ID)->andReturn([]);
        $storage->shouldReceive('put')->once()->withArgs(fn ($p, $id, $slot, $name, $bytes, $version) =>
            $id === self::ID && $slot === 'avatar' && $name === 'a.png' && $bytes === 'synthetic' && $version === 0
            && $p['user_id'] === self::ID)->andReturn(['slot' => 'avatar', 'version' => 1, 'present' => true]);
        $storage->shouldReceive('read')->once()->andReturn(['content' => 'synthetic', 'version' => 1]);
        $storage->shouldReceive('delete')->once()->andReturn(['version' => 2, 'present' => false]);
        $this->internal('GET', self::PATH, [], $this->principal)->assertOk();
        $this->internal('PUT', self::PATH.'/avatar', ['name' => 'a.png', 'content' => base64_encode('synthetic'), 'version' => 0], $this->principal)->assertOk()->assertJsonPath('data.version', 1);
        $this->internal('GET', self::PATH.'/avatar', [], $this->principal)->assertJsonPath('data.content', base64_encode('synthetic'));
        $this->internal('DELETE', self::PATH.'/avatar', ['confirmed' => true, 'version' => 1], $this->principal)->assertJsonPath('data.present', false);
    }
}
