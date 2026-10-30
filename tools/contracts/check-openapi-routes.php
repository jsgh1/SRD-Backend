<?php

declare(strict_types=1);

/**
 * Compare a Laravel service's route:list JSON with its OpenAPI path/method
 * declarations. This deliberately checks route coverage only; it does not
 * claim schema, authorization, or response-shape equivalence.
 */

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function decodeJsonFile(string $path): mixed
{
    if (!is_file($path)) {
        fail("File not found: {$path}");
    }

    try {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON in {$path}: {$exception->getMessage()}");
    }
}

/** @return array{matched: list<string>, missingRoutes: list<string>, undocumentedRoutes: list<string>} */
function compareRoutes(array $contract, array $laravelRoutes): array
{
    $pathPrefix = null;
    foreach ($contract['servers'] ?? [] as $server) {
        $serverPath = parse_url((string) ($server['url'] ?? ''), PHP_URL_PATH);
        $serverPath = '/' . trim(is_string($serverPath) ? $serverPath : '', '/');
        if ($pathPrefix !== null && $pathPrefix !== $serverPath) {
            throw new RuntimeException('OpenAPI servers use different path prefixes; route comparison would be ambiguous.');
        }
        $pathPrefix = $serverPath;
    }

    if ($pathPrefix === null || $pathPrefix === '/') {
        throw new RuntimeException('OpenAPI must declare a server path prefix for the Gateway routes.');
    }

    $contractOperations = [];
    foreach ($contract['paths'] ?? [] as $path => $pathItem) {
        if (!is_array($pathItem)) {
            continue;
        }
        foreach ($pathItem as $method => $_operation) {
            $method = strtoupper((string) $method);
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE'], true)) {
                $contractOperations[] = ['method' => $method, 'path' => normalizePath((string) $path)];
            }
        }
    }

    $actualOperations = [];
    foreach ($laravelRoutes as $route) {
        if (!is_array($route) || !isset($route['uri'], $route['method'])) {
            continue;
        }

        $uri = '/' . trim(normalizePath((string) $route['uri']), '/');
        if ($uri !== $pathPrefix && !str_starts_with($uri, $pathPrefix . '/')) {
            continue;
        }

        $path = substr($uri, strlen($pathPrefix));
        $path = $path === '' ? '/' : $path;
        foreach (explode('|', strtoupper((string) $route['method'])) as $method) {
            // Laravel reports HEAD for GET routes even when HEAD is not a
            // separately declared OpenAPI operation.
            if ($method === 'HEAD') {
                continue;
            }
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE'], true)) {
                $actualOperations[] = ['method' => $method, 'path' => normalizePath($path)];
            }
        }
    }

    $contractOperations = uniqueOperations($contractOperations);
    $actualOperations = uniqueOperations($actualOperations);

    $matched = [];
    $missingRoutes = [];
    foreach ($contractOperations as $contractOperation) {
        $routeExists = false;
        foreach ($actualOperations as $actualOperation) {
            if ($contractOperation['method'] === $actualOperation['method']
                && pathMatchesRoute($actualOperation['path'], $contractOperation['path'])) {
                $routeExists = true;
                break;
            }
        }

        $label = $contractOperation['method'] . ' ' . $contractOperation['path'];
        if ($routeExists) {
            $matched[] = $label;
        } else {
            $missingRoutes[] = $label;
        }
    }

    $undocumentedRoutes = [];
    foreach ($actualOperations as $actualOperation) {
        $documented = false;
        foreach ($contractOperations as $contractOperation) {
            if ($contractOperation['method'] === $actualOperation['method']
                && contractCoversRoute($contractOperation['path'], $actualOperation['path'])) {
                $documented = true;
                break;
            }
        }
        if (!$documented) {
            $undocumentedRoutes[] = $actualOperation['method'] . ' ' . $actualOperation['path'];
        }
    }

    return [
        'matched' => $matched,
        'missingRoutes' => $missingRoutes,
        'undocumentedRoutes' => $undocumentedRoutes,
    ];
}

/** @param list<array{method: string, path: string}> $operations
 *  @return list<array{method: string, path: string}> */
function uniqueOperations(array $operations): array
{
    $unique = [];
    foreach ($operations as $operation) {
        $unique[$operation['method'] . ' ' . $operation['path']] = $operation;
    }
    ksort($unique);
    return array_values($unique);
}

/** A Laravel route template may serve a concrete OpenAPI path or another template. */
function pathMatchesRoute(string $routePath, string $contractPath): bool
{
    $routeSegments = explode('/', trim(normalizePath($routePath), '/'));
    $contractSegments = explode('/', trim(normalizePath($contractPath), '/'));
    if (count($routeSegments) !== count($contractSegments)) {
        return false;
    }

    foreach ($routeSegments as $index => $routeSegment) {
        if (isPathParameter($routeSegment) || isPathParameter($contractSegments[$index])) {
            continue;
        }
        if ($routeSegment !== $contractSegments[$index]) {
            return false;
        }
    }
    return true;
}

