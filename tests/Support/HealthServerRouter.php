<?php
/**
 * PHP built-in server router for the EasySQL test harness.
 *
 * Routes are captured and stubbed over real HTTP to exercise the Composer SDK
 * and plugin against the current schema-only API contract.
 */

$stateFile = sys_get_temp_dir() . '/easysql_test_state.json';
$captureFile = sys_get_temp_dir() . '/easysql_test_capture.json';

$readJson = function (string $file): array {
    if (! is_file($file)) {
        return [];
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return [];
    }
    flock($fp, LOCK_SH);
    // stream_get_contents() instead of fread(filesize()): the stat cache is not
    // invalidated by a write performed earlier in the same request, which made
    // the second read of a just-written file truncate the JSON.
    $contents = (string) stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($contents, true);
    return is_array($data) ? $data : [];
};

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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $path === '/__set_response') {
    $incoming = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($incoming)) {
        $writeJson($stateFile, array_merge($readJson($stateFile), $incoming));
    }
    http_response_code(204);
    return true;
}

if ($method === 'POST' && $path === '/__clear_capture') {
    @unlink($captureFile);
    http_response_code(204);
    return true;
}
if ($method === 'GET' && $path === '/__get_capture') {
    header('Content-Type: application/json');
    echo json_encode($readJson($captureFile));
    return true;
}

$state = $readJson($stateFile);
if ($method !== 'GET' || ! str_starts_with((string) $path, '/__')) {
    $capture = $readJson($captureFile);
    $capture[$method . ' ' . $path] = (string) file_get_contents('php://input');
    $writeJson($captureFile, $capture);
}
$serve = function (string $route) use ($state): void {
    $defaults = [
        'GET /v1/health' => [200, '{}'],
        'POST /v1/queries' => [200, json_encode([
            'id' => 'qry_test_1',
            'question' => 'How many users?',
            'sql_generated' => 'SELECT COUNT(*) AS n FROM wp_users',
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2025-01-01T00:00:00Z',
        ])],
        'GET /v1/queries' => [200, '{"items":[],"next_cursor":null}'],
        'GET /v1/connections' => [200, '[]'],
        'GET /v1/connections/{id}' => [404, '{"detail":"Connection not found"}'],
        'POST /v1/connections' => [201, json_encode([
            'id' => 'conn_default',
            'name' => 'WordPress',
            'type' => 'mysql',
            'last_sync_at' => null,
        ])],
        'POST /v1/connections/{id}/sync' => [200, '{}'],
    ];

    $entry = $state[$route] ?? null;
    if (is_array($entry)) {
        $status = isset($entry['status']) ? (int) $entry['status'] : 200;
        $body = isset($entry['body']) && is_string($entry['body']) ? $entry['body'] : '{}';
    } elseif (isset($defaults[$route])) {
        [$status, $body] = $defaults[$route];
    } else {
        $status = 404;
        $body = '{"detail":"not found"}';
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo $body;
};

if ($path === '/v1/health' && $method === 'GET') {
    $serve('GET /v1/health');
    return true;
}
if ($path === '/v1/queries' && $method === 'POST') {
    $serve('POST /v1/queries');
    return true;
}
if ($path === '/v1/queries' && $method === 'GET') {
    $serve('GET /v1/queries');
    return true;
}
if ($path === '/v1/connections' && $method === 'GET') {
    $serve('GET /v1/connections');
    return true;
}
if ($method === 'GET' && preg_match('#^/v1/connections/[^/]+$#', $path)) {
    $serve('GET /v1/connections/{id}');
    return true;
}
if ($path === '/v1/connections' && $method === 'POST') {
    $capture = $readJson($captureFile);
    $capture['POST /v1/connections'] = (string) file_get_contents('php://input');
    $writeJson($captureFile, $capture);
    $serve('POST /v1/connections');
    return true;
}
if ($method === 'POST' && preg_match('#^/v1/connections/[^/]+/sync$#', $path)) {
    $capture = $readJson($captureFile);
    $capture['POST /v1/connections/{id}/sync'] = (string) file_get_contents('php://input');
    $writeJson($captureFile, $capture);
    $serve('POST /v1/connections/{id}/sync');
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"detail":"not found"}';
return true;
