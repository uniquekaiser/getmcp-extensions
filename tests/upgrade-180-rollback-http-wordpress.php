<?php
/** Verify original synthetic OAuth and Gateway credentials after a core rollback. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Isolated rolled-back 1.7 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
if ( ! is_array( $state ) || empty( $state['access'] ) || empty( $state['refresh'] ) || 'api-key' !== ( $state['builtin_auth'] ?? null ) ) { throw new RuntimeException( 'Synthetic rollback state required.' ); }
$checks = 0; $check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function( string $url, array $body, array $extra = array(), bool $form = false ) use ( $host ): array {
	$headers = array_merge( array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json' ), $extra );
	$response = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Synthetic rollback HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $response ), 'body' => json_decode( wp_remote_retrieve_body( $response ), true ) );
};
$rpc = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass() );
$remote = ( new \GetMCP\Core\ServerManager() )->get( (int) $state['remote'] );
$list = $http( $state['base'], $rpc, array( 'Authorization' => 'Bearer ' . $state['access'] ) );
$check( 200 === $list['status'] && in_array( $remote->slug . '__synthetic_read', array_column( $list['body']['result']['tools'] ?? array(), 'name' ), true ), 'The original project Gateway access token works after rollback.' );
$check( 401 === $http( $state['remote_url'], $rpc, array( 'Authorization' => 'Bearer ' . $state['access'] ) )['status'], 'The Gateway token still cannot access its remote member directly.' );
$refresh = $http( $state['base'] . '/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $state['refresh'], 'client_id' => $state['client'], 'resource' => $state['base'] ), array(), true );
$check( 200 === $refresh['status'] && ! empty( $refresh['body']['access_token'] ) && ! empty( $refresh['body']['refresh_token'] ), 'The original project Gateway refresh grant works after rollback.' );
$builtin = $http( $state['builtin_base'], $rpc, array( 'X-API-Key' => $state['builtin_key'] ) ); $names = array_column( $builtin['body']['result']['tools'] ?? array(), 'name' );
$check( 200 === $builtin['status'] && in_array( 'qa-180-builtin-remote-none__synthetic_read', $names, true ) && in_array( 'qa-180-builtin-remote-shared__synthetic_read', $names, true ), 'The original API key and both built-in Gateway members work again after rollback.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic HTTP access after 1.7 rollback' ) ) . PHP_EOL;
