<?php

declare(strict_types=1);

namespace EasySQL;

/**
 * Main plugin container.
 *
 * Wires together all the moving parts of the plugin and manages the
 * lifecycle (boot, activation, deactivation).
 */
class Plugin {

    /**
     * @var Admin\SettingsPage|null
     */
    private $settings;

    /**
     * @var Admin\AskPage|null
     */
    private $ask_page;

    /**
     * @var Admin\HistoryPage|null
     */
    private $history_page;

    /**
     * @var QueryService|null
     */
    private $query_service;

    /**
     * @var ConnectorService|null
     */
    private $connector_service;

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /**
     * Boot the plugin on `plugins_loaded`.
     */
    public function boot(): void {
        $this->init_services();
        $this->register_hooks();

        if (is_admin()) {
            $this->init_admin();
        }
    }

    /**
     * Fired on plugin activation.
     */
    public static function activate(): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $min_php = '7.4';
        if (version_compare(PHP_VERSION, $min_php, '<')) {
            deactivate_plugins(plugin_basename(EASYSQL_FILE));
            wp_die(
                sprintf(
                    esc_html__('EasySQL requires PHP %s or later.', 'easysql'),
                    esc_html($min_php)
                )
            );
        }

        flush_rewrite_rules();
    }

    /**
     * Fired on plugin deactivation.
     */
    public static function deactivate(): void {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        flush_rewrite_rules();
    }

    // -----------------------------------------------------------------------
    // Internal wiring
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        // Localisation
        add_action('init', [$this, 'load_textdomain']);

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'register_public_assets']);
        add_action('admin_enqueue_scripts', [$this, 'register_admin_assets']);

        // REST API
        $router = new Api\Router($this->query_service, $this->connector_service);
        $router->register();
    }

    private function init_services(): void {
        $this->query_service     = new QueryService();
        $this->connector_service = new ConnectorService($this->query_service);
    }

    private function init_admin(): void {
        $this->ask_page     = new Admin\AskPage();
        $this->history_page = new Admin\HistoryPage();
        $this->settings     = new Admin\SettingsPage();
        $this->ask_page->register();
        $this->history_page->register();
        $this->settings->register();
    }

    // -----------------------------------------------------------------------
    // Hooks
    // -----------------------------------------------------------------------

    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'easysql',
            false,
            dirname(plugin_basename(EASYSQL_FILE)) . '/languages'
        );
    }

    /**
     * Register publicly enqueued assets (styles / scripts).
     */
    public function register_public_assets(): void {
        // Future: enqueue front-end assets here.
    }

    /**
     * Register admin assets.
     */
    public function register_admin_assets(string $hook_suffix): void {
        $is_easysql = $hook_suffix === $this->ask_page->get_hook_suffix()
            || $hook_suffix === $this->history_page->get_hook_suffix()
            || strpos($hook_suffix, 'easysql') !== false;

        // Shared assets for all easysql pages.
        if ($is_easysql) {
            wp_enqueue_style(
                'easysql-admin',
                EASYSQL_URL . 'assets/admin.css',
                [],
                EASYSQL_VERSION
            );

            wp_enqueue_script(
                'easysql-admin',
                EASYSQL_URL . 'assets/admin.js',
                ['jquery'],
                EASYSQL_VERSION,
                true
            );

            wp_localize_script(
                'easysql-admin',
                'wpApiSettings',
                [
                    'root'  => esc_url_raw(rest_url()),
                    'nonce' => wp_create_nonce('wp_rest'),
                ]
            );
        }

        // Ask page assets.
        if ($hook_suffix === $this->ask_page->get_hook_suffix()) {
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
                [],
                '4.4.7',
                true
            );

            // Try to resolve the connector ID server-side so the JS
            // doesn't need an extra round-trip.
            $connector_id = null;
            try {
                $connector    = $this->connector_service->get_or_create();
                $connector_id = $connector['id'] ?? null;
            } catch (\Throwable $e) {
                // Connector not available yet — ask.js will prompt the user.
            }

            wp_enqueue_script(
                'easysql-ask',
                EASYSQL_URL . 'assets/ask.js',
                ['jquery', 'chart-js'],
                filemtime(EASYSQL_DIR . 'assets/ask.js'),
                true
            );

            wp_localize_script(
                'easysql-ask',
                'easysqlAsk',
                [
                    'connector_id' => $connector_id,
                ]
            );
        }

        // History page assets.
        if ($hook_suffix === $this->history_page->get_hook_suffix()) {
            wp_enqueue_script(
                'easysql-history',
                EASYSQL_URL . 'assets/history.js',
                ['jquery'],
                EASYSQL_VERSION,
                true
            );
        }
    }

    // -----------------------------------------------------------------------
    // Public accessors
    // -----------------------------------------------------------------------

    /**
     * Return the query service instance.
     */
    public function query_service(): QueryService {
        return $this->query_service;
    }

    /**
     * Return the connector service instance.
     */
    public function connector_service(): ConnectorService {
        return $this->connector_service;
    }
}
