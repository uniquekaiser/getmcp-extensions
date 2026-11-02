<?php
/** Actual OAuth and MCP HTTP path using only a disposable local provider stub. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 HTTP fixture required.' ); }
global $wpdb; wp_set_current_user( 1 ); $manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager();
$slug = 'qa-http-personal-' . substr( wp_generate_uuid4(), 0, 8 ); $server = null; $tool_id = 0; $client_row = 0; $checks = 0;
$options = array(); foreach ( array( 'getmcp_gateway_enabled', 'getmcp_gateway_mode', 'getmcp_gateway_auth_type', 'getmcp_gateway_servers', 'getmcp_gateway_namespace_tools' ) as $key ) { $options[$key] = get_option( $key, false ); }
$check = static function( $ok, $message ) use ( &$checks ) { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
 $server = $manager->create( array( 'name' => 'QA personal HTTP provider', 'slug' => $slug, 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_url' => 'https://oauth2.googleapis.com/token', 'client_id' => 'synthetic-client' ) ), 'settings' => wp_json_encode( array( 'personal_provider' => array( 'allowed_user_ids' => array( 1 ) ) ) ) ) );
 if ( ! $server ) { throw new RuntimeException( 'Could not create provider fixture.' ); }
 $tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA personal HTTP read', 'slug' => 'qa_personal_http_read', 'endpoint_url' => 'https://www.googleapis.com/qa-personal-http', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) ); if ( ! $tool ) { throw new RuntimeException( 'Could not create tool fixture.' ); } $tool_id = $tool->id;
 \GetMCP\Remote\UpstreamConnections::save( $server->id, 1, array( 'kind' => 'rest-provider', 'config_hash' => \GetMCPExtensions\ProviderConnections::fingerprint( $server ), 'access_token' => 'synthetic-personal-http', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600 ) );
 \GetMCP\Gateway\McpGateway::set_enabled( true ); \GetMCP\Gateway\McpGateway::set_auth_type( 'oauth' ); \GetMCP\Gateway\McpGateway::set_mode( 'selected' ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $server->id ) ); \GetMCP\Gateway\McpGateway::set_namespacing( true );
 $base = \GetMCP\Gateway\McpGateway::resource(); $host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
 $http = static function( string $url, array $body, bool $form = false, string $token = '', string $method = '' ) use ( $host ): array {
  $headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json' );
  if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; } if ( $method ) { $headers['Mcp-Method'] = $method; }
  $r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
  if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic HTTP request failed.' ); } return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
 };
 $redirect = 'https://example.org/fixture-callback'; $registered = $http( $base . '/oauth/register', array( 'client_name' => 'QA personal HTTP client', 'redirect_uris' => array( $redirect ), 'token_endpoint_auth_method' => 'none' ) );
 $client = $registered['body']['client_id'] ?? ''; $check( 201 === $registered['status'] && '' !== $client, 'Gateway OAuth client registration failed (HTTP ' . $registered['status'] . ', code ' . ( $registered['body']['error'] ?? 'none' ) . ').' );
 $client_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
 $verifier = str_repeat( 'v', 64 ); $request = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
 $code = ( new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' ) )->invoke( null, $client_row, 1, $request, $base );
 $token = $http( $base . '/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base ), true );
 $access = $token['body']['access_token'] ?? ''; $check( 200 === $token['status'] && '' !== $access, 'Gateway OAuth grant failed.' );
 $rpc = static fn( $method, $params = array() ) => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: new stdClass() );
 $name = $server->slug . '__' . $tool->slug; $list = $http( $base, $rpc( 'tools/list' ), false, $access, 'tools/list' );
 $check( 200 === $list['status'] && in_array( $name, array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ), 'Authenticated Gateway did not list the personal tool.' );
 $call = $http( $base, $rpc( 'tools/call', array( 'name' => $name, 'arguments' => new stdClass() ) ), false, $access, 'tools/call' );
 $check( 200 === $call['status'] && empty( $call['body']['error'] ) && empty( $call['body']['result']['isError'] ) && str_contains( wp_json_encode( $call['body']['result'] ?? array() ), 'personal-token-accepted' ), 'Gateway call failed or used the wrong upstream identity.' );
 $check( 401 === $http( $server->get_endpoint_url(), $rpc( 'tools/list' ), false, $access, 'tools/list' )['status'], 'Gateway token must not authorize the provider directly.' );
 echo wp_json_encode( array( 'passed' => $checks ) ) . PHP_EOL;
} finally {
 foreach ( $options as $key => $value ) { if ( false === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
 if ( $client_row ) { $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_tokens', array( 'client_id' => $client_row ) ); $wpdb->delete( $wpdb->prefix . 'getmcp_oauth_clients', array( 'id' => $client_row ) ); }
 if ( $server ) { $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $server->id, 'user_id' => 1 ) ); } if ( $tool_id ) { $tools->delete( $tool_id ); } if ( $server && ( $current = $manager->get( $server->id ) ) && $current->slug === $slug ) { $manager->delete( $server->id ); }
}
