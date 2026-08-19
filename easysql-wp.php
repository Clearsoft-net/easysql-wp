<?php
/**
 * Plugin Name: EasySQL
 * Plugin URI:  https://github.com/Clearsoft-net/easysql-wp
 * Description: Integrate EasySQL into WordPress — run queries, manage data, and build reports with ease.
 * Version:     0.1.0
 * Author:      Clearsoft
 * Author URI:  https://clearsoft.net
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: easysql
 * Domain Path: /languages
 *
 * @package EasySQL
 */

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------
// Idempotency guard — prevents redeclaring functions/hooks when this file is
// loaded more than once (e.g. a plugin folder is duplicated or an activation
// runs while the plugin is already booted). Without this, WordPress fails
// with "Cannot redeclare easysql_container()".
// ---------------------------------------------------------------------------

if (defined('EASYSQL_VERSION')) {
    return;
}

// ---------------------------------------------------------------------------
// Autoload
// ---------------------------------------------------------------------------

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define('EASYSQL_VERSION', '0.1.0');
define('EASYSQL_FILE',    __FILE__);
define('EASYSQL_DIR',     plugin_dir_path(__FILE__));
define('EASYSQL_URL',     plugin_dir_url(__FILE__));

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Returns the main plugin container instance.
 *
 * @return \EasySQL\Plugin
 */
function easysql_container(): \EasySQL\Plugin {
    static $container = null;
    if ($container === null) {
        $container = new \EasySQL\Plugin();
    }
    return $container;
}

// Hook into WordPress lifecycle.
add_action('plugins_loaded', function () {
    easysql_container()->boot();
});

register_activation_hook(__FILE__, [\EasySQL\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\EasySQL\Plugin::class, 'deactivate']);
