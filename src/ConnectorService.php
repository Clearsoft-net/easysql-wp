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
     *
     * @throws \RuntimeException If the API response does not contain an 'id' field.
     */
    public function get_or_create(): array {
        if ($this->connector !== null) {
            return $this->connector;
        }

        $cached = get_option('easysql_connector', null);
        if (is_array($cached) && isset($cached['id']) && is_string($cached['id'])) {
            $cached_id = $cached['id'];

            $client = $this->query_service->client();

            // Validate the cached connector against the backend. If it no
            // longer exists (e.g. backend restarted with a fresh DB), fall
            // through so we can look it up / recreate instead of returning a
            // stale id that would fail with "Connector not found".
            try {
                $check = $client->getHttpClient()->request(
                    'GET',
                    '/v1/connectors/' . rawurlencode($cached_id)
                );
                if ($check->getStatusCode() >= 200 && $check->getStatusCode() < 300) {
                    $this->connector = $cached;
                    return $this->connector;
                }
            } catch (\Throwable $e) {
                // Connection/network error — keep the cached connector rather
                // than throwing; the caller may be offline.
                $this->connector = $cached;
                return $this->connector;
            }

            // Cached id is gone — ignore it and resolve fresh below.
            $cached = null;
        }

        $client = $this->query_service->client();

        // 1. Try to find an existing connector named "wp".
        $response   = $client->getHttpClient()->request('GET', '/v1/connectors');
        $status     = $response->getStatusCode();
        $body       = (string) $response->getBody();
        $connectors = json_decode($body, true);

        if ($status >= 400) {
            throw new \RuntimeException(
                $this->extract_error($body, $status)
            );
        }

        if (is_array($connectors)) {
            foreach ($connectors as $c) {
                if (isset($c['name']) && $c['name'] === 'wp') {
                    if (! isset($c['id']) || ! is_string($c['id'])) {
                        throw new \RuntimeException(
                            esc_html__('The "wp" connector returned by the API has no valid "id" field.', 'easysql')
                        );
                    }
                    $this->connector = $c;
                    update_option('easysql_connector', $c, false);
                    return $this->connector;
                }
            }
        }

        // 2. None found — create one from WordPress DB credentials.
        $connector = $this->create_from_wp_config($client);

        if (! isset($connector['id']) || ! is_string($connector['id'])) {
            throw new \RuntimeException(
                esc_html__('The EasySQL API did not return a valid connector "id" after creation.', 'easysql')
            );
        }

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
        try {
            $connector = $this->get_or_create();

            $connector_id = $connector['id'] ?? null;
            if (! is_string($connector_id) || $connector_id === '') {
                return [
                    'success' => false,
                    'message' => esc_html__('Connector ID is missing or invalid.', 'easysql'),
                ];
            }

            // Fetch the schema from the local WordPress database and send
            // it to the API — the API never connects to the database directly.
            $schema = $this->fetch_schema();

            $client  = $this->query_service->client();
            $response = $client->getHttpClient()->request(
                'POST',
                '/v1/connectors/' . rawurlencode($connector_id) . '/sync',
                [
                    'json' => [
                        'schema' => $schema,
                    ],
                ]
            );

            if ($response->getStatusCode() >= 400) {
                $body = json_decode((string) $response->getBody(), true);
                $message = is_array($body) && isset($body['detail']) && is_string($body['detail'])
                    ? $body['detail']
                    : sprintf(
                        /* translators: %d: HTTP status code */
                        esc_html__('Sync request failed with HTTP %d.', 'easysql'),
                        $response->getStatusCode()
                    );
                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

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
     * Create a connector via POST /v1/connectors.
     *
     * Instead of sending database credentials to the API (which would
     * require the API to connect to the database directly), we use the
     * existing WordPress database connection ($wpdb) to fetch the schema
     * and send it along so the API can generate accurate SQL without
     * ever touching the database itself.
     *
     * @param  Client $client
     * @return array
     */
    private function create_from_wp_config(Client $client): array {
        $schema = $this->fetch_schema();

        $body = [
            'name' => 'wp',
            'type' => 'mysql',
        ];

        if ($schema !== []) {
            $body['schema'] = $schema;
        }

        $response = $client->getHttpClient()->request('POST', '/v1/connectors', [
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status >= 400) {
            throw new \RuntimeException(
                $this->extract_error($raw, $status)
            );
        }

        $body = json_decode($raw, true);
        if (! is_array($body)) {
            throw new \RuntimeException(
                esc_html__('Failed to create the WordPress connector.', 'easysql')
            );
        }

        return $body;
    }

    /**
     * Fetch the WordPress database schema using the existing wpdb
     * connection. Returns an array of tables with columns, types,
     * and foreign keys — the same structure the EasySQL API expects
     * from a schema sync.
     *
     * @return list<array{name: string, columns: list<array>, rows_approx: int|null}>
     */
    private function fetch_schema(): array {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb) || ! isset($wpdb->dbh)) {
            return [];
        }

        $tables = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT TABLE_NAME AS `name`, TABLE_ROWS AS `rows_approx`
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_TYPE = \'BASE TABLE\'
                 ORDER BY TABLE_NAME',
                DB_NAME
            ),
            ARRAY_A
        );

        if (! is_array($tables) || $tables === []) {
            return [];
        }

        $schema = [];
        foreach ($tables as $table) {
            $table_name  = $table['name'] ?? '';
            $rows_approx = isset($table['rows_approx']) ? (int) $table['rows_approx'] : null;

            if ($table_name === '') {
                continue;
            }

            $columns = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT COLUMN_NAME AS `name`,
                            COLUMN_TYPE AS `type`,
                            IS_NULLABLE AS `nullable`,
                            COLUMN_DEFAULT AS `default`,
                            COLUMN_KEY AS `column_key`
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = %s
                       AND TABLE_NAME = %s
                     ORDER BY ORDINAL_POSITION',
                    DB_NAME,
                    $table_name
                ),
                ARRAY_A
            );

            $cols = [];
            if (is_array($columns)) {
                foreach ($columns as $col) {
                    $cols[] = [
                        'name'         => $col['name'] ?? '',
                        'type'         => $col['type'] ?? '',
                        'nullable'     => isset($col['nullable']) && $col['nullable'] === 'YES',
                        'default'      => $col['default'] ?? null,
                        'primary_key'  => isset($col['column_key']) && $col['column_key'] === 'PRI',
                    ];
                }
            }

            $schema[] = [
                'name'         => $table_name,
                'columns'      => $cols,
                'rows_approx'  => $rows_approx,
            ];
        }

        return $schema;
    }

    /**
     * Extract a user-friendly error message from an API response body
     * that may be JSON with a "detail" or "message" key, falling back
     * to a generic "HTTP {status}" string.
     *
     * @param  string $raw_body Raw response body as a string.
     * @param  int    $status   HTTP status code.
     * @return string
     */
    private function extract_error(string $raw_body, int $status): string {
        $decoded = json_decode($raw_body, true);
        if (is_array($decoded)) {
            $detail = $decoded['detail'] ?? $decoded['message'] ?? null;

            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

            if (is_array($detail)) {
                $messages = [];
                foreach ($detail as $entry) {
                    if (is_array($entry) && isset($entry['msg']) && is_string($entry['msg'])) {
                        $messages[] = $entry['msg'];
                    }
                }
                if ($messages !== []) {
                    return implode('; ', $messages);
                }
            }
        }
        return sprintf(
            /* translators: %d: HTTP status code */
            esc_html__('Request failed with HTTP %d.', 'easysql'),
            $status
        );
    }
}
