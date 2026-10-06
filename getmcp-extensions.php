<?php
/**
 * Plugin Name: GetMCP Extensions
 * Plugin URI: https://github.com/uniquekaiser/getmcp-extensions
 * Author: Synergetic Dev
 * Author URI: https://synergetic.dev/
 * Update URI: https://github.com/uniquekaiser/getmcp-extensions
 * Description: Optional native OAuth/user access, remote MCP connections and project gateways for GetMCP.
 * Version: 1.2.0
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Requires Plugins: getmcp
 * License: GPL-2.0-or-later
 * Text Domain: getmcp-extensions
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'GETMCP_EXTENSIONS_VERSION', '1.2.0' );
require_once __DIR__ . '/includes/class-updater.php';
add_action( 'plugins_loaded', array( '\GetMCPExtensions\Updater', 'boot' ), 5 );
define( 'GETMCP_EXTENSIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'GETMCP_EXTENSIONS_URL', plugin_dir_url( __FILE__ ) );
require_once __DIR__ . '/includes/class-runtime.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
spl_autoload_register( static function( $class ) {
    if ( ! str_starts_with( $class, 'GetMCPExtensions\\' ) ) { return; }
    $name = substr( $class, strlen( 'GetMCPExtensions\\' ) );
    if ( 'SettingsConflict' === $name ) { $name = 'AuthenticationSettings'; }
    $file = __DIR__ . '/includes/class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $name ) ) . '.php';
    if ( is_file( $file ) ) { require_once $file; }
}, true, true );
// A persistent, small guard is installed on activation; this copy protects the first request too.
if ( ! function_exists( 'getmcp_extensions_guard_ready' ) ) { require_once __DIR__ . '/includes/endpoint-guard.php'; }
add_action( 'plugins_loaded', array( '\GetMCPExtensions\Runtime', 'boot' ), -100 );
register_activation_hook( __FILE__, array( '\GetMCPExtensions\Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\GetMCPExtensions\Lifecycle', 'deactivate' ) );
