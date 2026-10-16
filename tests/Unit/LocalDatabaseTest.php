<?php

declare(strict_types=1);

namespace EasySQL\Tests\Unit;

use Clearsoft\EasySQL\Connectors\MySQL\Connector;
use EasySQL\LocalDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalDatabase::class)]
final class LocalDatabaseTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: array{0: string, 1: int|null, 2: string|null}}>
     */
    public static function hostProvider(): iterable
    {
        yield 'plain host' => ['db.internal', ['db.internal', null, null]];
        yield 'localhost' => ['localhost', ['localhost', null, null]];
        yield 'host with port' => ['127.0.0.1:3307', ['127.0.0.1', 3307, null]];
        yield 'localhost with port' => ['localhost:3306', ['localhost', 3306, null]];
        yield 'unix socket' => ['localhost:/tmp/mysql.sock', ['localhost', null, '/tmp/mysql.sock']];
        yield 'ipv6' => ['[::1]', ['::1', null, null]];
        yield 'ipv6 with port' => ['[::1]:3306', ['::1', 3306, null]];
        yield 'empty host' => ['', ['', null, null]];
    }

    #[Test]
    #[DataProvider('hostProvider')]
    public function parse_db_host_mirrors_wordpress_semantics(string $db_host, array $expected): void
    {
        $this->assertSame($expected, LocalDatabase::parse_db_host($db_host));
    }

    #[Test]
    public function connection_config_maps_wordpress_credentials(): void
    {
        $config = LocalDatabase::connection_config([
            'host' => '127.0.0.1:3307',
            'user' => 'wp',
            'password' => 'secret',
            'database' => 'wordpress',
        ]);

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertNull($config->socket);
        $this->assertSame('wp', $config->user);
        $this->assertSame('secret', $config->password);
        $this->assertSame('wordpress', $config->database);
        $this->assertSame('mysql', $config->engine);
    }

    #[Test]
    public function connection_config_defaults_to_the_mysql_port_and_localhost(): void
    {
        $config = LocalDatabase::connection_config([
            'host' => '',
            'user' => '',
            'password' => '',
            'database' => '',
        ]);

        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
    }

    #[Test]
    public function connection_config_maps_unix_socket_hosts(): void
    {
        $config = LocalDatabase::connection_config([
            'host' => 'localhost:/tmp/mysql.sock',
            'user' => 'wp',
            'password' => 'secret',
            'database' => 'wordpress',
        ]);

        $this->assertSame('/tmp/mysql.sock', $config->socket);
        $this->assertStringStartsWith('mysql:unix_socket=/tmp/mysql.sock;', $config->toDsn());
    }

    #[Test]
    public function connector_builds_a_mysql_connector_from_the_wordpress_constants(): void
    {
        if (! in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not available in this PHP runtime.');
        }

        if (! defined('DB_HOST')) {
            define('DB_HOST', '127.0.0.1');
        }
        if (! defined('DB_USER')) {
            define('DB_USER', 'wp');
        }
        if (! defined('DB_PASSWORD')) {
            define('DB_PASSWORD', 'secret');
        }
        if (! defined('DB_NAME')) {
            define('DB_NAME', 'wordpress');
        }

        $connector = LocalDatabase::connector();

        $this->assertInstanceOf(Connector::class, $connector);
    }

    #[Test]
    public function version_returns_the_wordpress_database_version(): void
    {
        $GLOBALS['wpdb'] = new class() {
            public function db_version(): string
            {
                return '8.0.36';
            }
        };

        $this->assertSame('8.0.36', LocalDatabase::version());
    }
}
