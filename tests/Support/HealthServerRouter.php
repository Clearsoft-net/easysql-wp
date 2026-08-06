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

$stateFile     = sys_get_temp_dir() . '/easysql_test_state.json';
$captureFile   = sys_get_temp_dir() . '/easysql_test_capture.json';

/**
 * Read a JSON file safely (process-safe for php -S workers).
 */
$readJson = function (string $file): array {
    if (! is_file($file)) {
        return [];
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return [];
    }
    flock($fp, LOCK_SH);
    $data = json_decode((string) fread($fp, max(filesize($file), 1)), true);
    flock($fp, LOCK_UN);
    fclose($fp);
    return is_array($data) ? $data : [];
};

/**
 * Write a JSON file safely (process-safe for php -S workers).
 */
$writeJson = function (string $file, array $data): void {
    $fp = @fopen($file, 'cb');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
};

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $path === '/__set_response') {
    $incoming = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($incoming)) {
        $existing = $readJson($stateFile);
        $writeJson($stateFile, array_merge($existing, $incoming));
    }
    http_response_code(204);
    return true;
}

// Capture the raw body of the last request to a given route, so tests can
// assert what the plugin actually sends (e.g. schema vs credentials).
if ($method === 'POST' && $path === '/__clear_capture') {
    @unlink($captureFile);
    http_response_code(204);
    return true;
}
if ($method === 'GET' && $path === '/__get_capture') {
    $capture = $readJson($captureFile);
    header('Content-Type: application/json');
    echo json_encode($capture);
    return true;
}

$state = $readJson($stateFile);

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
        'POST /v1/queries/{id}/answer' => [200, '{}'],
        'GET /v1/connectors'           => [200, '[]'],
        'GET /v1/connectors/{id}'      => [404, '{"detail":"Connector not found"}'],
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

// POST /v1/queries/{id}/answer
if ($method === 'POST' && preg_match('#^/v1/queries/[^/]+/answer$#', $path)) {
    $body = (string) file_get_contents('php://input');
    $capture = $readJson($captureFile);
    $capture['POST /v1/queries/{id}/answer'] = $body;
    $writeJson($captureFile, $capture);
    $serve('POST /v1/queries/{id}/answer');
    return true;
}

if ($path === '/v1/connectors' && $method === 'GET') {
    $serve('GET /v1/connectors');
    return true;
}

// GET /v1/connectors/{id}
if ($method === 'GET' && preg_match('#^/v1/connectors/[^/]+$#', $path)) {
    $serve('GET /v1/connectors/{id}');
    return true;
}

if ($path === '/v1/connectors' && $method === 'POST') {
    $body = (string) file_get_contents('php://input');
    $capture = $readJson($captureFile);
    $capture['POST /v1/connectors'] = $body;
    $writeJson($captureFile, $capture);
    $serve('POST /v1/connectors');
    return true;
}

// POST /v1/connectors/{id}/sync
if ($method === 'POST' && preg_match('#^/v1/connectors/[^/]+/sync$#', $path)) {
    $body = (string) file_get_contents('php://input');
    $capture = $readJson($captureFile);
    $capture['POST /v1/connectors/{id}/sync'] = $body;
    $writeJson($captureFile, $capture);
    $serve('POST /v1/connectors/{id}/sync');
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"detail":"not found"}';
return true;
