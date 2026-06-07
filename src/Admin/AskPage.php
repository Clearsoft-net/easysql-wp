<?php

declare(strict_types=1);

namespace EasySQL\Admin;

/**
 * Admin page for asking natural-language questions about the WordPress
 * database.
 */
class AskPage {

    /**
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Register the admin page.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
    }

    /**
     * Add the top-level menu page.
     */
    public function add_menu_page(): void {
        $this->hook_suffix = add_menu_page(
            __('EasySQL Ask', 'easysql'),
            __('EasySQL', 'easysql'),
            'manage_options',
            'easysql-ask',
            [$this, 'render'],
            'dashicons-editor-table',
            30
        );
    }

    /**
     * Return the hook suffix for asset enqueueing.
     */
    public function get_hook_suffix(): string {
        return $this->hook_suffix;
    }

    /**
     * Render the Ask page.
     */
    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'easysql'));
        }

        $initial_question = isset($_GET['question'])
            ? sanitize_text_field(wp_unslash($_GET['question']))
            : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Ask your database', 'easysql'); ?></h1>
            <p><?php esc_html_e('Type a question in plain language about your WordPress data.', 'easysql'); ?></p>

            <div id="easysql-ask-form">
                <textarea
                    id="easysql-question"
                    class="large-text"
                    rows="3"
                    placeholder="<?php esc_attr_e('e.g. How many users registered this month?', 'easysql'); ?>"
                ><?php echo esc_textarea($initial_question); ?></textarea>
                <p>
                    <button
                        type="button"
                        id="easysql-ask-btn"
                        class="button button-primary"
                    >
                        <?php esc_html_e('Ask', 'easysql'); ?>
                    </button>
                    <span id="easysql-ask-status" style="margin-left:1em;"></span>
                </p>
            </div>

            <div id="easysql-ask-result" style="display:none;">
                <hr>

                <h2><?php esc_html_e('Answer', 'easysql'); ?></h2>
                <div id="easysql-answer" class="notice notice-success" style="padding:12px;"></div>

                <h3>
                    <?php esc_html_e('SQL Generated', 'easysql'); ?>
                    <button type="button" id="easysql-toggle-sql" class="button button-small">
                        <?php esc_html_e('Show', 'easysql'); ?>
                    </button>
                </h3>
                <pre id="easysql-sql" style="display:none;background:#f5f5f5;padding:10px;overflow-x:auto;"></pre>

                <h3><?php esc_html_e('Results', 'easysql'); ?></h3>
                <div id="easysql-result-table"></div>

                <div id="easysql-chart-container" style="display:none;max-width:600px;margin-top:1em;">
                    <canvas id="easysql-chart"></canvas>
                </div>
            </div>
        </div>
        <?php
    }
}
