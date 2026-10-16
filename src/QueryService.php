<?php

declare(strict_types=1);

namespace EasySQL;

use Clearsoft\EasySQL\Client\Client;
use Clearsoft\EasySQL\Common\SqlValidator;
use Clearsoft\EasySQL\Connectors\ConnectorInterface;

class QueryService {

	/**
	 * @var Client|null
	 */
	private $client;

	/**
	 * @var array|null
	 */
	private $config;

	/**
	 * @var \Closure():ConnectorInterface
	 */
	private $connector_factory;

	/**
	 * @param \Closure():ConnectorInterface|null $connector_factory Builds connectors for the WordPress database.
	 */
	public function __construct( ?\Closure $connector_factory = null ) {
		$this->connector_factory = $connector_factory ?? static function (): ConnectorInterface {
			return LocalDatabase::connector();
		};
	}

	/**
	 * @return array{api_key?: string, endpoint?: string, timeout?: int}
	 */
	public function get_config(): array {
		if ( null !== $this->config ) {
			return $this->config;
		}

		$defaults = array(
			'api_key'  => defined( 'EASYSQL_API_KEY' ) ? constant( 'EASYSQL_API_KEY' ) : '',
			'endpoint' => defined( 'EASYSQL_ENDPOINT' ) ? constant( 'EASYSQL_ENDPOINT' ) : 'https://api.easysql.net',
			'timeout'  => 30,
		);

		$stored = get_option( 'easysql_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();

		$config_from_db = array_intersect_key( $stored, array_flip( array( 'api_key', 'timeout' ) ) );
		$this->config   = wp_parse_args( $config_from_db, $defaults );

		return $this->config;
	}

	/**
	 * @param array $settings Must contain at least 'api_key'.
	 */
	public function save_config( array $settings ): bool {
		$existing = $this->get_config();
		$merged   = wp_parse_args( $settings, $existing );

		$this->config = $merged;

		return update_option( 'easysql_settings', $merged, false );
	}

	public function client(): Client {
		if ( null !== $this->client ) {
			return $this->client;
		}

		$config = $this->get_config();

		if ( empty( $config['api_key'] ) ) {
			throw new \RuntimeException(
				esc_html__( 'EasySQL is not configured: missing API key.', 'easysql' )
			);
		}

		$this->client = new Client(
			array(
				'base_url'     => rtrim( $config['endpoint'], '/' ),
				'access_token' => $config['api_key'],
				'timeout'      => $config['timeout'],
			)
		);

		return $this->client;
	}

	/**
	 * Generate SQL through EasySQL and execute the safe SELECT locally in WordPress.
	 *
	 * @param  string $connection_id EasySQL connection UUID.
	 * @param  string $question      Natural-language question.
	 * @return array<string, mixed>
	 */
	public function query( string $connection_id, string $question ): array {
		try {
			$query = $this->client()->createQuery(
				array(
					'connection_id' => $connection_id,
					'question'      => $question,
				)
			);

			if ( empty( $query['needs_local_execution'] ) || empty( $query['sql_generated'] ) ) {
				return $query;
			}

			$sql = (string) $query['sql_generated'];
			$this->validate_select( $sql );

			try {
				$result_data = $this->run_local_sql( $sql );
			} catch ( \Throwable $e ) {
				return array_merge( $query, array( 'error' => $e->getMessage() ) );
			}

			$row_count = count( $result_data );
			return array_merge(
				$query,
				array(
					'answer'      => sprintf(
						/* translators: 1: number of rows returned, 2: row label */
						__( 'Query completed: %1$d %2$s returned.', 'easysql' ),
						$row_count,
						1 === $row_count ? __( 'row', 'easysql' ) : __( 'rows', 'easysql' )
					),
					'result_data' => $result_data,
					'row_count'   => $row_count,
				)
			);
		} catch ( \Throwable $e ) {
			return array(
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * List query history for the WordPress database connection.
	 *
	 * @param  string      $connection_id EasySQL connection UUID.
	 * @param  string|null $cursor        Opaque next-page cursor.
	 * @param  int         $limit         Page size (1–100).
	 * @return array<string, mixed>
	 */
	public function list_queries( string $connection_id, ?string $cursor = null, int $limit = 20 ): array {
		try {
			$query = array(
				'cursor' => $cursor,
				'limit'  => min( 100, max( 1, $limit ) ),
			);
			if ( null === $cursor ) {
				$query['connection_id'] = $connection_id;
			}
			$history = $this->client()->listQueries( $query );
			if ( isset( $history['items'] ) && is_array( $history['items'] ) ) {
				$history['items'] = array_values(
					array_filter(
						$history['items'],
						static function ( $item ) use ( $connection_id ): bool {
							return is_array( $item )
								&& isset( $item['connection_id'] )
								&& $item['connection_id'] === $connection_id;
						}
					)
				);
			}
			return $history;
		} catch ( \Throwable $e ) {
			return array(
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Test connectivity with the current configuration.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection(): array {
		try {
			$body    = $this->client()->health();
			$message = $body['message'] ?? __( 'Connection successful.', 'easysql' );
			return array(
				'success' => true,
				'message' => $message,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Validate that SQL is read-only and scoped to the WordPress database.
	 *
	 * @param string $sql Generated SQL.
	 * @throws \RuntimeException When the SQL fails local safety validation.
	 */
	private function validate_select( string $sql ): void {
		$validation = SqlValidator::validateSelectOnly( $sql );
		if ( empty( $validation['ok'] ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: SQL validation reason */
					esc_html__( 'Generated SQL was rejected: %s', 'easysql' ),
					esc_html( $validation['reason'] ?? __( 'statement is not read-only', 'easysql' ) )
				)
			);
		}

		if ( preg_match( '/\\b(?:FROM|JOIN)\\s+(`?)([a-zA-Z0-9_$]+)\\1\\s*\\./i', $sql, $match ) ) {
			$database          = trim( $match[2], '`' );
			$allowed_databases = array();
			if ( defined( 'DB_NAME' ) ) {
				$allowed_databases[] = (string) constant( 'DB_NAME' );
			}
			global $wpdb;
			if ( isset( $wpdb->dbname ) && is_string( $wpdb->dbname ) ) {
				$allowed_databases[] = $wpdb->dbname;
			}
			if ( ! in_array( $database, $allowed_databases, true ) ) {
				throw new \RuntimeException(
					esc_html__( 'Generated SQL may only read the WordPress database.', 'easysql' )
				);
			}
		}
	}

	/**
	 * Execute a SELECT against the current WordPress database through the
	 * EasySQL MySQL connector.
	 *
	 * @param string $sql Validated read-only SQL.
	 * @return list<array<string, mixed>>
	 * @throws \Throwable When the local connector cannot be built, connected or executed.
	 */
	private function run_local_sql( string $sql ): array {
		$connector = ( $this->connector_factory )();
		$connector->connect();

		try {
			$result = $connector->execute( $sql );
		} finally {
			$connector->close();
		}

		$rows = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();

		return array_map(
			static function ( $row ): array {
				return is_array( $row ) ? $row : array();
			},
			array_values( $rows )
		);
	}
}
