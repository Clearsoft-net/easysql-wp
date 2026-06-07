<?php

declare(strict_types=1);

namespace EasySQL;

use Clearsoft\EasySQL\SDK\Client;

/**
 * Manages the "wp" connector lifecycle.
 *
 * On first use, creates a connector representing the WordPress database
 * via the EasySQL API, caches the connector_id in wp_options, and
 * provides helpers to sync the schema and retrieve connector info.
 */
class ConnectorService {

    /**
     * @var QueryService
     */
    private $query_service;

    /**
     * @var array|null
     */
    private $connector;

    /**
     * @param QueryService $query_service
     */
    public function __construct(QueryService $query_service) {
        $this->query_service = $query_service;
    }

    // -----------------------------------------------------------------------
    // Connector resolution
    // -----------------------------------------------------------------------

    /**
     * Return the "wp" connector, creating it via the API if it does not
     * yet exist. The connector_id is cached in wp_options so subsequent
     * calls are instant.
     *
     * @return array{id: string, name: string, last_sync_at: string|null}
     */
    public function get_or_create(): array {
        if ($this->connector !== null) {
            return $this->connector;
        }

        $cached = get_option('easysql_connector', null);
        if (is_array($cached) && isset($cached['id'])) {
            $this->connector = $cached;
            return $this->connector;
        }

        $client = $this->query_service->client();

        // 1. Try to find an existing connector named "wp".
        $response = $client->getHttpClient()->request('GET', '/v1/connectors');
        $connectors = json_decode((string) $response->getBody(), true);
        if (is_array($connectors)) {
            foreach ($connectors as $c) {
                if (isset($c['name']) && $c['name'] === 'wp') {
                    $this->connector = $c;
                    update_option('easysql_connector', $c, false);
                    return $this->connector;
                }
            }
        }

        // 2. None found — create one from WordPress DB credentials.
        $connector = $this->create_from_wp_config($client);
        $this->connector = $connector;
        update_option('easysql_connector', $connector, false);

        return $this->connector;
    }

    /**
     * Call POST /v1/connectors/{id}/sync to synchronise the WordPress
     * database schema with EasySQL.
     *
     * @return array{success: bool, message?: string}
     */
    public function sync(): array {
        $connector = $this->get_or_create();

        try {
            $client = $this->query_service->client();
            $client->getHttpClient()->request(
                'POST',
                '/v1/connectors/' . rawurlencode($connector['id']) . '/sync',
            );

            // Update cached last_sync_at.
            $cached = get_option('easysql_connector', []);
            if (is_array($cached)) {
                $cached['last_sync_at'] = gmdate('Y-m-d\TH:i:s\Z');
                update_option('easysql_connector', $cached, false);
                $this->connector = $cached;
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete the stored connector option so get_or_create() will
     * re-create it on the next call.
     */
    public function reset(): void {
        delete_option('easysql_connector');
        $this->connector = null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Create a connector via POST /v1/connectors using the WordPress
     * database credentials (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD).
     *
     * @param  Client $client
     * @return array
     */
    private function create_from_wp_config(Client $client): array {
        $host     = defined('DB_HOST') ? DB_HOST : 'localhost';
        $port     = 3306;
        $database = defined('DB_NAME') ? DB_NAME : 'wordpress';
        $user     = defined('DB_USER') ? DB_USER : 'root';
        $password = defined('DB_PASSWORD') ? DB_PASSWORD : '';

        // DB_HOST may contain a port suffix (e.g. "localhost:3306").
        if (strpos($host, ':') !== false) {
            $parts = explode(':', $host, 2);
            $host  = $parts[0];
            $port  = (int) $parts[1];
        }

        $response = $client->getHttpClient()->request('POST', '/v1/connectors', [
            'json' => [
                'name' => 'wp',
                'type' => 'mysql',
                'config' => [
                    'host'     => $host,
                    'port'     => $port,
                    'user'     => $user,
                    'password' => $password,
                    'database' => $database,
                    'ssl'      => true,
                ],
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);
        if (! is_array($body)) {
            throw new \RuntimeException(
                esc_html__('Failed to create the WordPress connector.', 'easysql')
            );
        }

        return $body;
    }
}
