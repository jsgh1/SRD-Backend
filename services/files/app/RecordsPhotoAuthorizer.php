<?php

namespace SrdFiles;

use Srd\InternalClient;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Pass this callable to PhotoStore using Closure::fromCallable($authorizer). */
final class RecordsPhotoAuthorizer
{
    public function __construct(private InternalClient $client) {}

    public function __invoke(array $principal, string $person, string $action, ?string $slot = null): bool
    {
        if (!in_array($action, ['persons.read', 'persons.write'], true)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $person)) {
            return false;
        }
        // Never cache grants: each operation must recheck the resource in Records.
        try {
            $result = $this->client->call('records', 'POST', "persons/$person/photo-access",
                ['action' => $action] + ($slot === null ? [] : ['slot' => $slot]), $principal);
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [403, 404], true)) {
                return false;
            }
            throw $exception;
        }

        // Outages, protocol errors and authentication failures must not become grants.
        return ($result['authorized'] ?? null) === true;
    }
}
