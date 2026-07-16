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
     * Remove the state file so the next test class starts with a clean
     * slate. Call from setUpBeforeClass() in each test class.
     */
    public function clearState(): void
    {
        $this->server->removeStateFile();
    }
}
