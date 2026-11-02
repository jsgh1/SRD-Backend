<?php

declare(strict_types=1);

/**
 * Validate the correlation between the single public POST /auth/{action}
 * operation and its action-specific request and browser response schemas.
 * This is a structural contract check; it does not prove runtime equivalence.
 */

const SRD_AUTH_ACTIONS = [
    'login',
    'verify',
    'resend',
    'cancel',
    'recover',
    'reset',
    'logout',
    'revokeOthers',
    'switchOrganization',
];

const SRD_AUTH_SESSION_REQUIREMENTS = [
    'login' => 'anonymous',
    'verify' => 'challenge',
    'resend' => 'challenge',
    'cancel' => 'challenge',
    'recover' => 'anonymous',
    'reset' => 'anonymous',
    'logout' => 'authenticated',
    'revokeOthers' => 'authenticated',
    'switchOrganization' => 'authenticated',
];

const SRD_AUTH_FORBIDDEN_RESPONSE_FIELDS = [
    'token',
    'identity_token',
    'credential',
    'identity_credential',
    'secret',
    'password',
    'password_confirmation',
    'session_id',
    'session_ids',
    'internal_session_id',
    'revoked_session_ids',
];

function authIssue(array &$issues, string $message): void
{
    $issues[] = $message;
}

/** Resolve a JSON Pointer within an OpenAPI document. */
function resolveAuthRef(array $document, string $ref): ?array
{
    if (!str_starts_with($ref, '#/')) {
        return null;
    }

    $value = $document;
    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return is_array($value) ? $value : null;
}

/**
 * @param list<string> $issues
 * @param array<string, true> $visited
 */
function validateAuthRefs(array $document, mixed $value, string $location, array &$issues, array &$visited = []): void
{
    if (!is_array($value)) {
        return;
    }

    if (isset($value['$ref'])) {
        $ref = $value['$ref'];
        if (!is_string($ref) || resolveAuthRef($document, $ref) === null) {
            authIssue($issues, "{$location}: broken or unsupported reference.");
        } elseif (!isset($visited[$ref])) {
            $visited[$ref] = true;
            validateAuthRefs($document, resolveAuthRef($document, $ref), $location." {$ref}", $issues, $visited);
        }
    }

    foreach ($value as $key => $child) {
        if ($key !== '$ref') {
            validateAuthRefs($document, $child, $location.'.'.$key, $issues, $visited);
        }
    }
}

/**
 * @param array<string, true> $visited
 * @param list<string> $issues
 */
function inspectSensitiveAuthFields(
    array $document,
    mixed $schema,
    string $location,
    array &$issues,
    array &$visited = [],
): void {
    if (!is_array($schema)) {
        return;
    }

    if (isset($schema['$ref']) && is_string($schema['$ref'])) {
        $ref = $schema['$ref'];
        if (!isset($visited[$ref])) {
            $visited[$ref] = true;
            $resolved = resolveAuthRef($document, $ref);
            if ($resolved !== null) {
                inspectSensitiveAuthFields($document, $resolved, $location." {$ref}", $issues, $visited);
            }
        }
    }

    foreach ($schema['properties'] ?? [] as $name => $property) {
        if (in_array(strtolower((string) $name), SRD_AUTH_FORBIDDEN_RESPONSE_FIELDS, true)) {
            authIssue($issues, "{$location}: response schema exposes forbidden field '{$name}'.");
        }
        inspectSensitiveAuthFields($document, $property, $location.'.'.$name, $issues, $visited);
    }

    foreach (['items', 'allOf', 'oneOf', 'anyOf', 'not', 'additionalProperties'] as $keyword) {
        if (!array_key_exists($keyword, $schema)) {
            continue;
        }
        $children = is_array($schema[$keyword]) && array_is_list($schema[$keyword])
            ? $schema[$keyword]
            : [$schema[$keyword]];
        foreach ($children as $index => $child) {
            inspectSensitiveAuthFields($document, $child, $location.'.'.$keyword.'.'.$index, $issues, $visited);
        }
    }
}

/**
 * @return array{actions: array<string, string>, issues: list<string>}
 */
