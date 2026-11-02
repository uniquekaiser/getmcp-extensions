<?php
/** Issue a synthetic gateway grant on 1.7 for isolated HTTP upgrade rehearsal. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Local reviewed 1.7 fixture required.' ); }
wp_set_current_user( 1 );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules( false );
ob_start();
require GETMCP_EXTENSIONS_PATH . 'tests/upgrade-180-auto-seed.php';
$seed = json_decode( (string) ob_get_clean(), true );
if ( empty( $seed['remote'] ) || empty( $seed['gateway'] ) ) { throw new RuntimeException( 'Synthetic gateway fixtures were not created.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$row = $wpdb->get_row( $wpdb->prepare( "SELECT auth_config FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", $seed['remote'] ), ARRAY_A );
$config = json_decode( $row['auth_config'] ?? '', true );
if ( ! is_array( $config ) ) { throw new RuntimeException( 'Synthetic remote config missing.' ); }
$config['allowed_user_ids'] = array();
if ( 1 !== $wpdb->update( $table, array( 'auth_config' => wp_json_encode( $config ) ), array( 'id' => (int) $seed['remote'], 'name' => 'QA 180 automatic remote' ) ) ) { throw new RuntimeException( 'Could not configure synthetic direct-access refusal.' ); }
$manager = new \GetMCP\Core\ServerManager();
$gateway = $manager->get( (int) $seed['gateway'] );
$remote = $manager->get( (int) $seed['remote'] );
$base = $gateway->get_endpoint_url();
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function ( string $url, array $body, bool $form = false ) use ( $host ): array {
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => array( 'Host' => $host, 'Accept' => 'application/json', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json' ), 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 90 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic gateway OAuth request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$redirect = 'https://example.org/fixture-callback';
$registered = $http( $base . '/oauth/register', array( 'client_name' => 'QA 180 gateway client', 'redirect_uris' => array( $redirect ), 'token_endpoint_auth_method' => 'none' ) );
$client = $registered['body']['client_id'] ?? '';
if ( 201 !== $registered['status'] || ! $client ) { throw new RuntimeException( 'Synthetic gateway client registration failed.' ); }
$client_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
$verifier = str_repeat( 'w', 64 );
$request = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
$code = ( new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' ) )->invoke( null, $client_row, 1, $request, $base );
$token = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), true );
if ( 200 !== $token['status'] || empty( $token['body']['access_token'] ) || empty( $token['body']['refresh_token'] ) ) { throw new RuntimeException( 'Synthetic gateway grant was not issued.' ); }
$request['scope'] = 'mcp:read';
$read_code = ( new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' ) )->invoke( null, $client_row, 1, $request, $base );
$read_token = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $read_code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), true );
if ( 200 !== $read_token['status'] || empty( $read_token['body']['access_token'] ) ) { throw new RuntimeException( 'Synthetic read-only gateway grant was not issued.' ); }
$standard = $manager->create( array( 'name' => 'QA 180 automatic standard', 'slug' => 'qa-180-automatic-standard', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) ), 'settings' => '{}' ) );
if ( ! $standard || 'qa-180-automatic-standard' !== $standard->slug ) { throw new RuntimeException( 'Synthetic standard server was not created.' ); }
$tool_uuid = wp_generate_uuid4();
if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_tools', array( 'uuid' => $tool_uuid, 'server_id' => $standard->id, 'name' => 'QA standard tool', 'slug' => 'qa_180_standard_tool', 'description' => '', 'endpoint_url' => 'https://example.org/qa-native-api', 'http_method' => 'GET', 'tool_type' => 'http', 'input_schema' => '{"type":"object","properties":{}}', 'status' => 'active' ) ) ) { throw new RuntimeException( 'Synthetic standard tool was not created.' ); }
$standard_base = $standard->get_endpoint_url();
$registered_standard = $http( $standard_base . '/oauth/register', array( 'client_name' => 'QA 180 standard client', 'redirect_uris' => array( $redirect ), 'token_endpoint_auth_method' => 'none' ) );
$standard_client = $registered_standard['body']['client_id'] ?? '';
if ( 201 !== $registered_standard['status'] || ! $standard_client ) { throw new RuntimeException( 'Synthetic standard client registration failed.' ); }
$standard_client_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $standard_client ) );
$request['scope'] = 'mcp:read mcp:write';
$standard_code = ( new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' ) )->invoke( null, $standard_client_row, 1, $request, $standard_base );
$standard_token = $http( $standard_base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $standard_code, 'code_verifier' => $verifier, 'client_id' => $standard_client, 'redirect_uri' => $redirect, 'resource' => $standard_base ), true );
if ( 200 !== $standard_token['status'] || empty( $standard_token['body']['access_token'] ) || empty( $standard_token['body']['refresh_token'] ) ) { throw new RuntimeException( 'Synthetic standard grant was not issued.' ); }
$fields = array( 'id', 'uuid', 'user_id', 'name', 'slug', 'server_id', 'status', 'transport_type', 'auth_config', 'auth_credentials', 'outbound_auth_type', 'outbound_auth_config', 'outbound_auth_credentials', 'test_auth_type', 'test_auth_credentials', 'cors_origins', 'rate_limit_per_min', 'settings', 'server_kind', 'created_at', 'updated_at' );
$fingerprint = static function ( int $id ) use ( $wpdb, $table, $fields ): string {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
	return hash( 'sha256', wp_json_encode( array_intersect_key( $row, array_flip( $fields ) ) ) );
};
$state = array( 'gateway' => (int) $seed['gateway'], 'remote' => (int) $seed['remote'], 'standard' => (int) $standard->id, 'base' => $base, 'remote_url' => $remote->get_endpoint_url(), 'standard_url' => $standard_base, 'standard_client' => $standard_client, 'standard_access' => $standard_token['body']['access_token'], 'standard_refresh' => $standard_token['body']['refresh_token'], 'client' => $client, 'access' => $token['body']['access_token'], 'read_access' => $read_token['body']['access_token'], 'refresh' => $token['body']['refresh_token'], 'grant_hash' => $seed['grant_hash'], 'gateway_hash' => $fingerprint( (int) $seed['gateway'] ), 'remote_hash' => $fingerprint( (int) $seed['remote'] ), 'standard_hash' => $fingerprint( (int) $standard->id ) );
if ( false === file_put_contents( '/tmp/getmcp-180-synthetic-http.json', wp_json_encode( $state ), LOCK_EX ) ) { throw new RuntimeException( 'Could not store synthetic HTTP test state.' ); }
echo wp_json_encode( array( 'seeded' => true, 'gateway' => $state['gateway'], 'remote' => $state['remote'] ) ) . PHP_EOL;
