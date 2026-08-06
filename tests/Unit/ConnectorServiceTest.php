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
    private const ROUTE_CONNECTORS_GET  = 'GET /v1/connectors/{id}';
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

        // The cached connector is validated against the backend and exists.
        self::$server->setResponse(self::ROUTE_CONNECTORS_GET, 200, json_encode([
            'id'           => 'conn_wp_cached',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));
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

    // ── get_or_create() ────────────────────────────────────────────────

    #[Test]
    public function get_or_create_creates_wp_connector_from_schema(): void
    {
        // Fresh state: no cached connector, empty list → must create one
        // by sending the local schema (no DB credentials), as the plugin
        // is designed to do. Guards against the backend 422 regression
        // that surfaced as "Could not load connector." in the admin UI.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 201, json_encode([
            'id'           => 'conn_wp_created',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));

        $connector = $this->connector_service->get_or_create();

        $this->assertSame('conn_wp_created', $connector['id']);
        $this->assertSame('wp', $connector['name']);
        // Should be cached for subsequent calls.
        $this->assertSame('conn_wp_created', \EasySQL\get_option('easysql_connector')['id']);
    }

    #[Test]
    public function get_or_create_returns_error_message_when_create_rejected(): void
    {
        // Regression: if the API rejects the schema-only create request
        // (e.g. 422 "config required"), get_or_create() must surface a
        // useful message instead of a broken/empty connector.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 422, json_encode([
            'detail' => 'Field required',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field required');

        $this->connector_service->get_or_create();
    }

    // ── End-to-end payload contract (schema, not credentials) ──────────

    #[Test]
    public function get_or_create_sends_schema_without_credentials(): void
    {
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }

        // Simulate the WordPress $wpdb used by fetch_schema().
        $GLOBALS['wpdb'] = $this->buildFakeWpdb();

        // No existing connector, list is empty → plugin must create one.
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 201, json_encode([
            'id'           => 'conn_wp_e2e',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));
        self::$server->clearCapture();

        $this->connector_service->get_or_create();

        $payload = json_decode((string) self::$server->getCapturedBody(self::ROUTE_CONNECTORS_POST), true);
        $this->assertIsArray($payload);
        $this->assertSame('wp', $payload['name']);
        $this->assertSame('mysql', $payload['type']);
        // The plugin must NOT send database credentials.
        $this->assertArrayNotHasKey('config', $payload);
        // The local schema must be sent instead.
        $this->assertArrayHasKey('schema', $payload);
        $names = array_column($payload['schema'], 'name');
        $this->assertContains('wp_users', $names);
        $this->assertContains('wp_posts', $names);

        $users = $payload['schema'][0];
        $this->assertSame('ID', $users['columns'][0]['name']);
        $this->assertTrue($users['columns'][0]['primary_key']);
    }

    #[Test]
    public function get_or_create_recovers_when_cached_connector_is_stale(): void
    {
        // Regression: after the backend restarts with a fresh database, the
        // "wp" connector no longer exists there, but WordPress still has an
        // old easysql_connector cached. get_or_create() used to return the
        // stale cache blindly, causing queries to fail with "Connector not
        // found". It must validate the cache against the backend and recreate.
        $GLOBALS['__easysql_test_options']['easysql_connector'] = [
            'id'           => 'conn_orphaned',
            'name'         => 'wp',
            'last_sync_at' => null,
        ];

        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }
        $GLOBALS['wpdb'] = $this->buildFakeWpdb();

        // Backend list has NO "wp" connector (fresh backend) — the cached id
        // is gone. Creation succeeds with a new id.
        self::$server->setResponse(self::ROUTE_CONNECTORS_GET, 404, '{"detail":"Connector not found"}');
        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, json_encode([
            [
                'id'           => 'conn_pg_seed',
                'name'         => 'PostgreSQL Local',
                'last_sync_at' => null,
            ],
        ]));
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 201, json_encode([
            'id'           => 'conn_wp_fresh',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));

        $connector = $this->connector_service->get_or_create();

        // Must return the freshly created connector, not the stale cache.
        $this->assertSame('conn_wp_fresh', $connector['id']);
        $this->assertSame('wp', $connector['name']);
        // The stale cache must have been replaced.
        $this->assertSame('conn_wp_fresh', \EasySQL\get_option('easysql_connector')['id']);
    }

    #[Test]
    public function get_or_create_reuses_valid_cached_connector(): void
    {
        // Happy path: the cached connector still exists in the backend (same
        // id), so it must NOT be recreated. Guards against over-optimistic
        // invalidation that would churn the connector on every request.
        $GLOBALS['__easysql_test_options']['easysql_connector'] = [
            'id'           => 'conn_still_valid',
            'name'         => 'wp',
            'last_sync_at' => '2026-01-01T00:00:00Z',
        ];

        // Backend list still contains the same "wp" connector id.
        self::$server->setResponse(self::ROUTE_CONNECTORS_GET, 200, json_encode([
            'id'           => 'conn_still_valid',
            'name'         => 'wp',
            'last_sync_at' => '2026-01-01T00:00:00Z',
        ]));
        // If the plugin incorrectly recreates, this would be called; we expect
        // it NOT to be. Setting a 500 makes the test fail loudly if it happens.
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 500, '{}');

        $connector = $this->connector_service->get_or_create();

        $this->assertSame('conn_still_valid', $connector['id']);
        $this->assertSame('wp', $connector['name']);
    }

    #[Test]
    public function sync_sends_schema_payload(): void
    {
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }

        $GLOBALS['wpdb'] = $this->buildFakeWpdb();

        self::$server->setResponse(self::ROUTE_CONNECTORS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTORS_POST, 201, json_encode([
            'id'           => 'conn_wp_sync',
            'name'         => 'wp',
            'last_sync_at' => null,
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 200, '{}');
        self::$server->clearCapture();

        $result = $this->connector_service->sync();

        $this->assertTrue($result['success']);
        $payload = json_decode((string) self::$server->getCapturedBody(self::ROUTE_SYNC), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertArrayNotHasKey('config', $payload);
        $this->assertSame('wp_users', $payload['schema'][0]['name']);
    }

    /**
     * Build a minimal fake $wpdb that returns two tables for the schema
     * queries issued by ConnectorService::fetch_schema().
     */
    private function buildFakeWpdb(): object
    {
        return new class() {
            public $dbh;

            public function __construct()
            {
                $this->dbh = new \stdClass();
            }

            public function prepare(string $sql, ...$args): string
            {
                foreach ($args as $arg) {
                    $sql = preg_replace('/%[sd]/', (string) $arg, $sql, 1);
                }
                return $sql;
            }

            public function get_results(string $query, string $output = OBJECT): array
            {
                $is_tables = strpos($query, 'information_schema.TABLES') !== false;
                if ($is_tables) {
                    return [
                        ['name' => 'wp_users', 'rows_approx' => '10'],
                        ['name' => 'wp_posts', 'rows_approx' => '5'],
                    ];
                }
                // information_schema.COLUMNS query
                if (strpos($query, 'wp_users') !== false) {
                    return [
                        [
                            'name'         => 'ID',
                            'type'         => 'bigint(20) unsigned',
                            'nullable'     => 'NO',
                            'default'      => null,
                            'column_key'   => 'PRI',
                        ],
                        [
                            'name'         => 'user_login',
                            'type'         => 'varchar(60)',
                            'nullable'     => 'NO',
                            'default'      => '',
                            'column_key'   => '',
                        ],
                    ];
                }
                if (strpos($query, 'wp_posts') !== false) {
                    return [
                        [
                            'name'         => 'ID',
                            'type'         => 'bigint(20) unsigned',
                            'nullable'     => 'NO',
                            'default'      => null,
                            'column_key'   => 'PRI',
                        ],
                        [
                            'name'         => 'post_title',
                            'type'         => 'text',
                            'nullable'     => 'NO',
                            'default'      => '',
                            'column_key'   => '',
                        ],
                    ];
                }
                return [];
            }
        };
    }
}
