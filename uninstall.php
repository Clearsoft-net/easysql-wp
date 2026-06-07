<?php
/**
 * EasySQL – Uninstall routine.
 *
 * Cleans up plugin options when the plugin is deleted via the WordPress admin.
 *
 * @package EasySQL
 */

// Exit if not called by WordPress uninstall mechanism.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove the settings option.
delete_option('easysql_settings');

// If the option was stored in a multisite network, clean that too.
delete_site_option('easysql_settings');
