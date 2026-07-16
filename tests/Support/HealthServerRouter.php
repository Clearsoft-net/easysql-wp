<?php
/**
 * PHP built-in server router for the EasySQL test harness.
 *
 * Returns canned responses for the real SDK endpoints the plugin
 * exercises:
 *   - GET  /v1/health               → per-path status/body from state file
 *   - POST /v1/queries              → per-path status/body from state file
 *   - GET  /v1/connectors           → per-path status/body from state file
 *   - POST /v1/connectors           → per-path status/body from state file
 *   - POST /v1/connectors/{id}/sync → per-path status/body from state file
 *
 * The state file is a JSON object keyed by request route:
 *   { "GET /v1/health":              {"status": 200, "body": "{...}"},
 *     "POST /v1/queries":            {"status": 401, "body": "{...}"},
 *     "GET /v1/connectors":          {"status": 200, "body": "{...}"},
 *     "POST /v1/connectors":         {"status": 200, "body": "{...}"},
 *     "POST /v1/connectors/{id}/sync": {"status": 200, "body": "{}"} }
 *
 * The test writes entries via POST /__set_response with body
 * {"route": "GET /v1/health", "status": 200, "body": "..."}. Defaults are
 * applied per-route when no entry exists.
 *
 * This is the ONLY way the test interacts with the "API server" — no
 * in-process fakes, no SDK subclassing, no Guzzle handlers. The test
 * starts a real PHP subprocess that serves real HTTP, the real
 * Clearsoft\EasySQL\SDK\Client makes real HTTP requests, and this
 * router returns real HTTP responses.
 */

$stateFile = sys_get_temp_dir() . '/easysql_test_state.json';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $path === '/__set_response') {
    $incoming = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($incoming)) {
        $existing = is_file($stateFile)
            ? (json_decode((string) file_get_contents($stateFile), true) ?: [])
            : [];
        file_put_contents($stateFile, json_encode(array_merge($existing, $incoming)));
    }
    http_response_code(204);
    return true;
}

$state = is_file($stateFile)
    ? (json_decode((string) file_get_contents($stateFile), true) ?: [])
    : [];

/** Look up a route in the state, with fallback to defaults. */
$serve = function (string $route) use ($state): void {
    $defaults = [
        'GET /v1/health'               => [200, '{}'],
        'POST /v1/queries'             => [200, json_encode([
            'id'            => 'qry_test_1',
            'question'      => 'How many users?',
            'sql_generated' => 'SELECT COUNT(*) AS n FROM users',
            'answer'        => 'There are 42 users.',
            'chart_config'  => null,
            'error'         => null,
            'result_data'   => [['n' => 42]],
            'created_at'    => '2025-01-01T00:00:00Z',
        ])],
        'GET /v1/connectors'           => [200, '[]'],
        'POST /v1/connectors'          => [200, json_encode([
            'id'   => 'conn_default',
            'name' => 'wp',
        ])],
        'POST /v1/connectors/{id}/sync' => [200, '{}'],
    ];

    $entry = $state[$route] ?? null;
    if (is_array($entry)) {
        $status = isset($entry['status']) ? (int) $entry['status'] : 200;
        $body   = isset($entry['body']) && is_string($entry['body'])
            ? $entry['body']
            : '{}';
    } elseif (isset($defaults[$route])) {
        [$status, $body] = $defaults[$route];
    } else {
        $status = 404;
        $body   = '{"detail":"not found"}';
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo $body;
};

// ── Route matching ──────────────────────────────────────────────────────────

if ($path === '/v1/health' && $method === 'GET') {
    $serve('GET /v1/health');
    return true;
}

if ($path === '/v1/queries' && $method === 'POST') {
    $serve('POST /v1/queries');
    return true;
}

if ($path === '/v1/connectors' && $method === 'GET') {
    $serve('GET /v1/connectors');
    return true;
}

if ($path === '/v1/connectors' && $method === 'POST') {
    $serve('POST /v1/connectors');
    return true;
}

// POST /v1/connectors/{id}/sync
if ($method === 'POST' && preg_match('#^/v1/connectors/[^/]+/sync$#', $path)) {
    $serve('POST /v1/connectors/{id}/sync');
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"detail":"not found"}';
return true;
