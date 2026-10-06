<?php
/** Owned fixture only: module/deactivation HTTP guards and byte preservation. */
global $wpdb; wp_set_current_user( 1 );
$f = get_option( 'getmcp_marketing_browser_fixtures' ); $m = new GetMCP\Core\ServerManager(); $s = $m->get( $f['instagram']['id'] );
$checks = array(); $assert = function( $ok, $name ) use ( &$checks ) { $checks[$name] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } };
$before = $wpdb->get_results( "SELECT id,auth_config,auth_credentials,outbound_auth_credentials,settings,status FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A );
$prior = get_option( 'getmcp_extensions_marketing_module', false );
try {
    update_option( 'getmcp_extensions_marketing_module', array( 'enabled' => false, 'revision' => 1 ) );
    $assert( ! GetMCPExtensions\Runtime::server_enabled( $s ) && getmcp_extensions_guard_row( (object) $s->to_array() ), 'marketing_switch_denies_marked_endpoint' );
    $assert( GetMCP\Remote\UpstreamConnections::get( $s->id, $f['users'][0] ) !== null, 'marketing_switch_keeps_encrypted_personal_connection' );
} finally { false === $prior ? delete_option( 'getmcp_extensions_marketing_module' ) : update_option( 'getmcp_extensions_marketing_module', $prior ); }
$http = function( $url ) { return wp_remote_get( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => array( 'Host' => wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT ) ), 'redirection' => 0, 'timeout' => 10 ) ); };
$active = get_option( 'active_plugins' );
try {
    update_option( 'active_plugins', array_values( array_diff( $active, array( 'getmcp-extensions/getmcp-extensions.php' ) ) ) );
    $assert( wp_remote_retrieve_response_code( $http( $s->get_endpoint_url() ) ) === 503, 'persistent_guard_blocks_page_endpoint_without_addon' );
    $assert( wp_remote_retrieve_response_code( $http( home_url( '/mcp' ) ) ) === 503, 'persistent_guard_blocks_original_gateway_without_runtime' );
} finally { update_option( 'active_plugins', $active ); }
$after = $wpdb->get_results( "SELECT id,auth_config,auth_credentials,outbound_auth_credentials,settings,status FROM {$wpdb->prefix}getmcp_servers ORDER BY id", ARRAY_A );
$assert( $before === $after, 'module_and_runtime_removal_preserve_all_server_configuration' );
$assert( hash_file( 'sha256', WPMU_PLUGIN_DIR . '/getmcp-extensions-guard.php' ) === hash_file( 'sha256', GETMCP_EXTENSIONS_PATH . 'includes/endpoint-guard.php' ), 'owned_guard_upgrade_matches_current_source' );
file_put_contents( '/evidence/marketing-lifecycle.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT ) ); echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
