<?php
/** Owned fixture only: verify actual ZIP bytes and protected state during replacement. */
if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || ! in_array( (int) wp_parse_url( home_url(), PHP_URL_PORT ), array( 8917, 8918 ), true ) || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned fixture required.' ); }
global $wpdb;
$state = array(
    'servers' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A ) ) ),
    'upstream' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections ORDER BY server_id,user_id", ARRAY_A ) ) ),
    'pages' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'getmcp_page_assets_%' ORDER BY option_name", ARRAY_A ) ) ),
    'modules' => hash( 'sha256', wp_json_encode( array( get_option( 'getmcp_extensions_settings' ), get_option( 'getmcp_extensions_marketing_module' ) ) ) ),
    'active_plugins' => hash( 'sha256', wp_json_encode( get_option( 'active_plugins' ) ) ),
    'guard' => hash_file( 'sha256', WPMU_PLUGIN_DIR . '/getmcp-extensions-guard.php' ),
);
$file = '/evidence/connections-package-baseline.json';
if ( 'before' === ( $args[0] ?? '' ) ) { file_put_contents( $file, wp_json_encode( $state ) ); echo 'Captured owned protected-state hashes.'; return; }
$before = json_decode( file_get_contents( $file ), true ); $checks = array();
foreach ( $state as $key => $hash ) { $checks[$key . '_preserved'] = $before[$key] === $hash; }
$checks['installed_version'] = '1.2.0' === GETMCP_EXTENSIONS_VERSION;
$checks['runtime_ready'] = GetMCPExtensions\Runtime::ready();
$checks['public_updater_loaded'] = null !== GetMCPExtensions\Updater::checker();
$checks['installed_bytes_match_package'] = true;
foreach ( json_decode( file_get_contents( '/packages/files.json' ), true ) as $file => $hash ) { if ( ! is_file( WP_PLUGIN_DIR . '/getmcp-extensions/' . $file ) || hash_file( 'sha256', WP_PLUGIN_DIR . '/getmcp-extensions/' . $file ) !== $hash ) { $checks['installed_bytes_match_package'] = false; } }
if ( in_array( false, $checks, true ) ) { throw new RuntimeException( wp_json_encode( $checks ) ); }
$result = array( 'passed' => count( $checks ), 'checks' => $checks );
file_put_contents( '/evidence/connections-package.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) ); echo wp_json_encode( $result );
