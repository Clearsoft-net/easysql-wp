<?php

declare(strict_types=1);

namespace EasySQL\Tests\Support;

/**
 * Singleton test server wrapper for the entire test suite.
 *
 * Manages a single PHP built-in server subprocess (wrapping HealthServer)
 * so that all test classes share the same base URL and the
 * EASYSQL_ENDPOINT constant is defined exactly once.
 *
 * Usage from any test class:
 *
 *   ApiServer::instance()->setResponse('GET /v1/connectors', 200, '...');
 */
final class ApiServer
{
    private static ?self $instance = null;
    private HealthServer $server;
    private bool $started = false;

    private function __construct()
    {
        $this->server = new HealthServer();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->server->start();
        $this->started = true;
    }

    public function stop(): void
    {
        if (! $this->started) {
            return;
        }
        $this->server->stop();
        $this->started = false;
    }

    public function getBaseUrl(): string
    {
        return $this->server->getBaseUrl();
    }

    public function setResponse(string $route, int $status, string $body = '{}'): void
    {
        $this->server->setResponse($route, $status, $body);
    }

    /**
     * Reset between test classes: restart the server on the same port (so the
     * single-threaded PHP built-in server never hits its ~70-request wall) and
     * clear any captured bodies. The new process starts with an empty state.
     * Call from setUpBeforeClass() in each test class.
     */
    public function clearState(): void
    {
        $this->server->restart();
        $this->server->clearCapture();
    }

    /**
     * Clear the captured request bodies from the test server.
     */
    public function clearCapture(): void
    {
        $this->server->clearCapture();
    }

    /**
     * Return the raw body captured for a route (e.g. 'POST /v1/connectors'),
     * or null if none was captured.
     */
    public function getCapturedBody(string $route): ?string
    {
        return $this->server->getCapturedBody($route);
    }
}
