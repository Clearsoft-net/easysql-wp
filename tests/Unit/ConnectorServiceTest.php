<?php

declare(strict_types=1);

namespace EasySQL\Tests\Unit;

use EasySQL\ConnectorService;
use EasySQL\QueryService;
use EasySQL\Tests\Support\ApiServer;
use EasySQL\Tests\Support\FakeConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectorService::class)]
final class ConnectorServiceTest extends TestCase
{
    private const ROUTE_CONNECTIONS = 'GET /v1/connections';
    private const ROUTE_CONNECTION_GET = 'GET /v1/connections/{id}';
    private const ROUTE_CONNECTION_POST = 'POST /v1/connections';
    private const ROUTE_SYNC = 'POST /v1/connections/{id}/sync';

    private static ApiServer $server;
    private QueryService $query_service;
    private ConnectorService $connector_service;
    private FakeConnector $connector;

    public static function setUpBeforeClass(): void
    {
        self::$server = ApiServer::instance();
        self::$server->clearState();
    }

    protected function setUp(): void
    {
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }

        $GLOBALS['wpdb'] = new class() {
            public function db_version(): string
            {
                return '5.7.44';
            }
        };

        $this->connector = new FakeConnector(self::rawSchema());
        $this->query_service = new QueryService(fn (): FakeConnector => $this->connector);
        $this->connector_service = new ConnectorService(
            $this->query_service,
            fn (): FakeConnector => $this->connector
        );

