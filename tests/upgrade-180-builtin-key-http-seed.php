<?php
/** Create a synthetic 1.7 API-key or public Gateway before a local upgrade. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local 1.7 fixture required.' ); }
global $wpdb; wp_set_current_user( 1 );
$mode = getenv( 'GETMCP_180_QA_GATEWAY_AUTH' ); if ( ! in_array( $mode, array( 'api-key', 'none' ), true ) ) { throw new RuntimeException( 'Synthetic Gateway mode required.' ); }
$path = '/tmp/getmcp-180-synthetic-http.json'; $state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
if ( ! is_array( $state ) || empty( $state['standard'] ) ) { throw new RuntimeException( 'Synthetic HTTP seed required.' ); }
$manager = new \GetMCP\Core\ServerManager();
$member = $manager->create( array( 'name' => 'QA 180 builtin HTTP member', 'slug' => 'qa-180-builtin-http-member', 'status' => 'active', 'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => '{}' ) );
if ( ! $member || 'qa-180-builtin-http-member' !== $member->slug ) { throw new RuntimeException( 'Could not create synthetic Gateway member.' ); }
$tool = ( new \GetMCP\Core\ToolManager() )->create( array( 'server_id' => $member->id, 'name' => 'QA builtin HTTP tool', 'endpoint_url' => 'https://example.org/qa-native-api', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic Gateway tool.' ); }
\GetMCP\Gateway\McpGateway::set_auth_type( $mode ); \GetMCP\Gateway\McpGateway::set_mode( 'selected' ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( array( $member->id ) ); \GetMCP\Gateway\McpGateway::set_enabled( true );
$key = ''; $key_id = 0;
if ( 'api-key' === $mode ) {
	$key = 'qa180-' . wp_generate_password( 40, false, false );
	if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_api_keys', array( 'server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'name' => 'QA 180 synthetic Gateway key', 'key_hash' => hash( 'sha256', $key ), 'key_prefix' => 'qa180', 'scopes' => '*', 'is_active' => 1 ) ) ) { throw new RuntimeException( 'Could not create synthetic Gateway key.' ); } $key_id = (int) $wpdb->insert_id;
}
$base = \GetMCP\Gateway\McpGateway::resource(); $host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'Mcp-Method' => 'tools/list' ); if ( $key ) { $headers['X-API-Key'] = $key; }
$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $base ), array( 'headers' => $headers, 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() ) ), 'redirection' => 0, 'timeout' => 30 ) );
if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic Gateway HTTP request failed.' ); }
$body = json_decode( wp_remote_retrieve_body( $r ), true ); $name = $member->slug . '__' . $tool->slug;
if ( 200 !== wp_remote_retrieve_response_code( $r ) || ! in_array( $name, array_column( $body['result']['tools'] ?? array(), 'name' ), true ) ) { throw new RuntimeException( 'The 1.7 Gateway did not expose the selected synthetic member.' ); }
$state += array( 'builtin_member' => $member->id, 'builtin_mode' => 'selected', 'builtin_auth' => $mode, 'builtin_base' => $base, 'builtin_member_url' => $member->get_endpoint_url(), 'builtin_tool' => $name, 'builtin_key' => $key, 'builtin_key_id' => $key_id );
if ( false === file_put_contents( $path, wp_json_encode( $state ), LOCK_EX ) ) { throw new RuntimeException( 'Could not store synthetic Gateway state.' ); }
echo wp_json_encode( array( 'seeded' => true, 'fixture' => 'synthetic key or public Gateway' ) ) . PHP_EOL;
