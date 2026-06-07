<?php

declare(strict_types=1);

namespace EasySQL\Api;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API endpoints for EasySQL.
 *
 * Exposes query execution, listing, and connection testing via WordPress
 * REST API under the `easysql/v1` namespace.
 */
class QueryController extends WP_REST_Controller {

    /**
     * @var \EasySQL\QueryService
     */
    private $query_service;

    /**
     * @param \EasySQL\QueryService $query_service
     */
    public function __construct(\EasySQL\QueryService $query_service) {
        $this->query_service = $query_service;
        $this->namespace     = 'easysql/v1';
    }

    /**
     * Register routes.
     */
    public function register_routes(): void {
        // POST /easysql/v1/query
        register_rest_route($this->namespace, '/query', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'execute_query'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args'                => $this->get_query_args(),
        ]);

        // GET /easysql/v1/queries
        register_rest_route($this->namespace, '/queries', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_queries'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args'                => $this->get_list_args(),
        ]);

        // GET /easysql/v1/test-connection
        register_rest_route($this->namespace, '/test-connection', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'test_connection'],
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
     * POST /easysql/v1/query
     */
    public function execute_query(WP_REST_Request $request): WP_REST_Response {
        $connector_id = $request->get_param('connector_id');
        $question     = $request->get_param('question');

        if (! is_string($connector_id) || '' === trim($connector_id)) {
            return new WP_REST_Response([
                'error' => __('The "connector_id" parameter is required.', 'easysql'),
            ], 400);
        }
        if (! is_string($question) || '' === trim($question)) {
            return new WP_REST_Response([
                'error' => __('The "question" parameter is required.', 'easysql'),
            ], 400);
        }

        $result = $this->query_service->query($connector_id, $question);

        if (isset($result['error'])) {
            return new WP_REST_Response($result, 400);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * GET /easysql/v1/queries
     *
     * Query params: connector_id (required), page, per_page.
     */
    public function list_queries(WP_REST_Request $request): WP_REST_Response {
        $connector_id = $request->get_param('connector_id');
        $page         = $request->get_param('page') ?? 1;
        $per_page     = $request->get_param('per_page') ?? 20;

        if (! is_string($connector_id) || '' === trim($connector_id)) {
            return new WP_REST_Response([
                'error' => __('The "connector_id" query parameter is required.', 'easysql'),
            ], 400);
        }

        $result = $this->query_service->list_queries(
            $connector_id,
            absint($page),
            absint($per_page)
        );

        if (isset($result['error'])) {
            return new WP_REST_Response($result, 400);
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * GET /easysql/v1/test-connection
     */
    public function test_connection(): WP_REST_Response {
        $result = $this->query_service->test_connection();
        $status = $result['success'] ? 200 : 400;

        return new WP_REST_Response($result, $status);
    }

    // -----------------------------------------------------------------------
    // Argument schemas
    // -----------------------------------------------------------------------

    private function get_query_args(): array {
        return [
            'connector_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    return is_string($value) && '' !== trim($value);
                },
            ],
            'question' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'validate_callback' => function ($value) {
                    return is_string($value) && '' !== trim($value);
                },
            ],
        ];
    }

    private function get_list_args(): array {
        return [
            'connector_id' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page' => [
                'required' => false,
                'type'     => 'integer',
                'default'  => 1,
            ],
            'per_page' => [
                'required' => false,
                'type'     => 'integer',
                'default'  => 20,
            ],
        ];
    }
}