function authVariantMap(array $schema, string $kind): array
{
    $issues = [];
    $variants = $schema['oneOf'] ?? null;
    if (!is_array($variants) || !array_is_list($variants)) {
        return ['actions' => [], 'issues' => ["{$kind} must declare a oneOf list of action variants."]];
    }

    $actions = [];
    foreach ($variants as $index => $variant) {
        if (!is_array($variant)) {
            authIssue($issues, "{$kind} variant {$index} must be an object.");
            continue;
        }

        $action = $variant['x-srd-action'] ?? null;
        if (!is_string($action) || !in_array($action, SRD_AUTH_ACTIONS, true)) {
            authIssue($issues, "{$kind} variant {$index} has a missing or unsupported x-srd-action.");
            continue;
        }
        if (isset($actions[$action])) {
            authIssue($issues, "{$kind} contains duplicate variant for action '{$action}'.");
            continue;
        }

        $refs = [];
        $allOf = $variant['allOf'] ?? null;
        if (is_array($allOf) && array_is_list($allOf)) {
            foreach ($allOf as $item) {
                if (is_array($item) && is_string($item['$ref'] ?? null)) {
                    $refs[] = $item['$ref'];
                }
            }
        }
        if (count($refs) !== 1) {
            authIssue($issues, "{$kind} variant '{$action}' must reference exactly one schema through allOf.");
            continue;
        }

        $actions[$action] = $refs[0];
    }

    return ['actions' => $actions, 'issues' => $issues];
}

