<?php

declare(strict_types=1);

namespace EasySQL\Api;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class QueryController extends WP_REST_Controller {

	/**
	 * @var \EasySQL\QueryService
	 */
	private $query_service;

	/**
	 * @var \EasySQL\ConnectorService|null
	 */
	private $connector_service;

	public function __construct(
		\EasySQL\QueryService $query_service,
		?\EasySQL\ConnectorService $connector_service = null
	) {
		$this->query_service     = $query_service;
		$this->connector_service = $connector_service;
		$this->namespace         = 'easysql/v1';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/query',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute_query' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'connection_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'question'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/queries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_queries' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'connection_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cursor'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'limit'         => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
					),
					'page'          => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 1,
					),
					'per_page'      => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 20,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public function execute_query( WP_REST_Request $request ): WP_REST_Response {
		$connection_id = $request->get_param( 'connection_id' );
		$question      = $request->get_param( 'question' );

		if ( ! is_string( $question ) || '' === trim( $question ) ) {
			return new WP_REST_Response(
				array(
					'error' => __( 'The "question" parameter is required.', 'easysql' ),
				),
				400
			);
		}

		if ( ! is_string( $connection_id ) || '' === trim( $connection_id ) ) {
			if ( $this->connector_service === null ) {
				return new WP_REST_Response(
					array(
						'error' => __( 'The WordPress connection is not available.', 'easysql' ),
					),
					400
				);
			}
			try {
				$connection_id = $this->connector_service->get_or_create()['id'] ?? '';
			} catch ( \Throwable $e ) {
				return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
			}
		}

		if ( ! is_string( $connection_id ) || '' === trim( $connection_id ) ) {
			return new WP_REST_Response(
				array(
					'error' => __( 'A valid WordPress connection is required.', 'easysql' ),
				),
				400
			);
		}

		$result = $this->query_service->query( $connection_id, $question );
		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function list_queries( WP_REST_Request $request ): WP_REST_Response {
		$connection_id = $request->get_param( 'connection_id' );
		if ( ! is_string( $connection_id ) || '' === trim( $connection_id ) ) {
			if ( $this->connector_service === null ) {
				return new WP_REST_Response(
					array(
						'error' => __( 'The WordPress connection is not available.', 'easysql' ),
					),
					400
				);
			}
			try {
				$connection_id = $this->connector_service->get_or_create()['id'] ?? '';
			} catch ( \Throwable $e ) {
				return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
			}
		}

		if ( ! is_string( $connection_id ) || '' === trim( $connection_id ) ) {
			return new WP_REST_Response(
				array(
					'error' => __( 'The "connection_id" query parameter is required.', 'easysql' ),
				),
				400
			);
		}

		$cursor = $request->get_param( 'cursor' );
		$limit  = $request->get_param( 'limit' );
		if ( null === $limit ) {
			$limit = $request->get_param( 'per_page' ) ?? 20;
		}

		$result = $this->query_service->list_queries(
			$connection_id,
			is_string( $cursor ) && '' !== $cursor ? $cursor : null,
			absint( $limit )
		);

		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response( $result, 400 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function test_connection(): WP_REST_Response {
		$result = $this->query_service->test_connection();
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}
}
