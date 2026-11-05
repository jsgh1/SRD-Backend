<?php
namespace SrdFiles;

use Srd\InternalClient;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class InventoryPhotoAuthorizer
{
    public function __construct(private InternalClient $client) {}

    public function __invoke(array $principal, string $asset, string $action): bool
    {
        if (!in_array($action, ['inventory.read', 'inventory.write'], true)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $asset)) return false;
        try {
            $result = $this->client->call('inventory', 'POST', "assets/$asset/photo-access", ['action' => $action], $principal);
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) return false;
            throw $exception;
        }
        return ($result['authorized'] ?? null) === true;
    }
}
