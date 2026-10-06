<?php
/**
 * Plugin Name: GetMCP Extensions Endpoint Guard
 * Description: Preserves enhanced endpoint restrictions when the standalone add-on is unavailable.
 */
/** getmcp-extensions-owned-guard:v1
 * Persistent endpoint guard. Independent of the add-on files, survives deactivation/deletion.
 * Remove only after retiring/migrating protected records and reviewing the replacement access policy.
 */
if ( ! defined( 'ABSPATH' ) ) { return; }
if ( ! function_exists( 'getmcp_extensions_guard_row' ) ) {
function getmcp_extensions_guard_ready(): bool {
    return defined( 'GETMCP_EXTENSIONS_READY' ) && GETMCP_EXTENSIONS_READY && ! get_option( 'getmcp_extensions_deactivated' );
}
function getmcp_extensions_guard_marketing_missing(): bool {
    if ( class_exists( '\GetMCPExtensions\MarketingModule' ) ) { return false; }
    global $wpdb;
    foreach ( $wpdb->get_col( "SELECT settings FROM {$wpdb->prefix}getmcp_servers" ) ?: array() as $settings ) { if ( ! empty( ( json_decode( $settings ?: '{}', true ) ?: array() )['getmcp_extensions_required'] ) ) { return true; } }
    return false;
}
function getmcp_extensions_guard_row( object $row ): bool {
    $kind = $row->server_kind ?? 'native';
    $config = json_decode( $row->auth_config ?? '{}', true ) ?: array();
    $settings = json_decode( $row->settings ?? '{}', true ) ?: array();
    $requires_marketing = ! empty( $settings['getmcp_extensions_required'] );
    $managed = $requires_marketing || 'native' !== $kind || ( 'oauth' === ( $row->auth_type ?? '' ) && 'getmcp' === ( $config['provider'] ?? '' ) );
    if ( ! $managed ) { return false; }
    if ( ! getmcp_extensions_guard_ready() ) { return true; }
    if ( $requires_marketing && ( ! class_exists( '\GetMCPExtensions\MarketingModule' ) || ! \GetMCPExtensions\MarketingModule::enabled() ) ) { return true; }
    if ( 'gateway' === $kind && getmcp_extensions_guard_marketing_missing() ) { return true; }
    return ! \GetMCPExtensions\Runtime::server_enabled( $row );
}
function getmcp_extensions_guard_has_blocked(): bool {
    global $wpdb;
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}getmcp_servers" );
    foreach ( $rows ?: array() as $row ) { if ( getmcp_extensions_guard_row( $row ) ) { return true; } }
    return false;
}
add_action( 'template_redirect', static function() {
    if ( ! get_option( 'getmcp_extensions_guard_installed' ) ) { return; }
    global $wpdb;
    $slug = (string) get_query_var( 'getmcp_server_slug' );
    $gateway = '1' === (string) get_query_var( 'getmcp_gateway' );
    if ( ! $slug && ! $gateway ) { return; }
    if ( $gateway || in_array( $slug, array( 'gateway', 'hub', 'all', 'portal', 'aggregate', 'everything', 'mcp', 'getmcp' ), true ) ) { $blocked = ( ! getmcp_extensions_guard_ready() || getmcp_extensions_guard_marketing_missing() ) && getmcp_extensions_guard_has_blocked(); }
    else { $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}getmcp_servers WHERE slug=%s", $slug ) ); $blocked = $row && getmcp_extensions_guard_row( $row ); }
    if ( $blocked ) { nocache_headers(); status_header( 503 ); header( 'Content-Type: application/json' ); echo wp_json_encode( array( 'error' => 'extensions_unavailable', 'error_description' => 'This MCP endpoint needs an enabled, compatible GetMCP Extensions module. Configuration is preserved.' ) ); exit; }
}, -100 );
add_filter( 'rest_pre_dispatch', static function( $result, $service, $request ) {
    if ( ! get_option( 'getmcp_extensions_guard_installed' ) ) { return $result; }
    $route = $request->get_route();
    if ( getmcp_extensions_guard_ready() ) { return $result; }
    if ( preg_match( '#^/getmcp/v1/(connections|my-connections|upstreams|oauth-users)(/|$)#', $route ) || ( 'GET' !== $request->get_method() && str_starts_with( $route, '/getmcp/v1/' ) && getmcp_extensions_guard_has_blocked() ) ) {
        return new WP_Error( 'extensions_unavailable', 'Restore a compatible GetMCP Extensions module before changing protected server configuration.', array( 'status' => 503 ) );
    }
    return $result;
}, -200, 3 );

}