/** @return list<string> */
function validateAuthActionContract(array $document): array
{
    $issues = [];
    $paths = $document['paths'] ?? null;
    if (!is_array($paths)) {
        return ['OpenAPI paths must be an object.'];
    }

    $path = '/auth/{action}';
    $operation = $paths[$path]['post'] ?? null;
    if (!is_array($operation)) {
        authIssue($issues, 'The single public POST /auth/{action} operation is required.');
        foreach ($paths as $candidate => $pathItem) {
            if (preg_match('~^/auth/[^{}]+$~', (string) $candidate) === 1
                && is_array($pathItem) && isset($pathItem['post'])) {
                authIssue($issues, "Synthetic public auth route {$candidate} must be removed.");
            }
            if ($candidate === '/auth/me' && is_array($pathItem) && $pathItem !== []) {
                authIssue($issues, 'Internal Identity auth/me must not be published as a public Gateway route.');
            }
        }
        return array_values(array_unique($issues));
    }

    if (!is_string($operation['operationId'] ?? null) || trim($operation['operationId']) === '') {
        authIssue($issues, 'The auth operation must have a unique operationId.');
    }

    foreach ($paths as $candidate => $pathItem) {
        if ($candidate !== $path && preg_match('~^/auth/[^{}]+$~', (string) $candidate) === 1
            && is_array($pathItem) && isset($pathItem['post'])) {
            authIssue($issues, "Synthetic public auth route {$candidate} must be removed.");
        }
        if ($candidate === '/auth/me' && is_array($pathItem) && $pathItem !== []) {
            authIssue($issues, 'Internal Identity auth/me must not be published as a public Gateway route.');
        }
    }

    $operationIds = [];
    foreach ($paths as $pathName => $pathItem) {
        if (!is_array($pathItem)) {
            continue;
        }
        foreach ($pathItem as $method => $candidateOperation) {
            if (!in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)
                || !is_array($candidateOperation)) {
                continue;
            }
            $operationId = $candidateOperation['operationId'] ?? null;
            if (!is_string($operationId) || trim($operationId) === '') {
                continue;
            }
            if (isset($operationIds[$operationId])) {
                authIssue($issues, "Duplicate operationId '{$operationId}' at {$pathName}.");
            }
            $operationIds[$operationId] = true;
        }
    }

    $pathParameters = array_values(array_filter(
        $operation['parameters'] ?? [],
        static fn ($parameter) => is_array($parameter)
            && ($parameter['in'] ?? null) === 'path'
            && ($parameter['name'] ?? null) === 'action',
    ));
    if (count($pathParameters) !== 1) {
        authIssue($issues, 'The action path parameter must be declared exactly once.');
    } else {
        $parameter = $pathParameters[0];
        $enum = $parameter['schema']['enum'] ?? null;
        if (($parameter['required'] ?? false) !== true
            || ($parameter['schema']['type'] ?? null) !== 'string'
            || !is_array($enum) || !array_is_list($enum)
            || count($enum) !== count(SRD_AUTH_ACTIONS)
            || count(array_unique($enum)) !== count(SRD_AUTH_ACTIONS)
            || array_diff(SRD_AUTH_ACTIONS, $enum) !== []
            || array_diff($enum, SRD_AUTH_ACTIONS) !== []) {
            authIssue($issues, 'The action path enum must contain each of the nine supported actions exactly once.');
        }
    }

    $securitySchemes = $document['components']['securitySchemes'] ?? [];
    if (($securitySchemes['session']['type'] ?? null) !== 'apiKey'
        || ($securitySchemes['session']['in'] ?? null) !== 'cookie') {
        authIssue($issues, 'The Gateway session cookie security scheme must be declared.');
    }
    $sessionSecurity = false;
    foreach ($operation['security'] ?? [] as $requirement) {
        if (is_array($requirement) && array_key_exists('session', $requirement)) {
            $sessionSecurity = true;
        }
    }
    if (!$sessionSecurity) {
        authIssue($issues, 'The auth operation must declare the Gateway session cookie security requirement.');
    }
    $csrf = array_values(array_filter(
        $operation['parameters'] ?? [],
        static fn ($parameter) => is_array($parameter)
            && ($parameter['in'] ?? null) === 'header'
            && strtoupper((string) ($parameter['name'] ?? '')) === 'X-CSRF-TOKEN'
            && ($parameter['required'] ?? false) === true,
    ));
    if (count($csrf) !== 1) {
        authIssue($issues, 'The auth operation must require the X-CSRF-TOKEN header exactly once.');
    }

    $matrix = $operation['x-srd-auth-action-contracts'] ?? null;
    $mapped = [];
    if (!is_array($matrix) || !array_is_list($matrix)) {
        authIssue($issues, 'x-srd-auth-action-contracts must be a list with one row per action.');
        $matrix = [];
    }
    foreach ($matrix as $index => $row) {
        if (!is_array($row)) {
            authIssue($issues, "Auth action mapping row {$index} must be an object.");
            continue;
        }
        $action = $row['action'] ?? null;
        if (!is_string($action) || !in_array($action, SRD_AUTH_ACTIONS, true)) {
            authIssue($issues, "Auth action mapping row {$index} has an unsupported action.");
            continue;
        }
        if (isset($mapped[$action])) {
            authIssue($issues, "Auth action mapping contains duplicate action '{$action}'.");
            continue;
        }
        $mapped[$action] = $row;
        foreach (['request', 'response', 'session'] as $field) {
            if (!is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                authIssue($issues, "Auth action '{$action}' is missing its {$field} schema or requirement.");
            }
        }
        if (is_string($row['session'] ?? null)
            && ($row['session'] !== (SRD_AUTH_SESSION_REQUIREMENTS[$action] ?? null))) {
            authIssue($issues, "Auth action '{$action}' has an incorrect session requirement.");
        }
        foreach (['request', 'response'] as $field) {
            if (is_string($row[$field] ?? null) && resolveAuthRef($document, $row[$field]) === null) {
                authIssue($issues, "Auth action '{$action}' {$field} reference is broken.");
            }
        }
    }

    foreach (SRD_AUTH_ACTIONS as $action) {
        if (!isset($mapped[$action])) {
            authIssue($issues, "Auth action '{$action}' is missing from the mapping.");
        }
    }
    foreach (array_keys($mapped) as $action) {
        if (!in_array($action, SRD_AUTH_ACTIONS, true)) {
            authIssue($issues, "Unsupported action '{$action}' is present in the mapping.");
        }
    }

    $requestSchema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
    $requestVariants = authVariantMap(is_array($requestSchema) ? $requestSchema : [], 'Auth request');
    $issues = [...$issues, ...$requestVariants['issues']];

    $responseSchema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
    $dataSchema = is_array($responseSchema) ? ($responseSchema['properties']['data'] ?? null) : null;
    $responseVariants = authVariantMap(is_array($dataSchema) ? $dataSchema : [], 'Auth response');
    $issues = [...$issues, ...$responseVariants['issues']];

    foreach (SRD_AUTH_ACTIONS as $action) {
        $row = $mapped[$action] ?? null;
        if (!is_array($row)) {
            continue;
        }
        if (is_string($row['request'] ?? null)
            && ($requestVariants['actions'][$action] ?? null) !== $row['request']) {
            authIssue($issues, "Auth action '{$action}' request mapping does not match its oneOf variant.");
        }
        if (is_string($row['response'] ?? null)
            && ($responseVariants['actions'][$action] ?? null) !== $row['response']) {
            authIssue($issues, "Auth action '{$action}' response mapping does not match its oneOf variant.");
        }
    }

    foreach (SRD_AUTH_ACTIONS as $action) {
        if (!isset($requestVariants['actions'][$action])) {
            authIssue($issues, "Auth request variant for '{$action}' is missing.");
        }
        if (!isset($responseVariants['actions'][$action])) {
            authIssue($issues, "Auth response variant for '{$action}' is missing.");
        }
    }
    foreach (array_keys($requestVariants['actions']) as $action) {
        if (!in_array($action, SRD_AUTH_ACTIONS, true)) {
            authIssue($issues, "Unsupported request variant '{$action}'.");
        }
    }
    foreach (array_keys($responseVariants['actions']) as $action) {
        if (!in_array($action, SRD_AUTH_ACTIONS, true)) {
            authIssue($issues, "Unsupported response variant '{$action}'.");
        }
    }

    $refIssues = [];
    $visitedRefs = [];
    validateAuthRefs($document, $operation, 'Auth operation', $refIssues, $visitedRefs);
    $issues = [...$issues, ...$refIssues];

    foreach ($mapped as $action => $row) {
        $ref = $row['response'] ?? null;
        if (is_string($ref)) {
            $schema = resolveAuthRef($document, $ref);
            if ($schema !== null) {
                $sensitiveVisited = [];
                inspectSensitiveAuthFields($document, $schema, "Auth response '{$action}'", $issues, $sensitiveVisited);
            }
        }
    }

    return array_values(array_unique($issues));
}

