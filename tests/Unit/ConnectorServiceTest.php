<?php

declare(strict_types=1);

namespace EasySQL\Tests\Unit;

use EasySQL\ConnectorService;
use EasySQL\QueryService;
use EasySQL\Tests\Support\ApiServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ConnectorService — connector lifecycle and sync.
 *
 * Uses the shared ApiServer (real PHP built-in server) so every HTTP
 * call goes through the real Clearsoft\EasySQL\SDK\Client on the wire.
 *
 * These tests guard against the regression fixed in get_or_create() and
 * sync() where a missing/null 'id' field in the API response would cause
 * rawurlencode(null) to throw a TypeError.
 */
#[CoversClass(ConnectorService::class)]
final class ConnectorServiceTest extends TestCase
{
    private const ROUTE_CONNECTORS     = 'GET /v1/connectors';
    private const ROUTE_CONNECTORS_POST = 'POST /v1/connectors';
    private const ROUTE_SYNC            = 'POST /v1/connectors/{id}/sync';

    private static ApiServer $server;
    private QueryService $query_service;
    private ConnectorService $connector_service;

    public static function setUpBeforeClass(): void
    {
        self::$server = ApiServer::instance();
        self::$server->clearState();
    }

    protected function setUp(): void
    {
        $this->query_service      = new QueryService();
        $this->connector_service  = new ConnectorService($this->query_service);

        $GLOBALS['__easysql_test_options'] = [
            'easysql_settings' => [
                'api_key' => 'test-token-do-not-care',
                'timeout' => 5,
            ],
            // No easysql_connector — simulates fresh state for each test.
        ];
    }

    // ── sync() ────────────────────────────────────────────────

    #[Test]
    public function sync_returns_success_when_connector_has_id(): void
    {
        // The "wp" connector is found in the list with a valid id.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, json_encode([
            [
                'id'           => 'conn_wp_001',
                'name'         => 'wp',
                'last_sync_at' => null,
            ],
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 200, '{}');

        $result = $this->connector_service->sync();

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function sync_returns_success_when_connector_is_created(): void
    {
        // No existing connector — will create one.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 200, json_encode([
            'id'           => 'conn_wp_002',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 200, '{}');

        $result = $this->connector_service->sync();

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function sync_returns_error_when_connector_list_missing_id(): void
    {
        // The "wp" connector exists but has no 'id' field — the regression
        // that caused rawurlencode(null).
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, json_encode([
            [
                'name'         => 'wp',
                'last_sync_at' => null,
                // no 'id' key
            ],
        ]));

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no valid &quot;id&quot;', $result['message']);
    }

    #[Test]
    public function sync_returns_error_when_created_connector_missing_id(): void
    {
        // No existing connector, and creation returns a body without 'id'.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 200, json_encode([
            'name'         => 'wp',
            'last_sync_at' => null,
            // no 'id' key
        ]));

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not return a valid connector &quot;id&quot;', $result['message']);
    }

    #[Test]
    public function sync_passes_through_connector_cache(): void
    {
        // Seed the cache with a valid connector.
        $GLOBALS['__easysql_test_options']['easysql_connector'] = [
            'id'           => 'conn_wp_cached',
            'name'         => 'wp',
            'last_sync_at' => null,
        ];

        self::$server->setResponse(self::ROUTE_SYNC, 200, '{}');

        $result = $this->connector_service->sync();

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function sync_returns_error_when_api_call_fails(): void
    {
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, json_encode([
            [
                'id'           => 'conn_wp_003',
                'name'         => 'wp',
                'last_sync_at' => null,
            ],
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 500, '{"detail":"Internal server error"}');

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Internal server error', $result['message']);
    }

    #[Test]
    public function sync_returns_error_when_list_connectors_fails(): void
    {
        // GET /v1/connectors returns an error (e.g., expired token).
        self::$server->setResponse(self::ROUTE_CONNECTORS, 401, '{"detail":"Invalid or expired token"}');

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid or expired token', $result['message']);
    }

    #[Test]
    public function sync_returns_error_when_create_connector_fails(): void
    {
        // No existing "wp" connector, and creation fails (e.g., duplicate).
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 409, '{"detail":"Connector \\"wp\\" already exists"}');

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['message']);
    }
}
