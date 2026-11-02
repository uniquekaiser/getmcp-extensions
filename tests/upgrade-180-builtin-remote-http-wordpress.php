<?php
/** Exercise the same Gateway and upstream credentials after the real 1.8 upgrade. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( ! is_array( $state ) || empty( $state['builtin_remote_none'] ) || empty( $state['builtin_remote_shared'] ) || ! in_array( $state['builtin_auth'] ?? '', array( 'api-key', 'none' ), true ) ) { throw new RuntimeException( 'Synthetic remote Gateway state required.' ); }
$checks = 0; $check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function( string $method, array $params = array() ) use ( $host, $state ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method );
	if ( $state['builtin_key'] ) { $headers['X-API-Key'] = $state['builtin_key']; }
	$params['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() );
	$response = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $state['builtin_base'] ), array( 'headers' => $headers, 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Synthetic Gateway request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $response ), 'body' => json_decode( wp_remote_retrieve_body( $response ), true ) );
};
$list = $http( 'tools/list' ); $names = array_column( $list['body']['result']['tools'] ?? array(), 'name' );
foreach ( array( 'none', 'shared' ) as $kind ) {
	$name = 'qa-180-builtin-remote-' . $kind . '__synthetic_read';
	$check( 200 === $list['status'] && in_array( $name, $names, true ), 'The original Gateway lists its ' . $kind . ' upstream.' );
	$call = $http( 'tools/call', array( 'name' => $name, 'arguments' => new stdClass() ) );
	$check( 200 === $call['status'] && 'synthetic upstream result' === ( $call['body']['result']['content'][0]['text'] ?? null ), 'The original Gateway calls its ' . $kind . ' upstream.' );
}
$resources = $http( 'resources/list' ); $prompts = $http( 'prompts/list' );
$check( 200 === $resources['status'] && 2 === count( $resources['body']['result']['resources'] ?? array() ), 'Both upstream resource lists remain available.' );
$check( 200 === $prompts['status'] && 2 === count( $prompts['body']['result']['prompts'] ?? array() ), 'Both upstream prompt lists remain available.' );
global $wpdb; $id = (int) $state['builtin_remote_shared']; $table = $wpdb->prefix . 'getmcp_servers';
$original = $wpdb->get_row( $wpdb->prepare( "SELECT name,outbound_auth_credentials FROM {$table} WHERE id=%d", $id ), ARRAY_A );
if ( ! is_array( $original ) || 'QA 180 builtin remote shared' !== $original['name'] ) { throw new RuntimeException( 'Unexpected synthetic remote.' ); }
try {
	$changed = \GetMCP\Utils\Encryption::encrypt( wp_json_encode( array( 'headers' => array( 'Authorization' => 'Bearer changed-synthetic' ) ) ) );
	if ( 1 !== $wpdb->update( $table, array( 'outbound_auth_credentials' => $changed ), array( 'id' => $id, 'name' => $original['name'] ) ) ) { throw new RuntimeException( 'Could not update synthetic credential.' ); }
	$changed_list = $http( 'tools/list' );
	$check( ! in_array( 'qa-180-builtin-remote-shared__synthetic_read', array_column( $changed_list['body']['result']['tools'] ?? array(), 'name' ), true ), 'A changed upstream credential does not inherit the prior Gateway delegation.' );
} finally {
	$wpdb->update( $table, array( 'outbound_auth_credentials' => $original['outbound_auth_credentials'] ), array( 'id' => $id, 'name' => $original['name'] ) );
}
update_option( 'getmcp_gateway_servers', array( (int) $state['builtin_remote_none'] ) );
$removed = $http( 'tools/list' );
$check( ! in_array( 'qa-180-builtin-remote-shared__synthetic_read', array_column( $removed['body']['result']['tools'] ?? array(), 'name' ), true ), 'Removing a member revokes its Gateway delegation.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic remote Gateway 1.7-to-1.8 HTTP' ) ) . PHP_EOL;
