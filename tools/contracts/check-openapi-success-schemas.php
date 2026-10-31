<?php
declare(strict_types=1);

/**
 * Guard the minimum structure needed for a usable API contract.
 * This validates OpenAPI structure only; it does not prove runtime equivalence.
 */

const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

/** @return list<string> */
function contractIssues(array $document, string $name): array
{
    $issues = [];
    $paths = $document['paths'] ?? null;
    if (!is_array($paths)) {
        return ["$name: paths must be an object"];
    }

    foreach ($paths as $path => $pathItem) {
        if (!is_array($pathItem)) {
            $issues[] = "$name $path: path item must be an object";
            continue;
        }
        foreach ($pathItem as $method => $operation) {
            if (!in_array(strtolower((string) $method), HTTP_METHODS, true)) {
                continue;
            }
            $label = "$name ".strtoupper((string) $method)." $path";
            if (!is_array($operation)) {
                $issues[] = "$label: operation must be an object";
                continue;
            }
            $responses = $operation['responses'] ?? null;
            if (!is_array($responses) || $responses === []) {
                $issues[] = "$label: responses must be declared";
            }
            if (!array_key_exists('security', $operation) && !array_key_exists('security', $document)) {
                $issues[] = "$label: security must be explicit or inherited";
            }

            $requestContent = $operation['requestBody']['content'] ?? [];
            foreach ($requestContent as $mediaType => $media) {
                if (str_contains(strtolower((string) $mediaType), 'json')
                    && (!is_array($media) || !isset($media['schema']))) {
                    $issues[] = "$label: request media $mediaType has no schema";
                }
            }

            foreach (is_array($responses) ? $responses : [] as $status => $response) {
                if (!preg_match('/^2[0-9]{2}$/', (string) $status)
                    || in_array((string) $status, ['204', '205'], true)) {
                    continue;
                }
                $content = is_array($response) ? ($response['content'] ?? []) : [];
                $hasSchema = false;
                foreach ($content as $media) {
                    if (is_array($media) && isset($media['schema'])) {
                        $hasSchema = true;
                        break;
                    }
                }
                if (!$hasSchema) {
                    $issues[] = "$label: success response $status has no content schema";
                }
            }
        }
    }

    if (in_array($name, ['files.openapi.json', 'gateway.openapi.json'], true)) {
        $responseSchema = $document['paths']['/folder-documents']['post']['responses']['200']['content']['application/json']['schema'] ?? null;
        $metadata = is_array($responseSchema) ? ($responseSchema['properties']['data'] ?? null) : null;
        $expected = ['id', 'folder_id', 'name', 'mime', 'bytes', 'sha256', 'created_at', 'version'];
        if (!is_array($responseSchema) || ($responseSchema['type'] ?? null) !== 'object'
            || !in_array('data', $responseSchema['required'] ?? [], true)
            || !is_array($metadata) || ($metadata['type'] ?? null) !== 'object') {
            $issues[] = "$name POST /folder-documents: response must describe the data envelope";
        } else {
            foreach ($expected as $field) {
                if (!isset($metadata['properties'][$field])
                    || !in_array($field, $metadata['required'] ?? [], true)) {
                    $issues[] = "$name POST /folder-documents: required metadata field $field is missing";
                }
            }
        }
    }

    return $issues;
}

if (($argv[1] ?? null) === '--self-test') {
    $valid = [
        'security' => [],
        'paths' => [
            '/resource' => ['post' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]],
            '/resource/{id}' => ['delete' => ['responses' => ['204' => ['description' => 'Deleted']]]],
        ],
    ];
    if (contractIssues($valid, 'valid') !== []) {
        fwrite(STDERR, "Self-test rejected a valid response and an explicit no-content response.\n");
        exit(1);
    }
    $invalid = ['paths' => ['/resource' => ['post' => ['responses' => ['200' => ['description' => 'Missing schema']]]]]];
    if (contractIssues($invalid, 'invalid') === []) {
        fwrite(STDERR, "Self-test did not detect a missing security declaration and success schema.\n");
        exit(1);
    }
    fwrite(STDOUT, "OpenAPI success-schema checker self-test passed.\n");
    exit(0);
}

$contracts = glob(dirname(__DIR__, 2).'/contracts/*.openapi.json') ?: [];
if ($contracts === []) {
    fwrite(STDERR, "No service OpenAPI contracts were found.\n");
    exit(2);
}

$operations = 0;
$issues = [];
foreach ($contracts as $contract) {
    try {
        $document = json_decode((string) file_get_contents($contract), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        $issues[] = basename($contract).': invalid JSON ('.$error->getMessage().')';
        continue;
    }
    if (!is_array($document)) {
        $issues[] = basename($contract).': document root must be an object';
        continue;
    }
    foreach ($document['paths'] ?? [] as $pathItem) {
        if (is_array($pathItem)) {
            foreach ($pathItem as $method => $operation) {
                if (in_array(strtolower((string) $method), HTTP_METHODS, true) && is_array($operation)) {
                    $operations++;
                }
            }
        }
    }
    $issues = [...$issues, ...contractIssues($document, basename($contract))];
}

if ($issues !== []) {
    fwrite(STDERR, implode("\n", $issues)."\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Validated explicit security, request schemas, and successful response schemas for %d OpenAPI operations across %d services.\n", $operations, count($contracts)));
