<?php

declare(strict_types=1);

namespace EasySQL\Tests\Support;

use RuntimeException;

/**
 * Manages a real PHP built-in server subprocess for the test suite.
 *
 * - Picks a free port by briefly binding to :0.
 * - Spawns `php -S 127.0.0.1:PORT router.php` via proc_open.
 * - Exposes setResponse() to drive the canned response.
 * - Cleans up on stop().
 *
 * The test is the only consumer; no global state.
 */
final class HealthServer
{
    private $process = null;
    private $pipes   = [];
    private int $port = 0;
    private string $stateFile;

    public function __construct()
    {
        $this->stateFile = sys_get_temp_dir() . '/easysql_test_state.json';
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function start(): void
    {
        $this->port = $this->pickFreePort();

        $router = realpath(__DIR__ . '/HealthServerRouter.php');
        if ($router === false) {
            throw new RuntimeException('HealthServerRouter.php not found.');
        }

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($router),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open($cmd, $descriptors, $this->pipes, dirname($router));
        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start PHP test server.');
        }

        $this->waitUntilReady();
    }

    /**
     * Restart the server on the same port (used between test classes so the
     * single-threaded PHP built-in server, which stops accepting connections
     * after ~70 requests, never hits that wall). Keeps the base URL stable so
     * the EASYSQL_ENDPOINT constant stays valid.
     */
    public function restart(): void
    {
        $this->stop();
        $this->port = $this->port ?: $this->pickFreePort();

        $router = realpath(__DIR__ . '/HealthServerRouter.php');
        if ($router === false) {
            throw new RuntimeException('HealthServerRouter.php not found.');
        }

        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($router),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open($cmd, $descriptors, $this->pipes, dirname($router));
        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to restart PHP test server.');
        }

        $this->waitUntilReady();
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function getBaseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    public function setResponse(string $route, int $status, string $body = '{}'): void
    {
        $payload = json_encode([
            $route => ['status' => $status, 'body' => $body],
        ]);
        $url = $this->getBaseUrl() . '/__set_response';
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'ignore_errors' => true,
                'timeout'       => 5,
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            throw new RuntimeException("Failed to reach test server at {$url}.");
        }
    }

    /**
     * Delete the state file so the test server resets its canned
     * responses. Useful between test classes that share the server.
     */
    public function removeStateFile(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * Clear the captured request-body capture file.
     */
    public function clearCapture(): void
    {
        $url = $this->getBaseUrl() . '/__clear_capture';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $ctx);
    }

    /**
     * Fetch the raw body the test server captured for a route.
     */
    public function getCapturedBody(string $route): ?string
    {
        $url = $this->getBaseUrl() . '/__get_capture';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return (is_array($data) && isset($data[$route])) ? (string) $data[$route] : null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function pickFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        if ($sock === false) {
            throw new RuntimeException('Could not bind a socket to pick a free port.');
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if (!is_string($name) || !str_contains($name, ':')) {
            throw new RuntimeException('Could not determine free port.');
        }
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilReady(): void
    {
        $url = $this->getBaseUrl() . '/v1/health';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 0.5, 'ignore_errors' => true],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            if ($response !== false) {
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("Test server did not become ready on {$url}.");
    }
}
