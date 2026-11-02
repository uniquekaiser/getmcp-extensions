<?php
/** Verify an actual 1.7 built-in Gateway grant after the local 1.8 upgrade. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 HTTP fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( empty( $state['builtin_member'] ) || empty( $state['builtin_access'] ) ) { throw new RuntimeException( 'Synthetic built-in Gateway grant required.' ); }
$manager = new \GetMCP\Core\ServerManager(); $member = $manager->get( (int) $state['builtin_member'] ); $checks = 0;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$snapshot = json_decode( (string) get_option( \GetMCPExtensions\LegacyGateway180::OPTION ), true );
$check( $member && 'wordpress' === $member->auth_type && ( $snapshot['members'][(string) $member->id] ?? '' ) === $member->uuid, 'The legacy member migrated and its pre-upgrade selection was captured.' );
$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $member ), 'Direct access remains denied.' );
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function( string $url, array $body, string $token = '', bool $form = false, string $method = '' ) use ( $host ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'MCP-Protocol-Version' => '2026-07-28' );
	if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; } if ( $method ) { $headers['Mcp-Method'] = $method; $body['params']['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ); }
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic built-in gateway HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$rpc = static fn( string $method, array $params = array() ): array => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params );
$list = $http( $state['builtin_base'], $rpc( 'tools/list' ), $state['builtin_access'], false, 'tools/list' );
if ( 200 !== $list['status'] || ! in_array( $state['builtin_tool'], array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ) ) { echo wp_json_encode( array( 'http_status' => $list['status'], 'rpc_error' => $list['body']['error']['code'] ?? null, 'listed_count' => count( $list['body']['result']['tools'] ?? array() ) ) ) . PHP_EOL; }
$check( 200 === $list['status'] && in_array( $state['builtin_tool'], array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ), 'The original 1.7 Gateway grant lists its selected member.' );
$call = $http( $state['builtin_base'], $rpc( 'tools/call', array( 'name' => $state['builtin_tool'] ) ), $state['builtin_access'], false, 'tools/call' );
$check( 200 === $call['status'] && empty( $call['body']['error'] ) && empty( $call['body']['result']['isError'] ), 'The original Gateway grant calls the member without forwarding its bearer.' );
$check( 401 === $http( $state['builtin_member_url'], $rpc( 'tools/list' ), $state['builtin_access'], false, 'tools/list' )['status'], 'The Gateway bearer cannot access the member directly.' );
$refresh = $http( $state['builtin_base'] . '/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $state['builtin_refresh'], 'client_id' => $state['builtin_client'], 'resource' => $state['builtin_base'] ), '', true );
$check( 200 === $refresh['status'] && ! empty( $refresh['body']['access_token'] ), 'The original Gateway refresh grant remains usable.' );
if ( 'all' === ( $state['builtin_mode'] ?? 'selected' ) ) { update_option( 'getmcp_gateway_mode', 'selected' ); }
update_option( 'getmcp_gateway_servers', array() );
$removed = $http( $state['builtin_base'], $rpc( 'tools/call', array( 'name' => $state['builtin_tool'] ) ), $refresh['body']['access_token'], false, 'tools/call' );
$check( isset( $removed['body']['error'] ) || ! empty( $removed['body']['result']['isError'] ), 'Removing the selected member immediately stops delegated calls.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic built-in Gateway 1.7-to-1.8 HTTP' ) ) . PHP_EOL;
