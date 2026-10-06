<?php
/** Read back after WordPress's normal dashboard/Plugin_Upgrader installation. */
if ( ! defined( 'ABSPATH' ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || ! in_array( (int) wp_parse_url( home_url(), PHP_URL_PORT ), array( 8917, 8918 ), true ) || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned release fixture required.' ); }
global $wpdb;
$baseline = json_decode( file_get_contents( '/evidence/upgrade-baseline.json' ), true );
$now = array(
    'servers' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A ) ) ),
    'upstream' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections ORDER BY server_id,user_id", ARRAY_A ) ) ),
    'pages' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'getmcp_page_assets_%' ORDER BY option_name", ARRAY_A ) ) ),
    'modules' => array( get_option( 'getmcp_extensions_settings' ), get_option( 'getmcp_extensions_marketing_module' ) ),
    'active_plugins' => get_option( 'active_plugins' ),
    'guard' => hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( WPMU_PLUGIN_DIR . '/getmcp-extensions-guard.php' ) ) ),
);
$checks = array(); foreach ( $now as $key => $value ) { $checks[$key . '_preserved'] = $baseline[$key] === $value; }
$checks['installed_version'] = GETMCP_EXTENSIONS_VERSION === '1.2.0';
$checks['runtime_ready'] = GetMCPExtensions\Runtime::status()['ready'];
$checks['updater_loaded'] = GetMCPExtensions\Updater::checker() !== null;
$manifest = json_decode( file_get_contents( '/packages/files.json' ), true );
$checks['installed_bytes_equal_published_package'] = true;
foreach ( $manifest as $file => $hash ) { if ( hash_file( 'sha256', WP_PLUGIN_DIR . '/getmcp-extensions/' . $file ) !== $hash ) { $checks['installed_bytes_equal_published_package'] = false; } }
if ( in_array( false, $checks, true ) ) { throw new RuntimeException( wp_json_encode( $checks ) ); }
file_put_contents( '/evidence/upgrade-result.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'client' => 'Genuine published 1.1.2 package upgraded normally to 1.2.0.' ), JSON_PRETTY_PRINT ) );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
