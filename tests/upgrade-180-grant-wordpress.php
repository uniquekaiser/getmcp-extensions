<?php
/** Investigate pre-upgrade OAuth grants on a disposable 1.8 fixture. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION ) { throw new RuntimeException( 'Local 1.8 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-grant.json' ), true );
if ( ! is_array( $state ) || empty( $state['id'] ) || empty( $state['access'] ) || empty( $state['refresh'] ) ) { throw new RuntimeException( 'Synthetic grant state missing.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $state['id'] ), ARRAY_A );
if ( ! $row || 'qa-180-existing-grant' !== $row['slug'] || 'oauth' !== $row['auth_type'] ) { throw new RuntimeException( 'Synthetic legacy server required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$host = wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . wp_parse_url( home_url(), PHP_URL_PORT );
$http = static function ( string $url, array $body, string $access = '', bool $form = false ) use ( $host ): array {
	$headers = array( 'Host' => $host, 'Accept' => 'application/json, text/event-stream', 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list' );
	if ( $access ) { $headers['Authorization'] = 'Bearer ' . $access; }
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 10 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic HTTP request failed.' ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$rpc = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ) ) );
$check( 503 === $http( $state['base'], $rpc, $state['access'] )['status'], 'Legacy route is guarded while runtime is unready.' );
$check( 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET auth_type='wordpress',updated_at=updated_at WHERE id=%d AND slug='qa-180-existing-grant' AND auth_type='oauth'", $state['id'] ) ), 'Only synthetic server was converted for the experiment.' );
$pending_params = array( 'grant_type' => 'authorization_code', 'code' => $state['pending_code'], 'code_verifier' => $state['verifier'], 'client_id' => $state['client'], 'redirect_uri' => $state['redirect'], 'resource' => $state['base'] );
$wrong_redirect = $pending_params;
$wrong_redirect['redirect_uri'] = 'https://example.org/other-callback';
$check( 400 === $http( $state['base'] . '/oauth/token', $wrong_redirect, '', true )['status'], 'Pending code rejects a different redirect.' );
$pending = $http( $state['base'] . '/oauth/token', $pending_params, '', true );
$check( 200 === $pending['status'] && ! empty( $pending['body']['access_token'] ), 'Pre-upgrade pending code retains its exact redirect binding.' );
$result = $http( $state['base'], $rpc, $state['access'] );
$check( 200 === $result['status'] && isset( $result['body']['result']['tools'] ), 'Pre-upgrade access token remains accepted.' );
$check( 401 === $http( $state['base'], $rpc, 'synthetic-invalid' )['status'], 'Invalid bearer remains refused.' );
$params = array( 'grant_type' => 'refresh_token', 'refresh_token' => $state['refresh'], 'client_id' => $state['client'], 'resource' => $state['base'] );
$result = $http( $state['base'] . '/oauth/token', $params, '', true );
$check( 200 === $result['status'] && ! empty( $result['body']['access_token'] ) && ! empty( $result['body']['refresh_token'] ), 'Pre-upgrade refresh token remains accepted.' );
$check( 200 === $http( $state['base'], $rpc, $result['body']['access_token'] )['status'], 'Refreshed token can access the same server.' );
$new_access = $result['body']['access_token'];
$new_refresh = $result['body']['refresh_token'];
$changed_config = json_decode( $row['auth_config'], true );
$changed_config['allowed_user_ids'] = array();
$check( 1 === $wpdb->update( $table, array( 'auth_config' => wp_json_encode( $changed_config ) ), array( 'id' => $state['id'], 'slug' => 'qa-180-existing-grant' ) ), 'Synthetic user access was revoked.' );
$check( 401 === $http( $state['base'], $rpc, $new_access )['status'], 'Revoked user cannot use an existing access token.' );
$params['refresh_token'] = $new_refresh;
$check( 400 === $http( $state['base'] . '/oauth/token', $params, '', true )['status'], 'Revoked user cannot refresh an existing grant.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic pre-upgrade OAuth grant' ) ) . PHP_EOL;
