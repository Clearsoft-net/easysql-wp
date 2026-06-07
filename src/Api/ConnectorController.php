<?php

declare(strict_types=1);

namespace EasySQL\Api;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST endpoints for the "wp" connector.
 *
 * Exposes connector info and schema sync under easysql/v1.
 */
class ConnectorController extends WP_REST_Controller {

    /**
     * @var \EasySQL\ConnectorService
     */
    private $connector_service;

    /**
     * @param \EasySQL\ConnectorService $connector_service
     */
    public function __construct(\EasySQL\ConnectorService $connector_service) {
        $this->connector_service = $connector_service;
        $this->namespace         = 'easysql/v1';
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        // GET /easysql/v1/connector
        register_rest_route($this->namespace, '/connector', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_connector'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // POST /easysql/v1/connector/sync
        register_rest_route($this->namespace, '/connector/sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'sync_connector'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);
    }

    /**
     * Permission: only users who can manage options.
     */
    public function admin_permission_check(): bool {
        return current_user_can('manage_options');
    }

    /**
     * GET /easysql/v1/connector
     *
     * Returns the "wp" connector (auto-creates if needed).
     */
    public function get_connector(): WP_REST_Response {
        try {
            $connector = $this->connector_service->get_or_create();
            return new WP_REST_Response($connector, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /easysql/v1/connector/sync
     *
     * Triggers a schema sync for the "wp" connector.
     */
    public function sync_connector(): WP_REST_Response {
        $result = $this->connector_service->sync();

        if (! $result['success']) {
            return new WP_REST_Response($result, 400);
        }

        return new WP_REST_Response($result, 200);
    }
}
