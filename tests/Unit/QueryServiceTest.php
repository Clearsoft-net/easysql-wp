<?php

declare(strict_types=1);

namespace EasySQL\Tests\Unit;

use EasySQL\QueryService;
use EasySQL\Tests\Support\ApiServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the plugin's real-API flows.
 *
 * No fakes, no mocks, no SDK subclassing. We:
 *   1. Use the shared ApiServer (PHP built-in server) returning canned
 *      responses for /v1/health and /v1/queries.
 *   2. Configure the real Clearsoft\EasySQL\SDK\Client (constructed by
 *      QueryService::client()) with the test server's base_url via the
 *      EASYSQL_ENDPOINT constant (defined in bootstrap.php).
 *   3. Drive the real QueryService methods and assert.
 *
 * The only thing under our control is what the test server returns;
 * everything between QueryService and the wire is real production code.
 */
#[CoversClass(QueryService::class)]
final class QueryServiceTest extends TestCase
{
    private const ROUTE_HEALTH        = 'GET /v1/health';
    private const ROUTE_QUERIES       = 'POST /v1/queries';
    private const ROUTE_QUERIES_ANSWER = 'POST /v1/queries/{id}/answer';

    private static ApiServer $server;
    private QueryService $service;

    public static function setUpBeforeClass(): void
    {
        self::$server = ApiServer::instance();
        self::$server->clearState();
    }

    protected function setUp(): void
    {
        $this->service = new QueryService();
        $GLOBALS['__easysql_test_options'] = [
            'easysql_settings' => [
                'api_key' => 'test-token-do-not-care',
                'timeout' => 5,
            ],
        ];
        // Tests must not inherit a fake $wpdb left by other test classes.
        unset($GLOBALS['wpdb']);
    }

    // ── test_connection() ─────────────────────────────────────

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
    public function test_connection_returns_failure_with_detail_when_api_rejects_token(): void
    {
        self::$server->setResponse(self::ROUTE_HEALTH, 401, '{"detail":"Invalid or expired token"}');

        $result = $this->service->test_connection();

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid or expired token', $result['message']);
    }

    #[Test]
    public function test_connection_returns_failure_with_status_code_when_body_has_no_message(): void
    {
        self::$server->setResponse(self::ROUTE_HEALTH, 500, '{}');

        $result = $this->service->test_connection();

        $this->assertFalse($result['success']);
        $this->assertSame('HTTP 500', $result['message']);
    }

    #[Test]
    public function test_connection_catches_runtime_exception_when_api_key_is_missing(): void
    {
        unset($GLOBALS['__easysql_test_options']['easysql_settings']['api_key']);

        $result = $this->service->test_connection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing API key', $result['message']);
    }

    // ── query() (NL → SQL) ────────────────────────────────────

