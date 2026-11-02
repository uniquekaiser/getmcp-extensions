<?php
/** Establish and exercise non-personal remote delegation on the actual 1.7 core. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local 1.7 fixture required.' ); }
$mode = getenv( 'GETMCP_180_QA_GATEWAY_AUTH' ); if ( ! in_array( $mode, array( 'api-key', 'none' ), true ) ) { throw new RuntimeException( 'Synthetic Gateway mode required.' ); }
$path = '/tmp/getmcp-180-synthetic-http.json'; $state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
if ( ! is_array( $state ) || empty( $state['standard'] ) ) { throw new RuntimeException( 'Synthetic HTTP seed required.' ); }
wp_set_current_user( 1 ); global $wpdb;
foreach ( array( 'none', 'shared' ) as $kind ) {
	$args = array( 'kind' => 'remote-mcp', 'name' => 'QA 180 builtin remote ' . $kind, 'slug' => 'qa-180-builtin-remote-' . $kind, 'allowed_user_ids' => array(), 'remote' => array( 'endpoint' => 'https://example.org/qa-' . ( 'none' === $kind ? 'public' : 'shared' ) . '-mcp', 'auth_mode' => $kind, 'publish_original' => true ) );
	if ( 'shared' === $kind ) { $args['credentials'] = array( 'headers' => array( 'Authorization' => 'Bearer synthetic-shared' ) ); }
	$created = \GetMCP\Gateway\FeatureManager::run( 'save', $args, 1 ); $state['builtin_remote_' . $kind] = (int) $created['id'];
}
$ids = array( $state['builtin_remote_none'], $state['builtin_remote_shared'] );
\GetMCP\Gateway\McpGateway::set_auth_type( $mode ); \GetMCP\Gateway\McpGateway::set_mode( 'selected' ); \GetMCP\Gateway\McpGateway::set_selected_server_ids( $ids ); \GetMCP\Gateway\McpGateway::set_enabled( true );
$key = ''; $key_id = 0;
if ( 'api-key' === $mode ) { $key = 'qa180-' . wp_generate_password( 40, false, false ); if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_api_keys', array( 'server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'name' => 'QA 180 synthetic remote key', 'key_hash' => hash( 'sha256', $key ), 'key_prefix' => 'qa180', 'scopes' => '*', 'is_active' => 1 ) ) ) { throw new RuntimeException( 'Could not create synthetic key.' ); } $key_id = (int) $wpdb->insert_id; }
$base = \GetMCP\Gateway\McpGateway::resource(); $host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json' ); if ( $key ) { $headers['X-API-Key'] = $key; }
$response = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $base ), array( 'headers' => $headers, 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() ) ), 'redirection' => 0, 'timeout' => 30 ) );
if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Synthetic Gateway request failed.' ); }
$body = json_decode( wp_remote_retrieve_body( $response ), true ); $names = array_column( $body['result']['tools'] ?? array(), 'name' );
foreach ( array( 'none', 'shared' ) as $kind ) { $name = 'qa-180-builtin-remote-' . $kind . '__synthetic_read'; if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! in_array( $name, $names, true ) ) { throw new RuntimeException( 'The 1.7 Gateway did not expose its synthetic ' . $kind . ' remote member.' ); } }
$state += array( 'builtin_auth' => $mode, 'builtin_base' => $base, 'builtin_key' => $key, 'builtin_key_id' => $key_id );
foreach ( array( 'none', 'shared' ) as $kind ) {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT auth_config,settings,outbound_auth_credentials FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", (int) $state['builtin_remote_' . $kind] ), ARRAY_A );
	if ( ! is_array( $row ) ) { throw new RuntimeException( 'Could not inspect synthetic remote settings.' ); }
	$state['builtin_remote_hash_' . $kind] = hash( 'sha256', wp_json_encode( $row ) );
}
if ( false === file_put_contents( $path, wp_json_encode( $state ), LOCK_EX ) ) { throw new RuntimeException( 'Could not store synthetic state.' ); }
echo wp_json_encode( array( 'seeded' => true, 'fixture' => 'synthetic 1.7 remote Gateway' ) ) . PHP_EOL;
