<?php

declare(strict_types=1);

namespace EasySQL\Tests\Integration;

use Clearsoft\EasySQL\Connectors\ConnectorEngine;
use Clearsoft\EasySQL\Connectors\ConnectorException;
use Clearsoft\EasySQL\Connectors\ConnectorFactory;
use Clearsoft\EasySQL\Connectors\ConnectorInterface;
use Clearsoft\EasySQL\SchemaGeneration\SchemaGenerator;
use EasySQL\LocalDatabase;
use EasySQL\QueryService;
use EasySQL\Tests\Support\ApiServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the EasySQL MySQL connector against a real MySQL server.
 *
 * Skipped when no server is reachable — `make db-up` starts the container
 * used by `make test-integration` (127.0.0.1:3307 by default).
 */
#[CoversClass(LocalDatabase::class)]
final class LocalDatabaseIntegrationTest extends TestCase
{
    private const TABLE = 'easysql_integration_orders';

    private static ApiServer $server;

    private \PDO $pdo;

    private ConnectorInterface $connector;

    public static function setUpBeforeClass(): void
    {
        self::$server = ApiServer::instance();
        self::$server->clearState();
    }

    protected function setUp(): void
    {
        if (! in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not available in this PHP runtime.');
        }

        try {
            $this->pdo = new \PDO(
                'mysql:host=' . self::host() . ';dbname=' . self::database(),
                self::user(),
                self::password(),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $error) {
            $this->markTestSkipped(
                'MySQL test server is not reachable at ' . self::host() . ': ' . $error->getMessage()
            );
        }

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' email VARCHAR(190) NOT NULL,'
            . ' total DECIMAL(10,2) NOT NULL DEFAULT 0.00'
            . ')'
        );
        $this->pdo->exec(
            'INSERT INTO ' . self::TABLE . " (email, total) VALUES ('a@example.com', '10.50'), ('b@example.com', '20.00')"
        );

        $this->connector = ConnectorFactory::create(
            ConnectorEngine::MySQL,
            LocalDatabase::connection_config(self::credentials())
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connector)) {
            $this->connector->close();
        }

        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    #[Test]
    public function introspects_a_real_database_and_generates_the_canonical_payload(): void
    {
        $this->connector->connect();
        $raw = $this->connector->introspect();

        $this->assertSame('mysql', $raw['engine']);

        $tables = array_column($raw['tables'], null, 'name');
        $this->assertArrayHasKey(self::TABLE, $tables);

        $columns = array_column($tables[self::TABLE]['columns'], null, 'name');
        $this->assertTrue($columns['id']['primaryKey']);
        $this->assertSame('int', $columns['id']['dataType']);
        $this->assertSame('varchar', $columns['email']['dataType']);
        $this->assertFalse($columns['email']['nullable']);
        $this->assertSame(1, $columns['id']['ordinal']);

        $payload = (new SchemaGenerator())->generate($raw);
        $generated = array_column($payload, null, 'name')[self::TABLE];
        $this->assertSame('integer', $generated['columns'][0]['type']);
        $this->assertSame('string', $generated['columns'][1]['type']);
        $this->assertSame('decimal', $generated['columns'][2]['type']);
        $this->assertTrue($generated['columns'][0]['primary_key']);
    }

    #[Test]
    public function executes_select_queries_and_returns_rows(): void
    {
        $this->connector->connect();
        $result = $this->connector->execute(
            'SELECT email, total FROM ' . self::TABLE . ' ORDER BY id LIMIT 10'
        );

        $this->assertSame(['email', 'total'], $result['columns']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame('a@example.com', $result['rows'][0]['email']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
    }

    #[Test]
    public function connects_through_a_unix_socket_when_one_is_available(): void
    {
        $socket = getenv('EASYSQL_TEST_DB_SOCKET');
        if (! is_string($socket) || '' === $socket || ! file_exists($socket)) {
            $this->markTestSkipped('Set EASYSQL_TEST_DB_SOCKET (make db-up) to exercise the Unix socket path.');
        }

        $connector = ConnectorFactory::create(
            ConnectorEngine::MySQL,
            LocalDatabase::connection_config([
                'host' => 'localhost:' . $socket,
                'user' => self::user(),
                'password' => self::password(),
                'database' => self::database(),
            ])
        );

        $connector->connect();
        try {
            $result = $connector->execute('SELECT COUNT(*) AS total FROM ' . self::TABLE);
            $this->assertSame(2, (int) $result['rows'][0]['total']);
        } finally {
            $connector->close();
        }
    }

    #[Test]
    public function refuses_mutating_sql_before_hitting_the_database(): void
    {
        $this->connector->connect();

        try {
            $this->connector->execute('DELETE FROM ' . self::TABLE);
            $this->fail('Mutating SQL was executed.');
        } catch (ConnectorException $error) {
            $this->assertStringContainsString('safety check', $error->getMessage());
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    #[Test]
    public function query_service_runs_generated_sql_locally_through_the_sdk_connector(): void
    {
        $GLOBALS['__easysql_test_options'] = [
            'easysql_settings' => [
                'api_key' => 'test-token-do-not-care',
                'timeout' => 5,
            ],
        ];
        self::$server->setResponse('POST /v1/queries', 200, json_encode([
            'id' => 'query_integration',
            'question' => 'How many orders are there?',
            'sql_generated' => 'SELECT COUNT(*) AS total FROM ' . self::TABLE,
            'error' => null,
            'needs_local_execution' => true,
            'status' => 'ready',
            'created_at' => '2026-01-01T00:00:00Z',
        ]));

        $service = new QueryService(fn (): ConnectorInterface => ConnectorFactory::create(
            ConnectorEngine::MySQL,
            LocalDatabase::connection_config(self::credentials())
        ));

        $result = $service->query('00000000-0000-4000-8000-000000000001', 'How many orders are there?');

        $this->assertNull($result['error'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertSame(1, $result['row_count']);
        $this->assertSame('2', (string) $result['result_data'][0]['total']);
        $this->assertStringContainsString('1 row returned', $result['answer']);
    }

    /**
     * @return array{host: string, user: string, password: string, database: string}
     */
    private static function credentials(): array
    {
        return [
            'host' => self::host(),
            'user' => self::user(),
            'password' => self::password(),
            'database' => self::database(),
        ];
    }

    private static function host(): string
    {
        return getenv('EASYSQL_TEST_DB_HOST') ?: '127.0.0.1:3307';
    }

    private static function user(): string
    {
        return getenv('EASYSQL_TEST_DB_USER') ?: 'root';
    }

    private static function password(): string
    {
        return getenv('EASYSQL_TEST_DB_PASSWORD') ?: '';
    }

    private static function database(): string
    {
        return getenv('EASYSQL_TEST_DB_NAME') ?: 'wordpress';
    }
}
