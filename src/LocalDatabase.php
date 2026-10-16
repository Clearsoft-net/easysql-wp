<?php

declare(strict_types=1);

namespace EasySQL;

use Clearsoft\EasySQL\Connectors\ConnectorEngine;
use Clearsoft\EasySQL\Connectors\ConnectorFactory;
use Clearsoft\EasySQL\Connectors\ConnectorInterface;
use Clearsoft\EasySQL\Connectors\MySQL\ConnectionConfig;
use RuntimeException;

/**
 * Local access to the WordPress database through the EasySQL connector package.
 *
 * Credentials come from the wp-config.php constants and stay in-process: only
 * the schema ever reaches the API, and result rows never leave the site.
 */
final class LocalDatabase {

	/**
	 * Create a connector for the WordPress database.
	 *
	 * The connector is not connected yet — callers own the connect/close cycle.
	 *
	 * @throws RuntimeException When the credentials are unavailable or pdo_mysql is missing.
	 */
	public static function connector(): ConnectorInterface {
		self::assert_pdo_mysql_available();

		return ConnectorFactory::create(
			ConnectorEngine::MySQL,
			self::connection_config( self::wordpress_credentials() )
		);
	}

	/** Return the database server version WordPress is connected to. */
	public static function version(): string {
		global $wpdb;

		return isset( $wpdb ) && method_exists( $wpdb, 'db_version' )
			? (string) $wpdb->db_version()
			: '';
	}

	/**
	 * Build the connector connection config from database credentials.
	 *
	 * Separate from {@see connector()} so arbitrary credentials can be mapped
	 * without touching the wp-config.php constants. When `DB_HOST` names a Unix
	 * socket, the path is passed through as-is; otherwise the TCP host/port pair
	 * is used.
	 *
	 * @param  array{host: string, user: string, password: string, database: string} $credentials Database credentials.
	 * @return ConnectionConfig Connector connection config.
	 */
	public static function connection_config( array $credentials ): ConnectionConfig {
		list( $host, $port, $socket ) = self::parse_db_host( $credentials['host'] );

		return new ConnectionConfig(
			host: '' !== $host ? $host : 'localhost',
			port: $port ?? 3306,
			socket: $socket,
			user: $credentials['user'],
			password: $credentials['password'],
			database: $credentials['database'],
			engine: ConnectorEngine::MySQL,
		);
	}

	/**
	 * Split DB_HOST into host, port and socket, mirroring wpdb::parse_db_host().
	 *
	 * Handles `host`, `host:port`, `[ipv6]`, `[ipv6]:port` and `host:/path/to/socket`.
	 *
	 * @param  string $db_host Value of the DB_HOST constant.
	 * @return array{0: string, 1: int|null, 2: string|null} Host, port and Unix socket path.
	 */
	public static function parse_db_host( string $db_host ): array {
		$socket     = null;
		$socket_pos = strpos( $db_host, ':/' );
		if ( false !== $socket_pos ) {
			$socket  = substr( $db_host, $socket_pos + 1 );
			$db_host = substr( $db_host, 0, $socket_pos );
		}

		// An IPv6 address always contains at least two colons.
		$pattern = substr_count( $db_host, ':' ) > 1
			? '/^(?:\[)?([0-9a-fA-F:]+)(?:\]:(\d+))?/'
			: '/^([^:\/]*)(?::(\d+))?/';

		if ( 1 !== preg_match( $pattern, $db_host, $matches ) ) {
			return array( '', null, $socket );
		}

		$host = isset( $matches[1] ) && '' !== $matches[1] ? $matches[1] : '';
		$port = isset( $matches[2] ) && '' !== $matches[2] ? (int) $matches[2] : null;

		return array( $host, $port, $socket );
	}

	/**
	 * Read the database credentials from wp-config.php.
	 *
	 * @return array{host: string, user: string, password: string, database: string} Database credentials.
	 * @throws RuntimeException When a required constant is missing.
	 */
	private static function wordpress_credentials(): array {
		$credentials = array();
		$constants   = array(
			'host'     => 'DB_HOST',
			'user'     => 'DB_USER',
			'password' => 'DB_PASSWORD',
			'database' => 'DB_NAME',
		);

		foreach ( $constants as $key => $constant ) {
			if ( ! defined( $constant ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: wp-config.php constant name. */
						esc_html__( 'The %s constant is not defined in wp-config.php.', 'easysql' ),
						esc_html( $constant )
					)
				);
			}
			$credentials[ $key ] = (string) constant( $constant );
		}

		return $credentials;
	}

	/**
	 * Ensure the PDO MySQL driver is available before a connection is attempted.
	 *
	 * @throws RuntimeException When the pdo_mysql extension is not loaded.
	 */
	private static function assert_pdo_mysql_available(): void {
		if ( ! class_exists( \PDO::class ) || ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			throw new RuntimeException(
				esc_html__( 'The pdo_mysql PHP extension is required for EasySQL to read the WordPress schema and run queries locally. Ask your hosting provider to enable it.', 'easysql' )
			);
		}
	}
}
