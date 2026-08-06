<?php

declare(strict_types=1);

namespace EasySQL;

use Clearsoft\EasySQL\SDK\Client;

/**
 * Thin WordPress-aware wrapper around the EasySQL SDK client.
 *
 * Handles configuration retrieval, client instantiation, caching, and
 * error conversion so the rest of the plugin never talks to the SDK
 * directly.
 */
class QueryService {

    /**
     * @var Client|null
     */
    private $client;

    /**
     * @var array|null
     */
    private $config;

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /**
     * Retrieve the stored connection settings.
     *
     * @return array{api_key?: string, endpoint?: string, timeout?: int}
     */
    public function get_config(): array {
        if ($this->config !== null) {
            return $this->config;
        }

        $defaults = [
            'api_key'  => defined('EASYSQL_API_KEY') ? EASYSQL_API_KEY : '',
            'endpoint' => defined('EASYSQL_ENDPOINT') ? EASYSQL_ENDPOINT : 'https://api.easysql.net/v1',
            'timeout'  => 30,
        ];

        $stored = get_option('easysql_settings', []);
        $stored = is_array($stored) ? $stored : [];

        $config_from_db = array_intersect_key($stored, array_flip(['api_key', 'timeout']));
        $this->config   = wp_parse_args($config_from_db, $defaults);

        return $this->config;
    }

    /**
     * Persist connection settings.
     *
     * @param array $settings Must contain at least 'api_key'.
     */
    public function save_config(array $settings): bool {
        $existing = $this->get_config();
        $merged   = wp_parse_args($settings, $existing);

        $this->config = $merged;

        return update_option('easysql_settings', $merged, false);
    }

    // -----------------------------------------------------------------------
    // Client
    // -----------------------------------------------------------------------

    /**
     * Return a configured SDK client (cached for the request).
     */
    public function client(): Client {
        if ($this->client !== null) {
            return $this->client;
        }

        $config = $this->get_config();

        if (empty($config['api_key'])) {
            throw new \RuntimeException(
                esc_html__('EasySQL is not configured: missing API key.', 'easysql')
            );
        }

        $this->client = new Client([
            'base_url'     => $config['endpoint'],
            'access_token' => $config['api_key'],
            'timeout'      => $config['timeout'],
        ]);

        return $this->client;
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    /**
     * Ask the EasySQL API to generate (and run) a query from a natural
     * language question against a previously created connector.
     *
     * @param  string $connector_id Connector UUID.
     * @param  string $question     Natural-language question.
     * @return array<string, mixed>
     */
    public function query(string $connector_id, string $question): array {
        try {
            $client   = $this->client();
            $response = $client->getHttpClient()->request('POST', '/v1/queries', [
                'json' => [
                    'connector_id' => $connector_id,
                    'question'     => $question,
                ],
            ]);
            $status = $response->getStatusCode();
            $body   = json_decode((string) $response->getBody(), true);
            if (is_array($body) === false) {
                $body = [];
            }

            if ($status < 200 || $status >= 300) {
                return ['error' => $this->extract_error_from_body($body, $status)];
            }

            // Schema-only connector (WordPress): the API generates the SQL but
            // cannot run it — the plugin must execute it against the local
            // database and submit the result back to generate the answer.
            if (! empty($body['needs_local_execution']) && ! empty($body['sql_generated'])) {
                return $this->execute_locally_and_answer($body);
            }

            return $body;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run the generated SQL against the local WordPress database ($wpdb) and
     * submit the rows back to the API so it can produce the natural-language
     * answer and chart.
     *
     * @param  array<string, mixed> $query Initial query response from the API.
     * @return array<string, mixed>
     */
    private function execute_locally_and_answer(array $query): array {
        $sql = isset($query['sql_generated']) ? (string) $query['sql_generated'] : '';
        $id  = isset($query['id']) ? (string) $query['id'] : '';

        try {
            $result_data = $this->run_local_sql($sql);
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        if ($id === '') {
            return $query;
        }

        try {
            $client   = $this->client();
            $response = $client->getHttpClient()->request(
                'POST',
                '/v1/queries/' . rawurlencode($id) . '/answer',
                [
                    'json' => ['result_data' => $result_data],
                ]
            );
            $status = $response->getStatusCode();
            $body   = json_decode((string) $response->getBody(), true);
            if (is_array($body) === false) {
                $body = [];
            }

            if ($status >= 200 && $status < 300) {
                return $body;
            }

            // If answering fails, still return what we ran locally so the user
            // sees something useful.
            return array_merge($query, ['result_data' => $result_data]);
        } catch (\Throwable $e) {
            return array_merge($query, [
                'error'       => $e->getMessage(),
                'result_data' => $result_data,
            ]);
        }
    }

    /**
     * Execute a SELECT query against the local WordPress database.
     *
     * @param  string $sql
     * @return list<array<string, mixed>>
     */
    private function run_local_sql(string $sql): array {
        global $wpdb;

        if (! isset($wpdb) || ! is_object($wpdb) || ! isset($wpdb->dbh)) {
            throw new \RuntimeException(
                esc_html__('WordPress database is not available to run the query locally.', 'easysql')
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (is_array($rows) === false) {
            return [];
        }

        // Cast values so the JSON payload sent to the API is well-formed.
        return array_map(function ($row): array {
            return is_array($row) ? $row : [];
        }, $rows);
    }

    /**
     * List past queries for a given connector with pagination.
     *
     * @param  string $connector_id Connector UUID.
     * @param  int    $page         Page number (1-based).
     * @param  int    $per_page     Items per page (max 100).
     * @return array<string, mixed>
     */
    public function list_queries(string $connector_id, int $page = 1, int $per_page = 20): array {
        try {
            $client   = $this->client();
            $response = $client->getHttpClient()->request('GET', '/v1/queries', [
                'query' => [
                    'connector_id' => $connector_id,
                    'page'         => max(1, $page),
                    'per_page'     => min(100, max(1, $per_page)),
                ],
            ]);
            $status = $response->getStatusCode();
            $body   = json_decode((string) $response->getBody(), true);
            if (is_array($body) === false) {
                $body = [];
            }

            if ($status >= 200 && $status < 300) {
                return $body;
            }

            return ['error' => $this->extract_error_from_body($body, $status)];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test connectivity with the current configuration.
     *
     * @return array{success: bool, message: string}
     */
    public function test_connection(): array {
        try {
            $client   = $this->client();
            $response = $client->getHttpClient()->request('GET', '/v1/health');
            $status   = $response->getStatusCode();
            $body     = json_decode((string) $response->getBody(), true);
            if (is_array($body) === false) {
                $body = [];
            }

            if ($status >= 200 && $status < 300) {
                $message = $body['message'] ?? __('Connection successful.', 'easysql');
                return [
                    'success' => true,
                    'message' => $message,
                ];
            }

            return [
                'success' => false,
                'message' => $this->extract_error_from_body($body, $status),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Error extraction
    // -----------------------------------------------------------------------

    /**
     * Extract a human-readable error message from a non-2xx response body.
     */
    private function extract_error_from_body(array $body, int $status): string {
        $detail = $body['detail'] ?? $body['message'] ?? null;

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

        return sprintf(
            /* translators: %d: HTTP status code */
            __('HTTP %d', 'easysql'),
            $status
        );
    }
}
