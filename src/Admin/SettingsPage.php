<?php

declare(strict_types=1);

namespace EasySQL\Admin;

/**
 * WordPress admin settings page for EasySQL.
 *
 * Provides the UI for entering / updating the API key and connection
 * timeout, plus a status section for the "wp" connector.
 * The endpoint URL is shown read-only and configured via the
 * EASYSQL_ENDPOINT constant in wp-config.php.
 */
class SettingsPage {

    /**
     * Hook suffix returned by add_options_page().
     *
     * @var string
     */
    private $hook_suffix;

    /**
     * Register the admin page and its settings.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add the settings page under the Settings menu.
     */
    public function add_menu_page(): void {
        $this->hook_suffix = add_options_page(
            __('EasySQL Settings', 'easysql'),
            __('EasySQL', 'easysql'),
            'manage_options',
            'easysql',
            [$this, 'render']
        );
    }

    /**
     * Register the setting and its validation.
     */
    public function register_settings(): void {
        register_setting('easysql', 'easysql_settings', [
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => [
                'api_key' => '',
                'timeout' => 30,
            ],
        ]);

        add_settings_section(
            'easysql_connection',
            __('Connection', 'easysql'),
            [$this, 'section_connection'],
            'easysql'
        );

        add_settings_field(
            'easysql_api_key',
            __('API Key', 'easysql'),
            [$this, 'field_api_key'],
            'easysql',
            'easysql_connection'
        );

        add_settings_field(
            'easysql_endpoint',
            __('Endpoint URL', 'easysql'),
            [$this, 'field_endpoint'],
            'easysql',
            'easysql_connection'
        );

        add_settings_field(
            'easysql_timeout',
            __('Timeout (seconds)', 'easysql'),
            [$this, 'field_timeout'],
            'easysql',
            'easysql_connection'
        );
    }

    /**
     * Render the settings page.
     */
    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'easysql'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('easysql');
                do_settings_sections('easysql');
                submit_button(__('Save Settings', 'easysql'));
                ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Test Connection', 'easysql'); ?></h2>
            <p><?php esc_html_e('Click the button below to verify that the current settings work.', 'easysql'); ?></p>
            <button type="button" id="easysql-test-btn" class="button button-secondary">
                <?php esc_html_e('Test Connection', 'easysql'); ?>
            </button>
            <span id="easysql-test-result" style="margin-left: 1em;"></span>

            <hr>

            <h2><?php esc_html_e('WordPress Connector', 'easysql'); ?></h2>
            <p><?php esc_html_e('Status of the automatic "wp" connector that represents this WordPress database.', 'easysql'); ?></p>
            <div id="easysql-connector-status">
                <span class="spinner" style="float:none;margin-top:0;"></span>
                <?php esc_html_e('Loading…', 'easysql'); ?>
            </div>
            <button type="button" id="easysql-sync-btn" class="button button-secondary" style="margin-top:0.5em;">
                <?php esc_html_e('Sync Schema Now', 'easysql'); ?>
            </button>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Section / field callbacks
    // -----------------------------------------------------------------------

    /**
     * Connection section description.
     */
    public function section_connection(): void {
        echo '<p>';
        esc_html_e(
            'Enter your EasySQL API key. The endpoint URL is read-only here and configured via the EASYSQL_ENDPOINT constant in wp-config.php.',
            'easysql'
        );
        echo '</p>';
    }

    /**
     * API key text field.
     */
    public function field_api_key(): void {
        $value = $this->get_option('api_key');
        printf(
            '<input type="password" id="easysql_api_key" name="easysql_settings[api_key]" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr($value)
        );
    }

    /**
     * Endpoint URL field (read-only — configured via EASYSQL_ENDPOINT).
     */
    public function field_endpoint(): void {
        $value = defined('EASYSQL_ENDPOINT')
            ? EASYSQL_ENDPOINT
            : 'https://api.easysql.net/v1';
        printf(
            '<input type="url" id="easysql_endpoint" value="%s" class="regular-text" disabled />',
            esc_attr($value)
        );
        echo ' <p class="description">';
        esc_html_e('Set the EASYSQL_ENDPOINT constant in wp-config.php to change this value.', 'easysql');
        echo '</p>';
    }

    /**
     * Timeout field.
     */
    public function field_timeout(): void {
        $value = $this->get_option('timeout', '30');
        printf(
            '<input type="number" id="easysql_timeout" name="easysql_settings[timeout]" value="%s" class="small-text" min="1" max="300" step="1" />',
            esc_attr((string) $value)
        );
    }

    // -----------------------------------------------------------------------
    // Sanitisation
    // -----------------------------------------------------------------------

    /**
     * Sanitize and validate submitted settings.
     *
     * @param  array $raw Raw input.
     * @return array
     */
    public function sanitize(array $raw): array {
        $clean = [];

        $clean['api_key'] = isset($raw['api_key'])
            ? sanitize_text_field($raw['api_key'])
            : '';

        $timeout = isset($raw['timeout']) ? absint($raw['timeout']) : 30;
        $clean['timeout'] = max(1, min(300, $timeout));

        return $clean;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Shorthand to retrieve a single stored option value.
     */
    private function get_option(string $key, $default = ''): string {
        $settings = get_option('easysql_settings', []);
        return isset($settings[$key]) ? (string) $settings[$key] : $default;
    }
}
