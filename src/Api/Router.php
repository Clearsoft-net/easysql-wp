<?php

declare(strict_types=1);

namespace EasySQL\Api;

/**
 * Registers all REST API routes for the plugin.
 */
class Router {

    /**
     * @var \EasySQL\QueryService
     */
    private $query_service;

    /**
     * @var \EasySQL\ConnectorService|null
     */
    private $connector_service;

    /**
     * @param \EasySQL\QueryService          $query_service
     * @param \EasySQL\ConnectorService|null $connector_service
     */
    public function __construct(
        \EasySQL\QueryService $query_service,
        ?\EasySQL\ConnectorService $connector_service = null
    ) {
        $this->query_service     = $query_service;
        $this->connector_service = $connector_service;
    }

    /**
     * Hook into rest_api_init.
     */
    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Instantiate controllers and register their routes.
     */
    public function register_routes(): void {
        $query_controller = new QueryController($this->query_service);
        $query_controller->register_routes();

        if ($this->connector_service !== null) {
            $connector_controller = new ConnectorController($this->connector_service);
            $connector_controller->register_routes();
        }
    }
}
