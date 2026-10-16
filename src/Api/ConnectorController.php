<?php

declare(strict_types=1);

namespace EasySQL\Api;

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

class ConnectorController extends WP_REST_Controller {

	/**
	 * @var \EasySQL\ConnectorService
	 */
	private $connector_service;

	public function __construct( \EasySQL\ConnectorService $connector_service ) {
		$this->connector_service = $connector_service;
		$this->namespace         = 'easysql/v1';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/connection',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/connection/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_connection(): WP_REST_Response {
		try {
			return new WP_REST_Response( $this->connector_service->get_or_create(), 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
		}
	}

	public function sync_connection(): WP_REST_Response {
		$result = $this->connector_service->sync();
		return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}
}
