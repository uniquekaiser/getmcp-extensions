<?php
/** Test pre-upgrade synthetic API-key or public Gateway access over HTTP. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 HTTP fixture required.' ); }
global $wpdb; $state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true ); $mode = $state['builtin_auth'] ?? '';
if ( empty( $state['builtin_member'] ) || ! in_array( $mode, array( 'api-key', 'none' ), true ) ) { throw new RuntimeException( 'Synthetic Gateway state required.' ); }
$member = ( new \GetMCP\Core\ServerManager() )->get( (int) $state['builtin_member'] ); $checks = 0; $new_key_id = 0;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$snapshot = json_decode( (string) get_option( \GetMCPExtensions\LegacyGateway180::OPTION ), true );
$check( $member && 'wordpress' === $member->auth_type && ( $snapshot['members'][(string) $member->id] ?? '' ) === $member->uuid, 'The pre-upgrade Gateway member was captured and converted.' );
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function( string $url, array $body, string $key = '', string $method = '' ) use ( $host ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28' );
	if ( $key ) { $headers['X-API-Key'] = $key; } if ( $method ) { $headers['Mcp-Method'] = $method; $body['params']['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ); }
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic Gateway HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$rpc = static fn( string $method, array $params = array() ): array => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params );
try {
	$key = $state['builtin_key']; $list = $http( $state['builtin_base'], $rpc( 'tools/list' ), $key, 'tools/list' );
	$check( 200 === $list['status'] && in_array( $state['builtin_tool'], array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ), 'The original Gateway access lists its selected member.' );
	$call = $http( $state['builtin_base'], $rpc( 'tools/call', array( 'name' => $state['builtin_tool'] ) ), $key, 'tools/call' );
	$check( 200 === $call['status'] && empty( $call['body']['error'] ) && empty( $call['body']['result']['isError'] ), 'The original Gateway access calls its member without forwarding the Gateway key.' );
	$check( 401 === $http( $state['builtin_member_url'], $rpc( 'tools/list' ), $key, 'tools/list' )['status'], 'Gateway access does not grant direct member access.' );
	if ( 'api-key' === $mode ) {
		$new_key = 'qa180-' . wp_generate_password( 40, false, false );
		if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_api_keys', array( 'server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'name' => 'QA 180 synthetic new Gateway key', 'key_hash' => hash( 'sha256', $new_key ), 'key_prefix' => 'qa180', 'scopes' => '*', 'is_active' => 1 ) ) ) { throw new RuntimeException( 'Could not create synthetic new key.' ); } $new_key_id = (int) $wpdb->insert_id;
		$new_list = $http( $state['builtin_base'], $rpc( 'tools/list' ), $new_key, 'tools/list' );
		$check( 200 === $new_list['status'] && ! in_array( $state['builtin_tool'], array_column( $new_list['body']['result']['tools'] ?? array(), 'name' ), true ), 'A key created after the upgrade does not inherit the old delegation.' );
		$wpdb->update( $wpdb->prefix . 'getmcp_api_keys', array( 'is_active' => 0 ), array( 'id' => (int) $state['builtin_key_id'], 'name' => 'QA 180 synthetic Gateway key' ) );
		$check( 401 === $http( $state['builtin_base'], $rpc( 'tools/list' ), $key, 'tools/list' )['status'], 'Revoking the old key immediately stops its access.' );
	} else {
		update_option( 'getmcp_gateway_servers', array() ); $removed = $http( $state['builtin_base'], $rpc( 'tools/call', array( 'name' => $state['builtin_tool'] ) ), '', 'tools/call' );
		$check( isset( $removed['body']['error'] ) || ! empty( $removed['body']['result']['isError'] ), 'Removing the public member immediately stops delegation.' );
	}
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic key or public Gateway 1.7-to-1.8 HTTP' ) ) . PHP_EOL;
} finally { if ( $new_key_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_api_keys', array( 'id' => $new_key_id, 'name' => 'QA 180 synthetic new Gateway key' ) ); } }