/** Return a structurally complete positive fixture for the checker self-test. */
function validAuthActionFixture(): array
{
    $actions = SRD_AUTH_ACTIONS;
    $schemas = [];
    $requestVariants = [];
    $responseVariants = [];
    $matrix = [];
    foreach ($actions as $action) {
        $requestName = 'Request_'.ucfirst($action);
        $responseName = 'Response_'.ucfirst($action);
        $schemas[$requestName] = ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]];
        $schemas[$responseName] = ['type' => 'object', 'properties' => ['result' => ['type' => 'string']]];
        $requestRef = '#/components/schemas/'.$requestName;
        $responseRef = '#/components/schemas/'.$responseName;
        $requestVariants[] = ['x-srd-action' => $action, 'allOf' => [['$ref' => $requestRef]]];
        $responseVariants[] = ['x-srd-action' => $action, 'allOf' => [['$ref' => $responseRef]]];
        $matrix[] = [
            'action' => $action,
            'request' => $requestRef,
            'response' => $responseRef,
            'session' => SRD_AUTH_SESSION_REQUIREMENTS[$action],
        ];
    }

    return [
        'openapi' => '3.0.3',
        'paths' => [
            '/auth/{action}' => ['post' => [
                'operationId' => 'post_auth_action',
                'parameters' => [
                    ['name' => 'action', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string', 'enum' => $actions]],
                    ['name' => 'X-CSRF-TOKEN', 'in' => 'header', 'required' => true,
                        'schema' => ['type' => 'string']],
                ],
                'security' => [['session' => []]],
                'requestBody' => ['required' => false, 'content' => [
                    'application/json' => ['schema' => ['oneOf' => $requestVariants]],
                ]],
                'responses' => ['200' => ['description' => 'Success', 'content' => [
                    'application/json' => ['schema' => ['type' => 'object', 'properties' => [
                        'data' => ['oneOf' => $responseVariants],
                    ]]],
                ]]],
                'x-srd-auth-action-contracts' => $matrix,
            ]],
        ],
        'components' => [
            'securitySchemes' => ['session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'srd_session']],
            'schemas' => $schemas,
        ],
    ];
}

/** @param list<string> $issues */
function assertAuthFixtureRejected(string $name, array $fixture, string $expectedMessage): void
{
    $issues = validateAuthActionContract($fixture);
    if ($issues === [] || !str_contains(implode("
", $issues), $expectedMessage)) {
        throw new RuntimeException("Negative fixture '{$name}' was not rejected for {$expectedMessage}.");
    }
}

/** Prove that positive and representative negative fixtures exercise the validator. */
function runAuthActionSelfTest(): void
{
    $valid = validAuthActionFixture();
    $issues = validateAuthActionContract($valid);
    if ($issues !== []) {
        throw new RuntimeException('Positive auth action fixture was rejected: '.implode('; ', $issues));
    }

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['parameters'][0]['schema']['enum'][] = 'login';
    assertAuthFixtureRejected('duplicate action', $fixture, 'enum must contain each');

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['parameters'][0]['schema']['enum'][] = 'me';
    assertAuthFixtureRejected('unsupported action', $fixture, 'enum must contain each');

    $fixture = $valid;
    array_pop($fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts']);
    assertAuthFixtureRejected('missing action', $fixture, 'is missing from the mapping');

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts'][1]['action'] = 'login';
    assertAuthFixtureRejected('duplicate mapping', $fixture, "contains duplicate action 'login'");

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts'][0]['request']
        = '#/components/schemas/Request_Verify';
    assertAuthFixtureRejected('wrong request schema', $fixture, 'request mapping does not match');

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts'][0]['response']
        = '#/components/schemas/Response_Verify';
    assertAuthFixtureRejected('wrong response schema', $fixture, 'response mapping does not match');

    $fixture = $valid;
    unset($fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts'][0]['request']);
    assertAuthFixtureRejected('missing schema', $fixture, 'missing its request schema');

    $fixture = $valid;
    unset($fixture['paths']['/auth/{action}']['post']['requestBody']['content']['application/json']['schema']['oneOf'][0]['x-srd-action']);
    assertAuthFixtureRejected('uncorrelated request variant', $fixture, 'missing or unsupported x-srd-action');

    $fixture = $valid;
    $fixture['paths']['/auth/{action}']['post']['x-srd-auth-action-contracts'][0]['request']
        = '#/components/schemas/Missing';
    assertAuthFixtureRejected('broken reference', $fixture, 'reference is broken');

    $fixture = $valid;
    $fixture['paths']['/auth/me'] = ['post' => ['operationId' => 'post_auth_me']];
    assertAuthFixtureRejected('internal me path', $fixture, 'must not be published');

    $fixture = $valid;
    $fixture['components']['schemas']['Response_Verify']['properties']['session_id'] = ['type' => 'string'];
    assertAuthFixtureRejected('sensitive response field', $fixture, "forbidden field 'session_id'");

    $fixture = $valid;
    $fixture['paths']['/other'] = ['get' => ['operationId' => 'post_auth_action', 'responses' => []]];
    assertAuthFixtureRejected('duplicate operationId', $fixture, 'Duplicate operationId');

    fwrite(STDOUT, "Auth action contract checker self-test passed (1 positive, 12 negative fixtures).\n");
}

function readAuthContract(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("OpenAPI file not found: {$path}");
    }
    $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($document)) {
        throw new RuntimeException('OpenAPI document root must be an object.');
    }

    return $document;
}

try {
    if (($argv[1] ?? null) === '--self-test') {
        runAuthActionSelfTest();
        exit(0);
    }

    $path = $argv[1] ?? dirname(__DIR__, 2).'/contracts/gateway.openapi.json';
    $issues = validateAuthActionContract(readAuthContract($path));
    if ($issues !== []) {
        fwrite(STDERR, implode("\n", $issues)."\n");
        exit(1);
    }

    fwrite(STDOUT, "Validated one public POST /auth/{action} operation with nine correlated request/response/session variants.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}