<?php
/** Owned WordPress fixture: consent destinations, refreshable status and private REST responses. */
use GetMCPExtensions\ProviderConnections as PC;
use GetMCPExtensions\ProviderTokens as PT;
use GetMCP\Remote\UpstreamConnections as UC;
use GetMCP\Core\ServerManager as SM;

if ( wp_parse_url( home_url(), PHP_URL_HOST ) !== 'localhost' || ! get_option( 'getmcp_qa_paid_fixture' ) ) { throw new RuntimeException( 'Owned release fixture required.' ); }
$checks = array();
$assert = function( $ok, $name ) use ( &$checks ) { $checks[$name] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } };
$reject = function( callable $fn, $name ) use ( $assert ) { try { $fn(); } catch ( Throwable $e ) { $assert( true, $name ); return; } $assert( false, $name ); };
$previous_user = get_current_user_id(); $mode = get_option( 'getmcp_fixture_provider_mode', false );
$sm = new SM(); $servers = array();
$user = (int) ( username_exists( 'marketing-a' ) ?: wp_create_user( 'marketing-a', 'disposable-fixture-only', 'marketing-a@example.invalid' ) );
$list = function( $id, $user ) { foreach ( PC::listing( $user ) as $item ) { if ( $item['id'] === $id ) { return $item; } } throw new RuntimeException( 'Fixture connection missing.' ); };
try {
    update_option( 'getmcp_fixture_provider_mode', 'valid' ); wp_set_current_user( 1 );
    $config = GetMCPExtensions\ProviderDiscovery::presets()['google-analytics']['auth_config'] + array( 'client_id' => 'fixture-google-application' );
    $server = $sm->create( array( 'name' => 'Marketing Status Fixture Google', 'slug' => 'marketing-status-fixture', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( $config ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'fixture-secret' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allowed_user_ids' => array( 1, $user ) ) ) ) ) );
    $servers[] = $server->id;
    $flow = function( $who, $context, $query_context = null ) use ( $server ) {
        wp_set_current_user( $who );
        $url = PC::begin( $server, $who, $context )['authorization_url']; $query = array(); wp_parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
        $callback = array( 'state' => $query['state'], 'code' => 'fixture-code-a' ); if ( null !== $query_context ) { $callback['return_to'] = $query_context; }
        return PC::complete( $callback, $who, wp_parse_url( PC::callback( $server ), PHP_URL_PATH ) );
    };
    $assert( $flow( 1, 'admin' ) === admin_url( 'admin.php?page=getmcp-my-connections' ), 'admin_consent_returns_to_wordpress_admin' );
    $assert( $flow( 1, 'portal', 'admin' ) === UC::portal_url( 'portal' ), 'return_context_bound_to_state_not_callback_query' );
    $assert( $flow( 1, 'https://evil.invalid/' ) === admin_url( 'admin.php?page=getmcp-my-connections' ), 'external_redirect_context_rejected' );
    $assert( $flow( $user, 'admin' ) === admin_url( 'profile.php?page=getmcp-account-connections' ), 'subscriber_cannot_be_sent_to_management_screen' );
    $assert( $flow( $user, 'profile' ) === admin_url( 'profile.php?page=getmcp-account-connections' ), 'profile_consent_preserves_admin_chrome' );
    $assert( $flow( $user, 'portal' ) === UC::portal_url( 'portal' ), 'standalone_portal_remains_available' );
    $assert( UC::portal_url() === admin_url( 'profile.php?page=getmcp-account-connections' ), 'old_state_defaults_to_accessible_connections_screen' );
    $item = $list( $server->id, $user );
    $assert( $item['connected'] && ! $item['reconnect_required'] && ! $item['refresh_pending'], 'valid_connection_status' );
    $g = UC::get( $server->id, $user ); $d = $g['data']; $d['expires_at'] = time() - 60;
    UC::save( $server->id, $user, $d, $g['version'] ); $expired_version = UC::version( $server->id, $user );
    $item = $list( $server->id, $user );
    $assert( $item['connected'] && ! $item['reconnect_required'] && $item['refresh_pending'], 'expired_refreshable_google_connection_does_not_request_consent' );
    $assert( $item['readiness']['user_connected'] && ! $item['readiness']['read_verified'], 'refreshable_connection_does_not_invent_read_evidence' );
    $assert( UC::version( $server->id, $user ) === $expired_version, 'listing_does_not_refresh_or_modify_credentials' );
    $g = PC::grant( $server, $user );
    $assert( $g['data']['expires_at'] > time() + 30 && $g['version'] !== $expired_version, 'next_provider_request_renews_access_token' );
    $assert( ! $list( $server->id, $user )['refresh_pending'], 'renewed_connection_status_is_current' );
    $d = $g['data']; $d['expires_at'] = time() - 60; unset( $d['refresh_token'] ); UC::save( $server->id, $user, $d, $g['version'] );
    $assert( $list( $server->id, $user )['reconnect_required'], 'expired_connection_without_refresh_requires_consent' );
    $d['refresh_token'] = 'fixture-refresh-a'; $g = UC::get( $server->id, $user ); UC::save( $server->id, $user, $d, $g['version'] ); $before = UC::get( $server->id, $user );
    update_option( 'getmcp_fixture_provider_mode', 'revoke' );
    $reject( fn() => PC::grant( $server, $user ), 'revoked_refresh_fails_without_shared_fallback' );
    $assert( UC::get( $server->id, $user ) === $before, 'failed_refresh_preserves_grant_for_explicit_reconnection' );
    update_option( 'getmcp_fixture_provider_mode', 'valid' );
    $meta = clone $server; $meta->auth_config = wp_json_encode( array( 'token_url' => 'https://graph.facebook.com/v26.0/oauth/access_token' ) );
    $assert( ! PC::refresh_available( $meta, $d ), 'meta_never_uses_generic_google_refresh_status' );
    $sm->update( $server->id, array( 'status' => 'paused' ) );
    $reject( fn() => PC::grant( $sm->get( $server->id ), $user ), 'paused_provider_immediately_denies_use' );
    $assert( ! array_filter( PC::listing( $user ), fn( $i ) => $i['id'] === $server->id ), 'paused_provider_removed_from_listing' );
    $sm->update( $server->id, array( 'status' => 'active' ) );
    $request = new WP_REST_Request( 'GET', '/getmcp/v1/my-connections' );
    // rest_do_request dispatches internally; the HTTP server applies post_dispatch when serving it.
    $response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
    $assert( $response->get_status() === 200 && str_contains( $response->get_headers()['Cache-Control'] ?? '', 'private, no-store' ), 'authenticated_status_is_private_and_not_cacheable' );
    $assert( str_contains( $response->get_headers()['Vary'] ?? '', 'Cookie' ) && str_contains( $response->get_headers()['Vary'] ?? '', 'Authorization' ), 'status_varies_by_authentication_identity' );
    $assert( ! str_contains( wp_json_encode( $response->get_data() ), 'fixture-refresh-a' ), 'status_never_returns_refresh_credentials' );
    wp_set_current_user( 0 ); $response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
    $assert( $response->get_status() >= 400 && str_contains( $response->get_headers()['Cache-Control'] ?? '', 'no-store' ), 'unauthenticated_status_error_is_not_cacheable' );
} finally {
    wp_set_current_user( 1 ); foreach ( $servers as $id ) { $sm->delete( $id ); }
    false === $mode ? delete_option( 'getmcp_fixture_provider_mode' ) : update_option( 'getmcp_fixture_provider_mode', $mode );
    wp_set_current_user( $previous_user );
}
file_put_contents( '/evidence/connection-status.json', wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'provider_http' => 'synthetic', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ), JSON_PRETTY_PRINT ) );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ) );