/** A contract fully documents a route template only when dynamic route segments are dynamic in OpenAPI too. */
function contractCoversRoute(string $contractPath, string $routePath): bool
{
    $contractSegments = explode('/', trim(normalizePath($contractPath), '/'));
    $routeSegments = explode('/', trim(normalizePath($routePath), '/'));
    if (count($contractSegments) !== count($routeSegments)) {
        return false;
    }

    foreach ($routeSegments as $index => $routeSegment) {
        $contractSegment = $contractSegments[$index];
        if (isPathParameter($routeSegment) && !isPathParameter($contractSegment)) {
            return false;
        }
        if (!isPathParameter($routeSegment) && !isPathParameter($contractSegment) && $routeSegment !== $contractSegment) {
            return false;
        }
    }
    return true;
}

function isPathParameter(string $segment): bool
{
    return preg_match('/^\{[^{}]+\}$/', $segment) === 1;
}

function normalizePath(string $path): string
{
    $path = '/' . trim($path, '/');
    $path = preg_replace('/\{([^{}?]+)\?\}/', '{$1}', $path) ?? $path;
    return $path === '//' ? '/' : $path;
}

function runSelfTest(): void
{
    $contract = [
        'servers' => [['url' => 'https://example.test/api/v1']],
        'paths' => [
            '/widgets' => ['get' => [], 'post' => []],
            '/widgets/{widgetId}' => ['patch' => []],
        ],
    ];
    $routes = [
        ['uri' => 'api/v1/widgets', 'method' => 'GET|HEAD|POST'],
        ['uri' => 'api/v1/widgets/{widgetId}', 'method' => 'PATCH'],
        ['uri' => 'up', 'method' => 'GET|HEAD'],
    ];
    $result = compareRoutes($contract, $routes);
    if (count($result['matched']) !== 3 || $result['missingRoutes'] !== [] || $result['undocumentedRoutes'] !== []) {
        throw new RuntimeException('Self-test failed for matching routes and implicit HEAD.');
    }

    $result = compareRoutes(
        ['servers' => [['url' => 'https://example.test/internal/v1']], 'paths' => ['/quick-links/organization' => ['patch' => []]]],
        [['uri' => 'internal/v1/quick-links/{scope}', 'method' => 'PATCH']],
    );
    if ($result['missingRoutes'] !== [] || $result['undocumentedRoutes'] !== ['PATCH /quick-links/{scope}']) {
        throw new RuntimeException('Self-test failed to map a concrete OpenAPI path to a constrained Laravel parameter route.');
    }

    $result = compareRoutes($contract, array_slice($routes, 1));
    if ($result['missingRoutes'] !== ['GET /widgets', 'POST /widgets']) {
        throw new RuntimeException('Self-test failed to detect contract operations without Laravel routes.');
    }

    $result = compareRoutes($contract, [...$routes, ['uri' => 'api/v1/widgets/{widgetId}', 'method' => 'DELETE']]);
    if ($result['undocumentedRoutes'] !== ['DELETE /widgets/{widgetId}']) {
        throw new RuntimeException('Self-test failed to detect undocumented Laravel routes.');
    }

    fwrite(STDOUT, "OpenAPI route checker self-test passed.\n");
}

try {
    if (($argv[1] ?? '') === '--self-test') {
        runSelfTest();
        exit(0);
    }

    if ($argc !== 3) {
        fail('Usage: php check-openapi-routes.php <openapi.json> <laravel-route-list.json>');
    }

    $contract = decodeJsonFile($argv[1]);
    $routes = decodeJsonFile($argv[2]);
    if (!is_array($contract) || !is_array($routes)) {
        fail('OpenAPI and Laravel route-list JSON must contain objects/arrays.');
    }

    $result = compareRoutes($contract, $routes);
    printf(
        "OpenAPI operations: %d; Laravel API routes: %d; contract operations mapped: %d; undocumented route templates: %d.\n",
        count($result['matched']) + count($result['missingRoutes']),
        count($result['matched']) + count($result['undocumentedRoutes']),
        count($result['matched']),
        count($result['undocumentedRoutes']),
    );

    if ($result['undocumentedRoutes'] !== []) {
        fwrite(STDERR, "Routes not fully represented in OpenAPI (reported, not treated as failure):\n");
        foreach (array_slice($result['undocumentedRoutes'], 0, 20) as $operation) {
            fwrite(STDERR, "  - {$operation}\n");
        }
        if (count($result['undocumentedRoutes']) > 20) {
            fwrite(STDERR, '  ... and ' . (count($result['undocumentedRoutes']) - 20) . " more.\n");
        }
    }

    if ($result['missingRoutes'] !== []) {
        fwrite(STDERR, "Contract operations without matching Laravel routes:\n");
        foreach ($result['missingRoutes'] as $operation) {
            fwrite(STDERR, "  - {$operation}\n");
        }
        exit(1);
    }

    fwrite(STDOUT, "Every declared OpenAPI operation maps to a Laravel route.\n");
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
