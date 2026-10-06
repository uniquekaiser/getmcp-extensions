<?php
/** Owned HTTP upstream fixture. No real credentials or accounts. */
$path = $_GET['path'] ?? '';
$respond = function( $data, $status = 200 ) { http_response_code( $status ); header( 'Content-Type: application/json' ); echo json_encode( $data ); exit; };
if ( str_contains( $path, 'oauth-protected-resource' ) ) { $respond( array( 'resource' => 'https://example.org/getmcp-qa/oauth', 'authorization_servers' => array( 'https://example.org/getmcp-qa/issuer' ), 'scopes_supported' => array( 'read', 'write' ) ) ); }
if ( str_contains( $path, 'oauth-authorization-server' ) || str_contains( $path, 'openid-configuration' ) ) { $respond( array( 'issuer' => 'https://example.org/getmcp-qa/issuer', 'authorization_endpoint' => 'https://example.org/getmcp-qa/authorize', 'token_endpoint' => 'https://example.org/getmcp-qa/token', 'code_challenge_methods_supported' => array( 'S256' ), 'authorization_response_iss_parameter_supported' => true ) ); }
if ( str_ends_with( $path, '/token' ) ) {
	parse_str( file_get_contents( 'php://input' ), $form );
	$identity = $form['code'] ?? str_replace( 'refresh-', '', $form['refresh_token'] ?? '' );
	if ( ! in_array( $identity, array( 'account-a', 'account-b' ), true ) || empty( $form['resource'] ) ) { $respond( array( 'error' => 'invalid_grant' ), 400 ); }
	$respond( array( 'access_token' => 'access-' . $identity, 'refresh_token' => 'refresh-' . $identity, 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
}
$body = json_decode( file_get_contents( 'php://input' ), true );
$method = $body['method'] ?? ''; $id = $body['id'] ?? null;
$reply = function( $result ) use ( $body, $path, $id ) {
	$message = array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	if ( str_contains( $path, '/sse' ) ) { header( 'Content-Type: text/event-stream' ); echo ': keepalive' . "\r\n\r\n" . 'data: ' . json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => array( 'progress' => 1 ) ) ) . "\r\n\r\n" . 'data: ' . json_encode( $message ) . "\r\n\r\n"; exit; }
	header( 'Content-Type: application/json' ); echo json_encode( $message ); exit;
};
if ( str_contains( $path, '/outage' ) ) { $respond( array(), 503 ); }
if ( str_contains( $path, '/malformed' ) ) { header( 'Content-Type: application/json' ); echo '{broken'; exit; }
if ( 'server/discover' === $method ) {
	if ( str_contains( $path, '/legacy' ) ) { $respond( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => -32601, 'message' => 'No modern handshake' ) ), 404 ); }
	if ( ( $_SERVER['HTTP_MCP_METHOD'] ?? '' ) !== $method ) { $respond( array(), 400 ); }
	$reply( array( 'capabilities' => array( 'tools' => new stdClass(), 'resources' => new stdClass(), 'prompts' => new stdClass() ) ) );
}
if ( 'initialize' === $method ) { header( 'Mcp-Session-Id: fixture-session' ); $reply( array( 'protocolVersion' => '2025-06-18', 'capabilities' => array( 'tools' => new stdClass() ) ) ); }
if ( 'notifications/initialized' === $method ) { http_response_code( 202 ); exit; }
if ( str_contains( $path, '/legacy' ) && ( $_SERVER['HTTP_MCP_SESSION_ID'] ?? '' ) !== 'fixture-session' ) { $respond( array(), 400 ); }
if ( str_contains( $path, '/oauth' ) && ! in_array( $_SERVER['HTTP_AUTHORIZATION'] ?? '', array( 'Bearer access-account-a', 'Bearer access-account-b' ), true ) ) { $respond( array(), 401 ); }
if ( 'tools/list' === $method ) {
	$tool = array( 'name' => 'echo', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ), 'annotations' => array( 'readOnlyHint' => true ) );
	$tools = array( $tool );
	if ( str_contains( $path, '/pages' ) ) { $tools = array(); $start = ( $body['params']['cursor'] ?? '' ) === 'page2' ? 101 : 0; for ( $n = $start; $n < min( $start + 101, 202 ); ++$n ) { $t = $tool; $t['name'] = 'echo_' . $n; $tools[] = $t; } $reply( array_merge( array( 'tools' => $tools ), $start ? array() : array( 'nextCursor' => 'page2' ) ) ); }
	if ( str_contains( $path, '/duplicate' ) ) { $tools[] = $tool; }
	$reply( array( 'tools' => $tools ) );
}
if ( 'resources/list' === $method ) { $reply( array( 'resources' => array( array( 'uri' => 'fixture://same/resource', 'name' => 'Resource', 'mimeType' => 'text/plain' ) ) ) ); }
if ( 'resources/templates/list' === $method ) { $reply( array( 'resourceTemplates' => array( array( 'uriTemplate' => 'fixture://item/{id}{?filter}', 'name' => 'Item' ) ) ) ); }
if ( 'prompts/list' === $method ) { $reply( array( 'prompts' => array( array( 'name' => 'greet', 'arguments' => array( array( 'name' => 'person', 'required' => true ) ) ) ) ) ); }
if ( 'tools/call' === $method ) { $reply( array( 'content' => array( array( 'type' => 'text', 'text' => 'fixture response' ), array( 'type' => 'resource_link', 'uri' => 'fixture://same/resource', 'name' => 'Resource' ) ), 'structuredContent' => array( 'identity' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'anonymous', 'empty' => new stdClass(), 'uri' => 'fixture://opaque' ), 'isError' => ! empty( $body['params']['arguments']['fail'] ) ) ); }
if ( 'resources/read' === $method ) { $reply( array( 'contents' => array( array( 'uri' => $body['params']['uri'], 'text' => 'owned fixture', 'mimeType' => 'text/plain' ) ) ) ); }
if ( 'prompts/get' === $method ) { $reply( array( 'messages' => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => 'Hello ' . ( $body['params']['arguments']['person'] ?? '' ) ) ) ) ) ); }
$respond( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => -32601, 'message' => 'Not implemented' ) ) );
