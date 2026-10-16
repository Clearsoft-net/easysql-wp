<?php

declare(strict_types=1);

namespace EasySQL;

use Clearsoft\EasySQL\Client\Client;
use Clearsoft\EasySQL\Client\Exceptions\ApiException;
use Clearsoft\EasySQL\Connectors\ConnectorInterface;
use Clearsoft\EasySQL\SchemaGeneration\SchemaGenerator;

class ConnectorService {

	private const CONNECTION_OPTION       = 'easysql_connection';
	private const LEGACY_CONNECTOR_OPTION = 'easysql_connector';

	/**
	 * @var QueryService
	 */
	private $query_service;

	/**
	 * @var \Closure():ConnectorInterface
	 */
	private $connector_factory;

	/**
	 * @var array|null
	 */
	private $connection;

	/**
	 * @param QueryService                       $query_service     EasySQL API service.
	 * @param \Closure():ConnectorInterface|null $connector_factory Builds connectors for the WordPress database.
	 */
	public function __construct( QueryService $query_service, ?\Closure $connector_factory = null ) {
		$this->query_service     = $query_service;
		$this->connector_factory = $connector_factory ?? static function (): ConnectorInterface {
			return LocalDatabase::connector();
		};
	}

	/**
	 * Return this WordPress site's EasySQL connection, creating it if needed.
	 *
	 * @return array{id: string, name: string, type?: string, last_sync_at?: string|null}
	 */
	public function get_or_create(): array {
		if ( null !== $this->connection ) {
			return $this->connection;
		}

		$cached = get_option( self::CONNECTION_OPTION, null );
		if ( ! is_array( $cached ) || empty( $cached['id'] ) || ! is_string( $cached['id'] ) ) {
			$legacy = get_option( self::LEGACY_CONNECTOR_OPTION, null );
			$cached = is_array( $legacy ) ? $legacy : null;
		}

		$client = $this->query_service->client();
		if ( is_array( $cached ) && isset( $cached['id'] ) && is_string( $cached['id'] ) ) {
			try {
				$connection = $client->getConnection( $cached['id'] );
				if ( isset( $connection['id'] ) && is_string( $connection['id'] ) ) {
					return $this->cache_connection( $connection );
				}
			} catch ( \Throwable $e ) {
				if ( ! $this->is_not_found( $e ) ) {
					$this->connection = $cached;
					return $this->connection;
				}
			}
		}

		foreach ( $client->listConnections() as $connection ) {
			if ( isset( $connection['name'] ) && $connection['name'] === $this->connection_name() ) {
				if ( ! isset( $connection['id'] ) || ! is_string( $connection['id'] ) ) {
					throw new \RuntimeException(
						esc_html__( 'The WordPress connection returned by EasySQL has an invalid ID.', 'easysql' )
					);
				}
				return $this->cache_connection( $connection );
			}
		}

		$connection = $this->create_from_wp_database( $client );
		if ( ! isset( $connection['id'] ) || ! is_string( $connection['id'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'EasySQL did not return a valid connection ID after creation.', 'easysql' )
			);
		}

		return $this->cache_connection( $connection );
	}

	/**
	 * Push the current WordPress schema to EasySQL.
	 *
	 * @return array{success: bool, message?: string}
	 */
	public function sync(): array {
		try {
			$connection    = $this->get_or_create();
			$connection_id = $connection['id'] ?? null;
			if ( ! is_string( $connection_id ) || '' === $connection_id ) {
				return array(
					'success' => false,
					'message' => esc_html__( 'Connection ID is missing or invalid.', 'easysql' ),
				);
			}

			$response = $this->query_service->client()->syncConnection(
				array(
					'schema'     => $this->generate_schema_payload(),
					'db_version' => $this->database_version(),
				),
				$connection_id
			);

			return array(
				'success'    => true,
				'connection' => $this->cache_connection(
					array_merge(
						$connection,
						$response,
						array( 'last_sync_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ) )
					)
				),
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/** Clear the cached connection so it is registered again on the next request. */
	public function reset(): void {
		delete_option( self::CONNECTION_OPTION );
		delete_option( self::LEGACY_CONNECTOR_OPTION );
		$this->connection = null;
	}

	/** Return a stable display name scoped to this WordPress site. */
	private function connection_name(): string {
		$site_url = get_option( 'siteurl', 'WordPress' );
		return 'WordPress - ' . (string) $site_url;
	}

	/**
	 * @param array $connection
	 * @return array{id: string, name: string, type?: string, last_sync_at?: string|null}
	 */
	private function cache_connection( array $connection ): array {
		$this->connection = $connection;
		update_option( self::CONNECTION_OPTION, $connection, false );
		delete_option( self::LEGACY_CONNECTOR_OPTION );
		return $this->connection;
	}

	/** Create a schema-only connection without sending database credentials.
	 *
	 * @param Client $client Configured EasySQL API client.
	 * @return array<string, mixed>
	 */
	private function create_from_wp_database( Client $client ): array {
		return $client->createConnection(
			array(
				'name'       => $this->connection_name(),
				'type'       => 'wp',
				'schema'     => $this->generate_schema_payload(),
				'db_version' => $this->database_version(),
			)
		);
	}

	/**
	 * Read the raw WordPress schema through the EasySQL MySQL connector.
	 *
	 * @return array{engine: string, tables: list<array<string, mixed>>}
	 */
	private function introspect_raw_schema(): array {
		$connector = ( $this->connector_factory )();
		$connector->connect();

		try {
			return $connector->introspect();
		} finally {
			$connector->close();
		}
	}

	/**
	 * Generate the canonical API schema using the SDK's schema generator.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function generate_schema_payload(): array {
		return ( new SchemaGenerator() )->generate( $this->introspect_raw_schema() );
	}

	private function database_version(): string {
		return LocalDatabase::version();
	}

	/** Identify a missing API-side connection so it can be re-registered.
	 *
	 * @param \Throwable $error API client exception.
	 */
	private function is_not_found( \Throwable $error ): bool {
		return $error instanceof ApiException && 404 === $error->getStatusCode();
	}
}
