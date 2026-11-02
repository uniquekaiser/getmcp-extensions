<?php
/** Seed only synthetic OAuth state in the snapshotted local 1.7 fixture. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Local reviewed 1.7 fixture required.' ); }
wp_set_current_user( 1 );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );
$server = ( new \GetMCP\Core\ServerManager() )->create( array( 'name' => 'QA 180 existing grant', 'slug' => 'qa-180-existing-grant', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) ), 'settings' => '{}' ) );
if ( ! $server || 'qa-180-existing-grant' !== $server->slug ) { throw new RuntimeException( 'Synthetic server could not be created.' ); }
$base = $server->get_endpoint_url();
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function ( string $url, array $body, bool $form = false ) use ( $host ): array {
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => array( 'Host' => $host, 'Accept' => 'application/json', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json' ), 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 10 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic OAuth request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$redirect = 'https://example.org/fixture-callback';
$registered = $http( $base . '/oauth/register', array( 'client_name' => 'QA 180 synthetic client', 'redirect_uris' => array( $redirect ), 'token_endpoint_auth_method' => 'none' ) );
$client = $registered['body']['client_id'] ?? '';
if ( 201 !== $registered['status'] || ! $client ) { throw new RuntimeException( 'Synthetic registration failed.' ); }
global $wpdb;
$client_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
$verifier = str_repeat( 'v', 64 );
$request = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
$issue = new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' );
$code = $issue->invoke( null, $client_row, 1, $request, $base );
$token = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), true );
if ( 200 !== $token['status'] || empty( $token['body']['access_token'] ) || empty( $token['body']['refresh_token'] ) ) { throw new RuntimeException( 'Synthetic grant was not issued.' ); }
$pending = $issue->invoke( null, $client_row, 1, $request, $base );
if ( ! is_string( $pending ) || '' === $pending ) { throw new RuntimeException( 'Synthetic pending authorization code was not issued.' ); }
$state = array( 'id' => $server->id, 'base' => $base, 'client' => $client, 'access' => $token['body']['access_token'], 'refresh' => $token['body']['refresh_token'], 'pending_code' => $pending, 'verifier' => $verifier, 'redirect' => $redirect );
if ( false === file_put_contents( '/tmp/getmcp-180-synthetic-grant.json', wp_json_encode( $state ), LOCK_EX ) ) { throw new RuntimeException( 'Could not store synthetic test state.' ); }
echo wp_json_encode( array( 'seeded' => true, 'server_id' => $server->id ) ) . PHP_EOL;
