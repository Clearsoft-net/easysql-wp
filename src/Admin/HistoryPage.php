<?php

declare(strict_types=1);

namespace EasySQL\Admin;

/**
 * Admin page showing paginated query history for the "wp" connector.
 */
class HistoryPage {

    /**
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Register the admin page as a submenu under the EasySQL menu.
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_submenu_page']);
    }

    /**
     * Add submenu page under the EasySQL top-level menu.
     */
    public function add_submenu_page(): void {
        $this->hook_suffix = add_submenu_page(
            'easysql-ask',
            __('View all history', 'easysql'),
            __('View all history', 'easysql'),
            'manage_options',
            'easysql-history',
            [$this, 'render']
        );
    }

    /**
     * Return the hook suffix for asset enqueueing.
     */
    public function get_hook_suffix(): string {
        return $this->hook_suffix;
    }

    /**
     * Render the History page.
     */
    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'easysql'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Query History', 'easysql'); ?></h1>
            <p><?php esc_html_e('Past questions asked to the WordPress database.', 'easysql'); ?></p>

            <table class="wp-list-table widefat striped" id="easysql-history-table">
                <thead>
                    <tr>
                        <th scope="col" style="width:40%;"><?php esc_html_e('Question', 'easysql'); ?></th>
                        <th scope="col" style="width:30%;"><?php esc_html_e('Answer', 'easysql'); ?></th>
                        <th scope="col" style="width:20%;"><?php esc_html_e('Date', 'easysql'); ?></th>
                        <th scope="col" style="width:10%;"><?php esc_html_e('Action', 'easysql'); ?></th>
                    </tr>
                </thead>
                <tbody id="easysql-history-body">
                    <tr>
                        <td colspan="4">
                            <span class="spinner" style="float:none;visibility:visible;"></span>
                            <?php esc_html_e('Loading…', 'easysql'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div id="easysql-history-pagination" class="tablenav bottom" style="display:none;">
                <div class="tablenav-pages">
                    <span class="displaying-num" id="easysql-history-total"></span>
                    <span class="pagination-links">
                        <button type="button" id="easysql-prev-page" class="button button-small" disabled>&laquo;</button>
                        <span class="paging-input">
                            <span id="easysql-current-page">1</span>
                            <?php esc_html_e('of', 'easysql'); ?>
                            <span id="easysql-total-pages">1</span>
                        </span>
                        <button type="button" id="easysql-next-page" class="button button-small" disabled>&raquo;</button>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }
}
