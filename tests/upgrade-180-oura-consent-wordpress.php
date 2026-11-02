<?php
/** Synthetic Oura consent, state replay and personal token exchange. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager();
$slug = 'qa-oura-consent-' . substr( wp_generate_uuid4(), 0, 8 );
$server = $manager->create( array( 'name' => 'QA Oura consent', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://cloud.ouraring.com/oauth/authorize', 'token_url' => 'https://api.ouraring.com/oauth/token', 'client_id' => 'synthetic-client', 'scope' => 'personal daily' ) ), 'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-secret' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allow_all_users' => true ) ) ) ) );
if ( ! $server ) { throw new RuntimeException( 'Fixture creation failed.' ); }
$old_user = get_current_user_id(); wp_set_current_user( 1 ); $calls = 0; $challenge = '';
$http = static function( $pre, $args, $url ) use ( &$calls, &$challenge ) {
 if ( 'https://api.ouraring.com/oauth/token' !== $url ) { return $pre; }
 ++$calls; parse_str( $args['body'], $body );
 $actual = rtrim( strtr( base64_encode( hash( 'sha256', $body['code_verifier'] ?? '', true ) ), '+/', '-_' ), '=' );
 if ( 'authorization_code' !== ( $body['grant_type'] ?? '' ) || 'fixture-code' !== ( $body['code'] ?? '' ) || ! hash_equals( $challenge, $actual ) ) { throw new RuntimeException( 'The exchange did not use its bound PKCE verifier.' ); }
 return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_in' => 3600, 'token_type' => 'bearer' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $http, 0, 3 );
try {
 $started = \GetMCPExtensions\ProviderConnections::begin( $server, 1, 'admin' );
 parse_str( (string) wp_parse_url( $started['authorization_url'], PHP_URL_QUERY ), $query );
 if ( 'S256' !== ( $query['code_challenge_method'] ?? '' ) || empty( $query['state'] ) || empty( $query['code_challenge'] ) ) { throw new RuntimeException( 'Oura consent must include state and PKCE.' ); }
 $challenge = $query['code_challenge'];
 \GetMCPExtensions\ProviderConnections::complete( array( 'state' => $query['state'], 'code' => 'fixture-code' ), 1, wp_parse_url( \GetMCPExtensions\ProviderConnections::callback( $server ), PHP_URL_PATH ) );
 $saved = \GetMCP\Remote\UpstreamConnections::get( $server->id, 1 );
 if ( 1 !== $calls || 'fixture-access' !== ( $saved['data']['access_token'] ?? null ) || 'fixture-refresh' !== ( $saved['data']['refresh_token'] ?? null ) || \GetMCPExtensions\ProviderConnections::fingerprint( $server ) !== ( $saved['data']['config_hash'] ?? null ) ) { throw new RuntimeException( 'The grant was not saved for its owner and configuration.' ); }
 $refused = false; try { \GetMCPExtensions\ProviderConnections::complete( array( 'state' => $query['state'], 'code' => 'fixture-code' ), 1 ); } catch ( \Throwable $e ) { $refused = true; }
 if ( ! $refused || 1 !== $calls ) { throw new RuntimeException( 'A consumed state must not trigger another token exchange.' ); }
 echo wp_json_encode( array( 'passed' => 3 ) ) . PHP_EOL;
} finally {
 remove_filter( 'pre_http_request', $http, 0 ); wp_set_current_user( $old_user );
 $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => 1 ) );
 $manager->delete( $server->id );
}
