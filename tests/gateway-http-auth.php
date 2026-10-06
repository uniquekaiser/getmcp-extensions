<?php
/** Real local OAuth token exchange and gateway endpoint acceptance. Consent is tested separately in browser. */
global $wpdb;
wp_set_current_user( 1 );
$checks = array(); $assert = function( $ok, $name ) use ( &$checks ) { $checks[ $name ] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $name ); } };
$fm = '\\GetMCP\\Gateway\\FeatureManager'; $sm = new \GetMCP\Core\ServerManager();
$a = $fm::run( 'save', array( 'kind' => 'gateway', 'name' => 'HTTP QA A ' . time(), 'allowed_user_ids' => array( 1 ), 'server_ids' => array() ) );
$b = $fm::run( 'save', array( 'kind' => 'gateway', 'name' => 'HTTP QA B ' . time(), 'allowed_user_ids' => array( 2 ), 'server_ids' => array() ) );
$http = function( $url, $body, $token = null, $form = false, $method = null ) {
	$headers = array( 'Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'Accept' => 'application/json, text/event-stream' );
	if ( $token ) { $headers['Authorization'] = 'Bearer ' . $token; }
	if ( $method ) { $headers['MCP-Protocol-Version'] = '2026-07-28'; $headers['Mcp-Method'] = $method; $body['params']['_meta'] = array( 'io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientInfo' => array( 'name' => 'QA', 'version' => '1' ), 'io.modelcontextprotocol/clientCapabilities' => new stdClass() ); }
	$headers['Host'] = wp_parse_url(home_url(), PHP_URL_HOST) . (wp_parse_url(home_url(), PHP_URL_PORT) ? ':' . wp_parse_url(home_url(), PHP_URL_PORT) : '');
	$r = wp_remote_post( str_replace( home_url(), 'http://127.0.0.1', $url ), array( 'headers' => $headers, 'body' => $form ? http_build_query( $body ) : wp_json_encode( $body ), 'redirection' => 0, 'timeout' => 10 ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( $r->get_error_message() ); }
	return array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$base = $a['url']; $redirect = 'https://example.org/fixture-callback';
$r = $http( $base . '/oauth/register', array( 'client_name' => 'Local QA Gateway', 'redirect_uris' => array( $redirect ), 'grant_types' => array( 'authorization_code', 'refresh_token' ), 'token_endpoint_auth_method' => 'none' ) );
if ($r['status'] !== 201) { echo wp_json_encode($r); }
$assert( $r['status'] === 201 && ! empty( $r['body']['client_id'] ), 'real_http_client_registration' );
$client = $r['body']['client_id']; $row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}getmcp_oauth_clients WHERE client_id=%s", $client ) );
$verifier = bin2hex( random_bytes( 32 ) );
$request = array( 'scope' => 'mcp:read mcp:write', 'redirect_uri' => $redirect, 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
$issue = new ReflectionMethod( \GetMCP\Auth\FirstPartyOAuth::class, 'issue_code' );
$code = $issue->invoke( null, $row, 1, $request, $base );
$params = array( 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => $redirect, 'resource' => $base );
$bad = $params; $bad['resource'] = $b['url'];
$assert( $http( $base . '/oauth/token', $bad, null, true )['status'] === 400, 'exchange_wrong_gateway_resource_denied' );
$r = $http( $base . '/oauth/token', $params, null, true ); $token = $r['body']['access_token'] ?? ''; $refresh = $r['body']['refresh_token'] ?? '';
$assert( $r['status'] === 200 && $token && $refresh, 'real_http_native_code_exchange' );
$rpc = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() );
$r = $http( $base, $rpc, $token, false, 'tools/list' );
$assert( $r['status'] === 200 && isset( $r['body']['result']['tools'] ), 'real_http_gateway_token_accepted' );
$assert( $http( $b['url'], $rpc, $token, false, 'tools/list' )['status'] === 401, 'cross_gateway_token_denied' );
$refresh_params = array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client, 'resource' => $base );
$r = $http( $base . '/oauth/token', $refresh_params, null, true );
$assert( $r['status'] === 200 && ! empty( $r['body']['access_token'] ), 'real_http_gateway_refresh' );
$token = $r['body']['access_token']; $refresh_params['refresh_token'] = $r['body']['refresh_token'];
$fm::run( 'save', array( 'id' => $a['id'], 'allowed_user_ids' => array() ) );
$assert( $http( $base, $rpc, $token, false, 'tools/list' )['status'] === 401, 'allowlist_removal_invalidates_protected_http_request' );
$assert( $http( $base . '/oauth/token', $refresh_params, null, true )['status'] === 400, 'allowlist_removal_denies_refresh' );
$fm::run( 'save', array( 'id' => $a['id'], 'allowed_user_ids' => array( 1 ), 'status' => 'paused' ) );
$assert( $http( $base, $rpc, $token, false, 'tools/list' )['status'] === 503, 'paused_gateway_protected_http_denied' );
$r = wp_remote_get( str_replace( home_url(), 'http://127.0.0.1', rest_url( 'getmcp/v1/connections' ) ), array( 'headers' => array( 'Host' => wp_parse_url(home_url(), PHP_URL_HOST) . (wp_parse_url(home_url(), PHP_URL_PORT) ? ':' . wp_parse_url(home_url(), PHP_URL_PORT) : '') ), 'timeout' => 10 ) );
$assert( wp_remote_retrieve_response_code( $r ) === 401, 'anonymous_management_rest_denied' );
echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks, 'browser_fixture' => array( 'gateway_url' => $b['url'], 'client_id' => $client ), 'consent' => 'Authorization code seeded for HTTP boundary tests; real consent browser flow separate.' ), JSON_PRETTY_PRINT );
