<?php
/** Create a synthetic 1.7 built-in gateway grant before the local upgrade. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local 1.7 fixture required.' ); }
global $wpdb; wp_set_current_user( 1 );
$path = '/tmp/getmcp-180-synthetic-http.json'; $state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
if ( ! is_array( $state ) || empty( $state['standard'] ) ) { throw new RuntimeException( 'Synthetic HTTP seed required.' ); }
$manager = new \GetMCP\Core\ServerManager();
$member = $manager->create( array( 'name' => 'QA 180 builtin HTTP member', 'slug' => 'qa-180-builtin-http-member', 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => '{}' ) );
if ( ! $member || 'qa-180-builtin-http-member' !== $member->slug ) { throw new RuntimeException( 'Could not create synthetic gateway member.' ); }
$tool = ( new \GetMCP\Core\ToolManager() )->create( array( 'server_id' => $member->id, 'name' => 'QA builtin HTTP tool', 'endpoint_url' => 'https://example.org/qa-native-api', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic gateway tool.' ); }
$mode = 'all' === getenv( 'GETMCP_180_QA_GATEWAY_MODE' ) ? 'all' : 'selected';
\GetMCP\Gateway\McpGateway::set_auth_type( 'oauth' ); \GetMCP\Gateway\McpGateway::set_mode( $mode ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $member->id ) ); \GetMCP\Gateway\McpGateway::set_enabled( true );
$base = \GetMCP\Gateway\McpGateway::resource(); $host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function( string $url, array $body, bool $form = false, string $token = '', string $method = '' ) use ( $host ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json' );
	if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; } if ( $method ) { $headers['Mcp-Method'] = $method; }
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic built-in gateway HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$redirect = 'https://example.org/fixture-callback'; $registered = $http( $base . '/oauth/register', array( 'client_name' => 'QA 180 built-in gateway client', 'redirect_uris' => array( $redirect ), 'token_endpoint_auth_method' => 'none' ) );
$client = $registered['body']['client_id'] ?? ''; if ( 201 !== $registered['status'] || ! $client ) { throw new RuntimeException( 'Synthetic built-in gateway registration failed: ' . $registered['status'] ); }
$client_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
$verifier = str_repeat( 'v', 64 ); $request = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
$code = ( new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' ) )->invoke( null, $client_row, 1, $request, $base );
$token = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), true );
if ( 200 !== $token['status'] || empty( $token['body']['access_token'] ) || empty( $token['body']['refresh_token'] ) ) { throw new RuntimeException( 'Synthetic built-in gateway grant was not issued.' ); }
$list = $http( $base, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() ), false, $token['body']['access_token'], 'tools/list' );
$name = $member->slug . '__' . $tool->slug;
if ( 200 !== $list['status'] || ! in_array( $name, array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ) ) { throw new RuntimeException( 'The 1.7 built-in gateway did not expose the selected synthetic member.' ); }
$state += array( 'builtin_member' => $member->id, 'builtin_mode' => $mode, 'builtin_base' => $base, 'builtin_member_url' => $member->get_endpoint_url(), 'builtin_tool' => $name, 'builtin_client' => $client, 'builtin_access' => $token['body']['access_token'], 'builtin_refresh' => $token['body']['refresh_token'] );
if ( false === file_put_contents( $path, wp_json_encode( $state ), LOCK_EX ) ) { throw new RuntimeException( 'Could not store synthetic gateway state.' ); }
echo wp_json_encode( array( 'seeded' => true, 'fixture' => 'synthetic built-in gateway grant' ) ) . PHP_EOL;