        $GLOBALS['__easysql_test_options'] = [
            'easysql_settings' => [
                'api_key' => 'test-token-do-not-care',
                'timeout' => 5,
            ],
            'siteurl' => 'https://example.test',
        ];
    }

    #[Test]
    public function get_or_create_creates_and_caches_site_connection(): void
    {
        self::$server->setResponse(self::ROUTE_CONNECTIONS, 200, '[]');
        self::$server->setResponse(self::ROUTE_CONNECTION_POST, 201, json_encode([
            'id' => 'connection_wp_001',
            'name' => 'WordPress - https://example.test',
            'type' => 'mysql',
            'last_sync_at' => null,
        ]));
        self::$server->clearCapture();

        $connection = $this->connector_service->get_or_create();

        $this->assertSame('connection_wp_001', $connection['id']);
        $this->assertSame('WordPress - https://example.test', $connection['name']);
        $this->assertSame(
            'connection_wp_001',
            \EasySQL\get_option('easysql_connection')['id']
        );

        $payload = json_decode(
            (string) self::$server->getCapturedBody(self::ROUTE_CONNECTION_POST),
            true
        );
        $this->assertIsArray($payload);
        $this->assertSame('wp', $payload['type']);
        $this->assertSame('5.7.44', $payload['db_version']);
        $this->assertArrayNotHasKey('config', $payload);
        $this->assertArrayNotHasKey('host', $payload);
        $this->assertArrayNotHasKey('username', $payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertContains('wp_users', array_column($payload['schema'], 'name'));
        $users = $payload['schema'][array_search('wp_users', array_column($payload['schema'], 'name'), true)];
        $this->assertSame('bigint', $users['columns'][0]['type']);
        $this->assertTrue($users['columns'][0]['primary_key']);

        $this->assertGreaterThanOrEqual(1, $this->connector->connectCalls());
        $this->assertFalse($this->connector->isConnected());
    }

    #[Test]
    public function get_or_create_uses_existing_named_connection(): void
    {
        self::$server->setResponse(self::ROUTE_CONNECTIONS, 200, json_encode([
            [
                'id' => 'connection_wp_existing',
                'name' => 'WordPress - https://example.test',
                'type' => 'mysql',
            ],
        ]));

        $connection = $this->connector_service->get_or_create();

        $this->assertSame('connection_wp_existing', $connection['id']);
    }

    #[Test]
    public function get_or_create_reuses_a_valid_cached_connection(): void
    {
        $GLOBALS['__easysql_test_options']['easysql_connection'] = [
            'id' => 'connection_wp_cached',
            'name' => 'WordPress - https://example.test',
        ];
        self::$server->setResponse(self::ROUTE_CONNECTION_GET, 200, json_encode([
            'id' => 'connection_wp_cached',
            'name' => 'WordPress - https://example.test',
            'type' => 'mysql',
        ]));

        $connection = $this->connector_service->get_or_create();

        $this->assertSame('connection_wp_cached', $connection['id']);
    }

    #[Test]
    public function get_or_create_migrates_legacy_connector_cache(): void
    {
        $GLOBALS['__easysql_test_options']['easysql_connector'] = [
            'id' => 'connection_wp_legacy',
            'name' => 'WordPress - https://example.test',
        ];
        self::$server->setResponse(self::ROUTE_CONNECTION_GET, 200, json_encode([
            'id' => 'connection_wp_legacy',
            'name' => 'WordPress - https://example.test',
            'type' => 'mysql',
        ]));

        $connection = $this->connector_service->get_or_create();

        $this->assertSame('connection_wp_legacy', $connection['id']);
        $this->assertArrayHasKey('easysql_connection', $GLOBALS['__easysql_test_options']);
        $this->assertArrayNotHasKey('easysql_connector', $GLOBALS['__easysql_test_options']);
    }

    #[Test]
    public function get_or_create_reports_local_connector_failures(): void
    {
        $this->connector->withIntrospectError(
            new \RuntimeException('The pdo_mysql PHP extension is required for EasySQL to read the WordPress schema and run queries locally.')
        );
        self::$server->setResponse(self::ROUTE_CONNECTIONS, 200, '[]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pdo_mysql');

        $this->connector_service->get_or_create();
    }

    #[Test]
    public function sync_pushes_the_canonical_wordpress_schema_and_database_version(): void
    {
        $GLOBALS['__easysql_test_options']['easysql_connection'] = [
            'id' => 'connection_wp_sync',
            'name' => 'WordPress - https://example.test',
        ];
        self::$server->setResponse(self::ROUTE_CONNECTION_GET, 200, json_encode([
            'id' => 'connection_wp_sync',
            'name' => 'WordPress - https://example.test',
            'type' => 'mysql',
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 200, '{}');
        self::$server->clearCapture();

        $result = $this->connector_service->sync();

        $this->assertTrue($result['success']);
        $payload = json_decode((string) self::$server->getCapturedBody(self::ROUTE_SYNC), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertArrayNotHasKey('config', $payload);
        $this->assertSame(['wp_posts', 'wp_users'], array_column($payload['schema'], 'name'));
        $this->assertSame(
            ['table' => 'wp_users', 'column' => 'ID'],
            $payload['schema'][0]['columns'][1]['foreign_key']
        );
        $this->assertSame('5.7.44', $payload['db_version']);
        $this->assertFalse($this->connector->isConnected());
    }

    #[Test]
    public function sync_returns_error_when_api_call_fails(): void
    {
        $GLOBALS['__easysql_test_options']['easysql_connection'] = [
            'id' => 'connection_wp_error',
            'name' => 'WordPress - https://example.test',
        ];
        self::$server->setResponse(self::ROUTE_CONNECTION_GET, 200, json_encode([
            'id' => 'connection_wp_error',
            'name' => 'WordPress - https://example.test',
            'type' => 'mysql',
        ]));
        self::$server->setResponse(self::ROUTE_SYNC, 500, '{"detail":"Internal server error"}');

        $result = $this->connector_service->sync();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Internal server error', $result['message']);
    }

    /**
     * Raw introspection output for a WordPress-like database, matching the
     * shape produced by the EasySQL connectors package.
     *
     * @return array{engine: string, tables: list<array<string, mixed>>}
     */
    private static function rawSchema(): array
    {
        return [
            'engine' => 'mysql',
            'tables' => [
                [
                    'name' => 'wp_users',
                    'columns' => [
                        [
                            'name' => 'ID',
                            'dataType' => 'bigint',
                            'nullable' => false,
                            'primaryKey' => true,
                            'defaultValue' => null,
                            'foreignKey' => null,
                            'ordinal' => 1,
                        ],
                        [
                            'name' => 'user_login',
                            'dataType' => 'varchar',
                            'nullable' => false,
                            'primaryKey' => false,
                            'defaultValue' => '',
                            'foreignKey' => null,
                            'ordinal' => 2,
                        ],
                    ],
                    'rowsApprox' => 10,
                ],
                [
                    'name' => 'wp_posts',
                    'columns' => [
                        [
                            'name' => 'ID',
                            'dataType' => 'bigint',
                            'nullable' => false,
                            'primaryKey' => true,
                            'defaultValue' => null,
                            'foreignKey' => null,
                            'ordinal' => 1,
                        ],
                        [
                            'name' => 'post_author',
                            'dataType' => 'bigint',
                            'nullable' => false,
                            'primaryKey' => false,
                            'defaultValue' => '0',
                            'foreignKey' => ['table' => 'wp_users', 'column' => 'ID'],
                            'ordinal' => 2,
                        ],
                    ],
                    'rowsApprox' => 5,
                ],
            ],
        ];
    }
}
