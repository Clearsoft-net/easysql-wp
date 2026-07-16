<?php
/**
 * PHPUnit bootstrap — stubs out the WordPress runtime so the plugin's
 * src/ classes can be exercised in isolation.
 *
 * Strategy: PHP falls back from `EasySQL\func()` to global `func()` for
 * unqualified calls. We define the WP functions the plugin calls inside
 * the `EasySQL` namespace so the production code transparently uses our
 * stubs without any test-only hooks in the production code.
 *
 * What is stubbed:
 *   - get_option() — returns canned settings from $GLOBALS['__easysql_test_options'].
 *   - update_option() — writes to $GLOBALS['__easysql_test_options'].
 *   - delete_option() — deletes from $GLOBALS['__easysql_test_options'].
 *   - wp_parse_args() — minimal implementation of WP's wp_parse_args.
 *   - __(), _e(), esc_html__(), esc_html_e(), esc_html(), esc_attr() — passthroughs.
 *   - absint(), wp_create_nonce() — passthroughs.
 *
 * What is NOT stubbed (and what fails if called):
 *   - Anything else from WordPress core. Production code paths that
 *     reach un-stubbed WP functions will fatal — that's a signal to
 *     add another stub here or to refactor for DI.
 */

declare(strict_types=1);

namespace EasySQL;

use EasySQL\Tests\Support\ApiServer;

require __DIR__ . '/../vendor/autoload.php';

if (!isset($GLOBALS['__easysql_test_options'])) {
    $GLOBALS['__easysql_test_options'] = [];
}

// ── Shared test server ──────────────────────────────────────────────────────
// A single PHP built-in server subprocess is used by all test classes so that
// the EASYSQL_ENDPOINT constant is defined exactly once.
$apiServer = ApiServer::instance();
$apiServer->start();

if (!defined('EASYSQL_ENDPOINT')) {
    define('EASYSQL_ENDPOINT', $apiServer->getBaseUrl());
}

register_shutdown_function(function (): void {
    ApiServer::instance()->stop();
});

// ── WordPress stubs ─────────────────────────────────────────────────────────

function get_option(string $name, $default = false)
{
    return $GLOBALS['__easysql_test_options'][$name] ?? $default;
}

function update_option(string $option, $value, $autoload = null): bool
{
    $GLOBALS['__easysql_test_options'][$option] = $value;
    return true;
}

function delete_option(string $option): bool
{
    unset($GLOBALS['__easysql_test_options'][$option]);
    return true;
}

function wp_parse_args($args, $defaults = [])
{
    if (is_object($args)) {
        $args = (array) $args;
    }
    if (!is_array($args)) {
        $args = [];
    }
    return array_merge($defaults, $args);
}

function __($text, $domain = '')
{
    return $text;
}

function _e($text, $domain = '')
{
    echo $text;
}

function esc_html__($text, $domain = '')
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html_e($text, $domain = '')
{
    echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function absint($value)
{
    return abs((int) $value);
}

function wp_create_nonce($action = '')
{
    return 'test-nonce-' . $action;
}
