<?php

declare(strict_types=1);

/**
 * Keep the reviewed classification inventory synchronized with the
 * undocumented route-template baseline. This validates scope and rationale;
 * it does not validate OpenAPI schemas or endpoint authorization behavior.
 */

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function readJson(string $path): array
{
    if (!is_file($path)) {
        fail("File not found: {$path}");
    }

    try {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON in {$path}: {$exception->getMessage()}");
    }

    if (!is_array($value)) {
        fail("JSON root must be an object: {$path}");
    }

    return $value;
}

/** @return array{errors: list<string>, counts: array<string, int>, total: int} */
function validateClassification(array $baseline, array $classification): array
{
    $allowed = ['public', 'internal', 'technical', 'excluded'];
    $errors = [];
    $counts = array_fill_keys($allowed, 0);

    if (($baseline['version'] ?? null) !== 1 || !is_array($baseline['services'] ?? null)) {
        $errors[] = 'Route baseline must be version 1 with a services map.';
    }
    if (($classification['version'] ?? null) !== 1
        || !is_array($classification['services'] ?? null)
        || !is_array($classification['semantics'] ?? null)) {
        $errors[] = 'Classification must be version 1 with semantics and a services map.';
    }

    $baselineServices = is_array($baseline['services'] ?? null) ? $baseline['services'] : [];
    $classifiedServices = is_array($classification['services'] ?? null) ? $classification['services'] : [];
    $semantics = is_array($classification['semantics'] ?? null) ? $classification['semantics'] : [];
    $total = 0;

    foreach ($baselineServices as $service => $operations) {
        if (!is_array($operations) || !array_is_list($operations)) {
            $errors[] = "Baseline for {$service} must be a list.";
            continue;
        }

        $expected = [];
        foreach ($operations as $operation) {
            if (!is_string($operation) || preg_match('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|TRACE) \/\S*$/', $operation) !== 1) {
                $errors[] = "Baseline for {$service} contains an invalid operation.";
                continue;
            }
            if (isset($expected[$operation])) {
                $errors[] = "Baseline for {$service} contains duplicate operation {$operation}.";
            }
            $expected[$operation] = true;
        }

        if ($expected === []) {
            if (isset($classifiedServices[$service])) {
                $errors[] = "Classification for {$service} has no baseline operations to classify.";
            }
            continue;
        }

        $serviceClassification = $classifiedServices[$service] ?? null;
        if (!is_array($serviceClassification)) {
            $errors[] = "Missing classification for {$service}.";
            continue;
        }
        if (!is_string($serviceClassification['rationale'] ?? null) || trim($serviceClassification['rationale']) === '') {
            $errors[] = "Classification for {$service} needs an evidence-based rationale.";
        }
        $routes = $serviceClassification['routes'] ?? null;
        if (!is_array($routes) || !array_is_list($routes)) {
            $errors[] = "Classification routes for {$service} must be a list.";
            continue;
        }

        $actual = [];
        foreach ($routes as $route) {
            if (!is_array($route) || !is_string($route['operation'] ?? null) || !is_string($route['category'] ?? null)) {
                $errors[] = "Classification entries for {$service} need operation and category strings.";
                continue;
            }
            $operation = $route['operation'];
            $category = $route['category'];
            if (!in_array($category, $allowed, true)) {
                $errors[] = "Invalid category '{$category}' for {$service}: {$operation}.";
                continue;
            }
            if (!is_string($semantics[$category] ?? null) || trim($semantics[$category]) === '') {
                $errors[] = "Category '{$category}' needs a defined meaning in the classification semantics.";
            }
            if ($category === 'excluded' && (!is_string($route['rationale'] ?? null) || trim($route['rationale']) === '')) {
                $errors[] = "Excluded route {$service} {$operation} needs a route-specific justification.";
            }
            if (isset($actual[$operation])) {
                $errors[] = "Duplicate classification for {$service}: {$operation}.";
                continue;
            }
            $actual[$operation] = true;
            $counts[$category]++;
        }

        foreach (array_diff_key($expected, $actual) as $operation => $_) {
            $errors[] = "Unclassified baseline route: {$service} {$operation}.";
        }
        foreach (array_diff_key($actual, $expected) as $operation => $_) {
            $errors[] = "Classification is outside the baseline: {$service} {$operation}.";
        }
        $total += count($expected);
    }

    foreach (array_diff_key($classifiedServices, $baselineServices) as $service => $_) {
        $errors[] = "Classification references unknown baseline service {$service}.";
    }

    return ['errors' => array_values(array_unique($errors)), 'counts' => $counts, 'total' => $total];
}

function runSelfTest(): void
{
    $baseline = ['version' => 1, 'services' => ['gateway' => ['GET /health'], 'files' => ['POST /upload']]];
    $classification = [
        'version' => 1,
        'semantics' => ['public' => 'External boundary', 'internal' => 'Service boundary'],
        'services' => [
            'gateway' => ['rationale' => 'Gateway API prefix', 'routes' => [['operation' => 'GET /health', 'category' => 'public']]],
            'files' => ['rationale' => 'Internal middleware', 'routes' => [['operation' => 'POST /upload', 'category' => 'internal']]],
        ],
    ];

    $result = validateClassification($baseline, $classification);
    if ($result['errors'] !== [] || $result['total'] !== 2 || $result['counts']['public'] !== 1 || $result['counts']['internal'] !== 1) {
        throw new RuntimeException('Self-test failed for a complete route classification inventory.');
    }

    $classification['services']['files']['routes'] = [];
    $result = validateClassification($baseline, $classification);
    $detectedUnclassifiedRoute = false;
    foreach ($result['errors'] as $error) {
        if (str_contains($error, 'Unclassified baseline route')) {
            $detectedUnclassifiedRoute = true;
            break;
        }
    }
    if (!$detectedUnclassifiedRoute) {
        throw new RuntimeException('Self-test failed to detect an unclassified baseline route.');
    }

    $classification['services']['files']['routes'] = [
        ['operation' => 'POST /upload', 'category' => 'internal'],
        ['operation' => 'POST /upload', 'category' => 'internal'],
    ];
    $result = validateClassification($baseline, $classification);
    $detectedDuplicate = false;
    foreach ($result['errors'] as $error) {
        if (str_contains($error, 'Duplicate classification')) {
            $detectedDuplicate = true;
            break;
        }
    }
    if (!$detectedDuplicate) {
        throw new RuntimeException('Self-test failed to detect duplicate route classification.');
    }

    fwrite(STDOUT, "OpenAPI classification checker self-test passed.\n");
}

try {
    if (($argv[1] ?? '') === '--self-test') {
        runSelfTest();
        exit(0);
    }
    if ($argc !== 3) {
        fail('Usage: php check-openapi-classification.php <undocumented-route-baseline.json> <route-classification.json>');
    }

    $result = validateClassification(readJson($argv[1]), readJson($argv[2]));
    if ($result['errors'] !== []) {
        foreach ($result['errors'] as $error) {
            fwrite(STDERR, "- {$error}\n");
        }
        exit(1);
    }

    printf(
        "Classified %d undocumented route templates: public %d, internal %d, technical %d, justified exclusions %d.\n",
        $result['total'],
        $result['counts']['public'],
        $result['counts']['internal'],
        $result['counts']['technical'],
        $result['counts']['excluded'],
    );
    fwrite(STDOUT, "Classification inventory matches the route baseline. Authorization and schema coverage remain separate checks.\n");
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
