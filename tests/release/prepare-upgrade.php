<?php
/** Baseline a genuine previous packaged release before a normal WordPress upgrade. */
if ( ! defined( 'ABSPATH' ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || ! in_array( (int) wp_parse_url( home_url(), PHP_URL_PORT ), array( 8917, 8918 ), true ) || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned release fixture required.' ); }
if ( GETMCP_EXTENSIONS_VERSION !== '1.1.2' ) { throw new RuntimeException( 'Install the genuine published 1.1.2 package before baselining.' ); }
global $wpdb;
$baseline = array(
    'servers' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A ) ) ),
    'upstream' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_upstream_connections ORDER BY server_id,user_id", ARRAY_A ) ) ),
    'pages' => hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'getmcp_page_assets_%' ORDER BY option_name", ARRAY_A ) ) ),
    'modules' => array( get_option( 'getmcp_extensions_settings' ), get_option( 'getmcp_extensions_marketing_module' ) ),
    'active_plugins' => get_option( 'active_plugins' ),
    'guard' => hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( WPMU_PLUGIN_DIR . '/getmcp-extensions-guard.php' ) ) ),
);
file_put_contents( '/evidence/upgrade-baseline.json', wp_json_encode( $baseline, JSON_PRETTY_PRINT ) );
GetMCPExtensions\Updater::checker()->resetUpdateState();
wp_clean_plugins_cache( true ); delete_site_transient( 'update_plugins' );
echo 'Baselined genuine published 1.1.2 client; protected-state hashes only.';
