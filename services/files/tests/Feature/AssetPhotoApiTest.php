<?php
namespace Tests\Feature;

use Srd\Testing\SignedRequests;
use SrdFiles\AssetPhotoStorage;
use Tests\TestCase;

final class AssetPhotoApiTest extends TestCase
{
    use SignedRequests;
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const PATH = 'assets/'.self::ID.'/photos';
    private array $principal = ['organization_id' => self::ID, 'user_id' => self::ID, 'session_id' => self::ID, 'role' => 'admin'];

    public function test_asset_routes_enforce_signed_gateway_and_inventory_role(): void
    {
        $this->app->bind(AssetPhotoStorage::class, fn () => throw new \RuntimeException('Storage should not resolve'));
        $this->getJson('/internal/v1/'.self::PATH)->assertUnauthorized();
        $this->internal('GET', self::PATH, [], $this->principal, 'records')->assertForbidden();
        $this->internal('GET', self::PATH, [], array_replace($this->principal, ['role' => 'registrar']))->assertForbidden();
        $this->internal('PUT', self::PATH.'/front', [], array_replace($this->principal, ['role' => 'viewer']))->assertForbidden();
        $this->internal('GET', self::PATH.'/person', [], $this->principal)->assertNotFound();
    }

    public function test_asset_upload_read_and_delete_use_isolated_storage_and_trusted_context(): void
    {
        $storage = $this->mock(AssetPhotoStorage::class);
        $storage->shouldReceive('list')->once()->withArgs(fn ($p, $id) => $id === self::ID && $p['organization_id'] === self::ID)->andReturn([]);
        $storage->shouldReceive('put')->once()->withArgs(fn ($p, $id, $slot, $name, $bytes, $version) =>
            $id === self::ID && $slot === 'front' && $name === 'a.png' && $bytes === 'synthetic' && $version === 0
            && $p['organization_id'] === self::ID)->andReturn(['slot' => 'front', 'version' => 1, 'present' => true]);
        $storage->shouldReceive('read')->once()->andReturn(['content' => 'synthetic', 'version' => 1]);
        $storage->shouldReceive('delete')->once()->withArgs(fn ($p, $id, $slot, $version, $confirmed) =>
            $id === self::ID && $slot === 'front' && $version === 1 && $confirmed)->andReturn(['version' => 2, 'present' => false]);
        $this->internal('GET', self::PATH, [], $this->principal)->assertOk();
        $this->internal('PUT', self::PATH.'/front', [
            'name' => 'a.png', 'content' => base64_encode('synthetic'), 'version' => 0,
            'organization_id' => 'forged'], $this->principal)->assertJsonPath('data.version', 1);
        $this->internal('GET', self::PATH.'/front', [], $this->principal)->assertJsonPath('data.content', base64_encode('synthetic'));
        $this->internal('DELETE', self::PATH.'/front', ['confirmed' => true, 'version' => 1], $this->principal)->assertJsonPath('data.present', false);
    }
}