    #[Test]
    public function query_returns_query_response_when_api_accepts(): void
    {
        $responseBody = json_encode([
            'id'            => 'qry_abc',
            'question'      => 'How many users?',
            'sql_generated' => 'SELECT COUNT(*) AS n FROM users',
            'answer'        => 'There are 42 users.',
            'chart_config'  => ['type' => 'bar'],
            'error'         => null,
            'result_data'   => [['n' => 42]],
            'created_at'    => '2025-01-01T00:00:00Z',
        ]);
        self::$server->setResponse(self::ROUTE_QUERIES, 200, $responseBody);

        $result = $this->service->query('conn_123', 'How many users?');

        $this->assertSame('qry_abc', $result['id']);
        $this->assertSame('SELECT COUNT(*) AS n FROM users', $result['sql_generated']);
        $this->assertSame('There are 42 users.', $result['answer']);
        $this->assertSame([['n' => 42]], $result['result_data']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function query_returns_api_error_field_when_query_fails_on_server_side(): void
    {
        $responseBody = json_encode([
            'id'            => 'qry_failed',
            'question'      => 'Drop users?',
            'sql_generated' => null,
            'answer'        => null,
            'chart_config'  => null,
            'error'         => 'Operation not permitted on this connector',
            'result_data'   => null,
            'created_at'    => '2025-01-01T00:00:00Z',
        ]);
        self::$server->setResponse(self::ROUTE_QUERIES, 200, $responseBody);

        $result = $this->service->query('conn_123', 'Drop users?');

        $this->assertSame('qry_failed', $result['id']);
        $this->assertSame('Operation not permitted on this connector', $result['error']);
    }

    #[Test]
    public function query_returns_error_shape_when_http_call_fails(): void
    {
        self::$server->setResponse(self::ROUTE_QUERIES, 422, '{"detail":[{"msg":"Invalid connector_id"}]}');

        $result = $this->service->query('conn_bogus', 'How many?');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid connector_id', $result['error']);
    }

    #[Test]
    public function query_catches_runtime_exception_when_api_key_is_missing(): void
    {
        unset($GLOBALS['__easysql_test_options']['easysql_settings']['api_key']);

        $result = $this->service->query('conn_123', 'How many?');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing API key', $result['error']);
    }

    #[Test]
    public function query_executes_locally_and_submits_result_when_needs_local_execution(): void
    {
        // Backend returns the generated SQL and asks the plugin to execute it
        // locally (schema-only "wp" connector). The plugin must run the SQL
        // against the local $wpdb and POST the result back to /answer.
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }
        $GLOBALS['wpdb'] = new class() {
            public $dbh;
            public $last_query = '';

            public function __construct()
            {
                $this->dbh = new \stdClass();
            }

            public function get_results(string $query, string $output = OBJECT): array
            {
                $this->last_query = $query;
                if (strpos($query, 'SELECT ID, user_login FROM wp_users') !== false) {
                    $rows = [
                        ['ID' => 1, 'user_login' => 'admin'],
                        ['ID' => 2, 'user_login' => 'editor'],
                    ];
                    if ($output === \EasySQL\ARRAY_A) {
                        return $rows;
                    }
                    return array_map(function ($r) {
                        return (object) $r;
                    }, $rows);
                }
                return [];
            }
        };

        self::$server->setResponse(self::ROUTE_QUERIES, 200, json_encode([
            'id'                   => 'qry_local',
            'question'             => 'liste os usuarios',
            'sql_generated'        => 'SELECT ID, user_login FROM wp_users',
            'answer'               => null,
            'chart_config'         => null,
            'error'                => null,
            'result_data'          => null,
            'needs_local_execution' => true,
            'created_at'           => '2025-01-01T00:00:00Z',
        ]));
        self::$server->setResponse(self::ROUTE_QUERIES_ANSWER, 200, json_encode([
            'id'            => 'qry_local',
            'question'      => 'liste os usuarios',
            'sql_generated' => 'SELECT ID, user_login FROM wp_users',
            'answer'        => 'Existem 2 usuarios.',
            'chart_config'  => null,
            'error'         => null,
            'result_data'   => [
                ['ID' => 1, 'user_login' => 'admin'],
                ['ID' => 2, 'user_login' => 'editor'],
            ],
            'needs_local_execution' => true,
            'created_at'    => '2025-01-01T00:00:00Z',
        ]));
        self::$server->clearCapture();

        $result = $this->service->query('conn_wp', 'liste os usuarios');

        // The answer returned to the admin must come from the /answer call.
        $this->assertSame('Existem 2 usuarios.', $result['answer']);
        $this->assertCount(2, $result['result_data']);

        // The plugin must have POSTed the local result to /answer.
        $captured = self::$server->getCapturedBody(self::ROUTE_QUERIES_ANSWER);
        $this->assertNotNull($captured, 'Expected a POST to /v1/queries/{id}/answer');
        $payload = json_decode($captured, true);
        $this->assertIsArray($payload);
        $this->assertCount(2, $payload['result_data']);
        $this->assertSame('admin', $payload['result_data'][0]['user_login']);
    }
}
