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
                $contractOperations[] = $method . ' ' . normalizePath((string) $path);
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
                $actualOperations[] = $method . ' ' . normalizePath($path);
            }
        }
    }

    $contractOperations = array_values(array_unique($contractOperations));
    $actualOperations = array_values(array_unique($actualOperations));
    sort($contractOperations);
    sort($actualOperations);

    return [
        'matched' => array_values(array_intersect($contractOperations, $actualOperations)),
        'missingRoutes' => array_values(array_diff($contractOperations, $actualOperations)),
        'undocumentedRoutes' => array_values(array_diff($actualOperations, $contractOperations)),
    ];
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
        "OpenAPI operations: %d; Laravel API routes: %d; matched: %d.\n",
        count($result['matched']) + count($result['missingRoutes']),
        count($result['matched']) + count($result['undocumentedRoutes']),
        count($result['matched']),
    );

    if ($result['missingRoutes'] !== [] || $result['undocumentedRoutes'] !== []) {
        foreach (['missingRoutes' => 'Contract operations without Laravel routes', 'undocumentedRoutes' => 'Laravel routes missing from OpenAPI'] as $key => $label) {
            if ($result[$key] === []) {
                continue;
            }
            fwrite(STDERR, $label . ":\n");
            foreach ($result[$key] as $operation) {
                fwrite(STDERR, "  - {$operation}\n");
            }
        }
        exit(1);
    }

    fwrite(STDOUT, "Service route coverage matches the OpenAPI paths and methods.\n");
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
