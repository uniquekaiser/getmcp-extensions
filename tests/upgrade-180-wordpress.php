<?php
/** Run only on a snapshotted local fixture with the four supplied synthetic IDs. */
if ( wp_get_environment_type() !== 'local' || ! getenv( 'GETMCP_180_IDS' ) ) { throw new RuntimeException( 'Local fixture and explicit synthetic IDs are required.' ); }
if ( '' === (string) get_option( 'permalink_structure' ) ) { throw new RuntimeException( 'This HTTP integration test requires fixture pretty permalinks.' ); }
$ids = array_map( 'intval', explode( ',', getenv( 'GETMCP_180_IDS' ) ) );
if ( count( $ids ) !== 4 || count( array_unique( $ids ) ) !== 4 || min( $ids ) < 1 ) { throw new RuntimeException( 'Invalid synthetic IDs.' ); }
list( $direct_id, $empty_id, $remote_id, $gateway_id ) = $ids;
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$row = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
foreach ( array( $direct_id => 'qa-180-migration-direct', $empty_id => 'qa-180-migration-empty' ) as $id => $slug ) {
    $r = $row( $id ); if ( ! $r || $r['slug'] !== $slug ) { throw new RuntimeException( 'Unexpected fixture server identity.' ); }
}
$gateway = $row( $gateway_id ); $remote = $row( $remote_id );
if ( ! $gateway || 'QA 180 migration gateway' !== $gateway['name'] || ! $remote || 'QA 180 migration remote' !== $remote['name'] ) { throw new RuntimeException( 'Unexpected gateway or remote fixture.' ); }
$checks = 0;
$assert = static function( $condition, string $message ) use ( &$checks ): void { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
$read = static fn( int $id ) => \GetMCPExtensions\Upgrade180::preview( $id );
$move = static fn( int $id, string $revision ) => \GetMCPExtensions\Upgrade180::migrate( $id, $revision );
wp_set_current_user( 1 );
$before = $row( $direct_id );
$initial = $read( $direct_id );
$assert( 'blocked' === $initial['state'] && in_array( 'gateway_member', $initial['reasons'], true ), 'legacy gateway prevents premature conversion' );
$assert( 'blocked' === $read( $remote_id )['state'], 'legacy remote is kept on the compatibility path' );
$assert( ! str_contains( wp_json_encode( $initial ), 'synthetic-outbound-token' ) && ! str_contains( wp_json_encode( $initial ), $before['outbound_auth_credentials'] ), 'preview contains no secrets or ciphertext' );
$legacy_store = \GetMCPExtensions\Upgrade180Store::SEPARATE === get_option( \GetMCPExtensions\Upgrade180Store::OPTION ) ? $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX : $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX;
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$legacy_store} WHERE server_id=%d", $remote_id ) ), 'legacy grant survives preview' );
wp_set_current_user( 0 );
$request = new WP_REST_Request( 'GET', '/getmcp-extensions/v1/upgrade-180/' . $direct_id );
$assert( in_array( rest_do_request( $request )->get_status(), array( 401, 403 ), true ), 'anonymous preview is refused' );
$assert( is_wp_error( $move( $direct_id, $initial['revision'] ) ), 'anonymous migration is refused' );
wp_set_current_user( 1 );
$assert( 1 === $wpdb->delete( $table, array( 'id' => $gateway_id, 'name' => 'QA 180 migration gateway' ) ), 'only the synthetic gateway is removed' );
$stale = $move( $direct_id, $initial['revision'] );
$assert( is_wp_error( $stale ) && 'upgrade_conflict' === $stale->get_error_code() && $before === $row( $direct_id ), 'changed dependencies reject a stale review without a write' );
$gateway_option = get_option( 'getmcp_gateway_enabled', false );
update_option( 'getmcp_gateway_enabled', 1 );
$assert( 'blocked' === $read( $direct_id )['state'], 'enabled original gateway prevents conversion' );
if ( false === $gateway_option ) { delete_option( 'getmcp_gateway_enabled' ); } else { update_option( 'getmcp_gateway_enabled', $gateway_option ); }
$assert( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_bundle_members', array( 'bundle_id' => $gateway_id, 'server_id' => $direct_id ) ), 'synthetic native bundle membership created' );
$assert( 'blocked' === $read( $direct_id )['state'], 'native bundle membership prevents conversion' );
$assert( 1 === $wpdb->delete( $wpdb->prefix . 'getmcp_bundle_members', array( 'bundle_id' => $gateway_id, 'server_id' => $direct_id ) ), 'synthetic native membership removed' );
$tool_uuid = wp_generate_uuid4();
$assert( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_tools', array( 'uuid' => $tool_uuid, 'server_id' => $direct_id, 'name' => 'Synthetic migration tool', 'slug' => 'synthetic-migration-tool', 'description' => '', 'endpoint_url' => 'https://example.invalid/mcp', 'status' => 'draft' ) ), 'synthetic tool created' );
$tool_id = (int) $wpdb->insert_id;
$assert( 'blocked' === $read( $direct_id )['state'], 'even draft tools keep the server protected' );
$assert( 1 === $wpdb->delete( $wpdb->prefix . 'getmcp_tools', array( 'id' => $tool_id, 'uuid' => $tool_uuid ) ), 'synthetic tool removed' );
$client_id = 'qa-180-' . wp_generate_uuid4();
$assert( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_oauth_clients', array( 'server_id' => $direct_id, 'client_id' => $client_id, 'client_name' => 'Synthetic 180 client', 'redirect_uris' => '[]' ) ), 'synthetic client created' );
$client_row = (int) $wpdb->insert_id;
$assert( 'blocked' === $read( $direct_id )['state'], 'registered server client prevents conversion' );
$assert( 1 === $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_clients', array( 'id' => $client_row, 'client_id' => $client_id ) ), 'synthetic client removed' );
$assert( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_oauth_clients', array( 'server_id' => 0, 'client_id' => $client_id, 'client_name' => 'Synthetic shared 180 client', 'redirect_uris' => '[]' ) ), 'synthetic shared client created' );
$client_row = (int) $wpdb->insert_id;
$resource = \GetMCP\Auth\FirstPartyOAuth::canonical_resource( ( new \GetMCP\Core\ServerManager() )->get( $direct_id ) );
$assert( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'client_id' => $client_row, 'user_id' => 1, 'access_token_hash' => hash( 'sha256', 'synthetic-180' ), 'resource' => $resource, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) ), 'synthetic resource-bound grant created' );
$token_row = (int) $wpdb->insert_id;
$assert( 'blocked' === $read( $direct_id )['state'], 'shared client grant for this resource prevents conversion' );
$assert( 1 === $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'id' => $token_row, 'client_id' => $client_row ) ), 'synthetic grant removed' );
$assert( 1 === $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_clients', array( 'id' => $client_row, 'client_id' => $client_id ) ), 'synthetic shared client removed' );
$plan = $read( $direct_id );
$assert( 'eligible' === $plan['state'], 'standalone WordPress sign-in can be reviewed' );
$changed = json_decode( $before['auth_config'], true ); $changed['test_revision'] = 'synthetic-change';
$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $changed ) ), array( 'id' => $direct_id ) );
$assert( is_wp_error( $move( $direct_id, $plan['revision'] ) ) && 'oauth' === $row( $direct_id )['auth_type'], 'concurrent row changes reject a stale review' );
$before = $row( $direct_id ); $plan = $read( $direct_id );
$result = $move( $direct_id, $plan['revision'] );
$assert( ! is_wp_error( $result ) && 'migrated' === $result['state'], 'eligible server migrates' );
$after = $row( $direct_id );
$assert( 'wordpress' === $after['auth_type'], 'native WordPress sign-in is selected' );
$before['auth_type'] = 'wordpress';
$assert( $before === $after, 'all other stored fields and encrypted credentials remain byte-identical' );
$native = ( new \GetMCP\Core\ServerManager() )->get( $direct_id );
$assert( \GetMCP\Auth\ServerUserAccess::can_user_access( 1, $native ) && ! \GetMCP\Auth\ServerUserAccess::can_user_access( 999999, $native ), 'native allowlist admits and denies the same users' );
$assert( ! getmcp_extensions_guard_row( (object) $after ), 'migrated standard endpoint no longer needs the legacy guard' );
$assert( is_wp_error( $move( $direct_id, $plan['revision'] ) ) && $after === $row( $direct_id ), 'repeated request makes no second change' );
// Real local HTTP exchange; consent itself is deliberately seeded, not browser tested.
$base = $native->get_endpoint_url();
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
$http = static function( string $url, array $body, string $bearer = '', bool $form = false ) use ( $host ): array {
    $headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'MCP-Protocol-Version' => '2026-07-28' );
    if ( '' !== $bearer ) { $headers['Authorization'] = 'Bearer ' . $bearer; }
    $response = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 10 ) );
    if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Synthetic HTTP request failed.' ); }
    return array( 'status' => wp_remote_retrieve_response_code( $response ), 'body' => json_decode( wp_remote_retrieve_body( $response ), true ) );
};
$redirect = 'https://example.org/fixture-callback';
$registration = $http( $base . '/oauth/register', array( 'client_name' => 'Synthetic 180 OAuth', 'redirect_uris' => array( $redirect ), 'grant_types' => array( 'authorization_code', 'refresh_token' ), 'token_endpoint_auth_method' => 'none' ) );
$assert( 201 === $registration['status'] && ! empty( $registration['body']['client_id'] ), 'migrated server accepts local client registration' );
$client = $registration['body']['client_id'];
$client_db_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
$verifier = bin2hex( random_bytes( 32 ) );
$authorize = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
$issue = new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' );
$code = $issue->invoke( null, $client_db_id, 1, $authorize, $base );
$exchange = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), '', true );
$assert( 200 === $exchange['status'] && ! empty( $exchange['body']['access_token'] ), 'migrated server exchanges a scoped local authorization code' );
$access = $exchange['body']['access_token'];
$rpc = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) array() );
$assert( 401 === $http( $base, $rpc )['status'], 'migrated endpoint refuses anonymous MCP calls' );
$call = $http( $base, $rpc, $access );
$assert( 200 === $call['status'] && isset( $call['body']['result']['tools'] ), 'migrated endpoint accepts an allowed user token' );
$denied_config = json_decode( $after['auth_config'], true ); $denied_config['allowed_user_ids'] = array();
$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $denied_config ) ), array( 'id' => $direct_id ) );
$assert( 401 === $http( $base, $rpc, $access )['status'], 'removing the user immediately revokes MCP access' );
$empty_before = $row( $empty_id ); $empty_plan = $read( $empty_id );
$assert( 'eligible' === $empty_plan['state'] && ! is_wp_error( $move( $empty_id, $empty_plan['revision'] ) ), 'empty allowlist migrates' );
$empty = ( new \GetMCP\Core\ServerManager() )->get( $empty_id );
$assert( ! \GetMCP\Auth\ServerUserAccess::can_user_access( 1, $empty ), 'empty allowlist continues to deny administrators' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_upstream_connections WHERE server_id=%d", $remote_id ) ), 'unmigrated remote grant remains stored' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.7 to 1.8 database' ) ) . PHP_EOL;
