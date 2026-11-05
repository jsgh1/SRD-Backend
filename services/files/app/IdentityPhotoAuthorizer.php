<?php
namespace SrdFiles;

use Srd\InternalClient;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class IdentityPhotoAuthorizer
{
    public function __construct(private InternalClient $client) {}

    public function __invoke(array $principal, string $user, string $action): bool
    {
        if (!in_array($action, ['contacts.read', 'profile.write'], true)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $user)) return false;
        try {
            $result = $this->client->call('identity', 'POST', "users/$user/photo-access", ['action' => $action], $principal);
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) return false;
            throw $exception;
        }
        return ($result['authorized'] ?? null) === true;
    }
}
