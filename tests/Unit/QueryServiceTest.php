<?php

declare(strict_types=1);

namespace EasySQL\Tests\Unit;

use Clearsoft\EasySQL\Connectors\ConnectorException;
use EasySQL\QueryService;
use EasySQL\Tests\Support\ApiServer;
use EasySQL\Tests\Support\FakeConnector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryService::class)]
final class QueryServiceTest extends TestCase
{
    private const ROUTE_HEALTH = 'GET /v1/health';
    private const ROUTE_QUERIES = 'POST /v1/queries';
    private const ROUTE_HISTORY = 'GET /v1/queries';

    private static ApiServer $server;
    private QueryService $service;
    private FakeConnector $connector;

    public static function setUpBeforeClass(): void
    {
        self::$server = ApiServer::instance();
        self::$server->clearState();
    }

    protected function setUp(): void
    {
        $this->connector = new FakeConnector();
        $this->service = new QueryService(fn (): FakeConnector => $this->connector);
        $GLOBALS['__easysql_test_options'] = [
            'easysql_settings' => [
                'api_key' => 'test-token-do-not-care',
                'timeout' => 5,
            ],
        ];
        unset($GLOBALS['wpdb']);
    }

    #[Test]
    public function test_connection_returns_success_when_health_endpoint_responds_2xx(): void
    {
        self::$server->setResponse(self::ROUTE_HEALTH, 200, '{"status":"ok"}');
        $result = $this->service->test_connection();
        $this->assertTrue($result['success']);
        $this->assertSame('Connection successful.', $result['message']);
    }

    #[Test]
    public function test_connection_uses_message_from_response_body_when_present(): void
    {
        self::$server->setResponse(self::ROUTE_HEALTH, 200, '{"message":"all good"}');
        $result = $this->service->test_connection();
        $this->assertTrue($result['success']);
        $this->assertSame('all good', $result['message']);
    }

    #[Test]
    public function test_connection_returns_failure_when_api_rejects_token(): void
    {
        self::$server->setResponse(self::ROUTE_HEALTH, 401, '{"detail":"Invalid or expired token"}');
        $result = $this->service->test_connection();
        $this->assertFalse($result['success']);
        $this->assertSame('Invalid or expired token', $result['message']);
    }

    #[Test]
    public function test_connection_reports_missing_api_key(): void
    {
        unset($GLOBALS['__easysql_test_options']['easysql_settings']['api_key']);
        $result = $this->service->test_connection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing API key', $result['message']);
    }

    #[Test]
    public function query_generates_and_runs_sql_only_against_the_wordpress_database(): void
    {
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }
        $this->connector = new FakeConnector(
            ['engine' => 'mysql', 'tables' => []],
            [
                'columns' => ['n'],
                'rows' => [['n' => 2]],
                'row_count' => 1,
                'duration_ms' => 3,
            ]
        );
        $this->service = new QueryService(fn (): FakeConnector => $this->connector);
        self::$server->setResponse(self::ROUTE_QUERIES, 200, json_encode([
            'id' => 'query_local',
            'question' => 'Quantos usuarios existem?',
            'sql_generated' => 'SELECT COUNT(*) AS n FROM wp_users',
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2026-01-01T00:00:00Z',
        ]));
        self::$server->clearCapture();

        $result = $this->service->query('00000000-0000-4000-8000-000000000001', 'Quantos usuarios existem?');

        $this->assertSame(['SELECT COUNT(*) AS n FROM wp_users'], $this->connector->executedSql());
        $this->assertSame([['n' => 2]], $result['result_data']);
        $this->assertSame(1, $result['row_count']);
        $this->assertStringContainsString('1 row returned', $result['answer']);
        $this->assertFalse($this->connector->isConnected());
        $this->assertArrayNotHasKey(
            'POST /v1/queries/{id}/answer',
            self::$server->getCapturedRequests()
        );
    }

    #[Test]
    public function query_rejects_mutating_generated_sql_without_executing_it(): void
    {
        self::$server->setResponse(self::ROUTE_QUERIES, 200, json_encode([
            'id' => 'query_unsafe',
            'question' => 'delete users',
            'sql_generated' => 'DELETE FROM wp_users',
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2026-01-01T00:00:00Z',
        ]));

        $result = $this->service->query('00000000-0000-4000-8000-000000000001', 'delete users');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $this->connector->executedSql());
    }

    #[Test]
    public function query_rejects_cross_database_sql(): void
    {
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }
        self::$server->setResponse(self::ROUTE_QUERIES, 200, json_encode([
            'id' => 'query_cross_db',
            'question' => 'select from another db',
            'sql_generated' => 'SELECT * FROM other_db.wp_users',
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2026-01-01T00:00:00Z',
        ]));

        $result = $this->service->query('00000000-0000-4000-8000-000000000001', 'select from another db');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $this->connector->executedSql());
    }

    #[Test]
    public function query_reports_local_execution_failures(): void
    {
        $this->connector->withExecuteError(
            new ConnectorException('MySQL query execution failed: Unknown column "nope"')
        );
        self::$server->setResponse(self::ROUTE_QUERIES, 200, json_encode([
            'id' => 'query_broken',
            'question' => 'broken',
            'sql_generated' => 'SELECT nope FROM wp_users',
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2026-01-01T00:00:00Z',
        ]));

        $result = $this->service->query('00000000-0000-4000-8000-000000000001', 'broken');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown column', $result['error']);
        $this->assertFalse($this->connector->isConnected());
    }

    #[Test]
    public function list_queries_filters_history_to_the_wordpress_connection(): void
    {
        self::$server->setResponse(self::ROUTE_HISTORY, 200, json_encode([
            'items' => [
                ['id' => 'query_wp', 'connection_id' => 'wordpress-id'],
                ['id' => 'query_other', 'connection_id' => 'other-id'],
            ],
            'next_cursor' => 'next-page',
        ]));

        $result = $this->service->list_queries('wordpress-id');

        $this->assertSame(['query_wp'], array_column($result['items'], 'id'));
        $this->assertSame('next-page', $result['next_cursor']);
    }
}
