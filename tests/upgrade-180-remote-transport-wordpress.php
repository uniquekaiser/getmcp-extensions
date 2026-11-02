<?php
/** Local-only contract test for the legacy remote transport on GetMCP 1.8. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION ) {
	throw new RuntimeException( 'A local GetMCP 1.8 fixture is required.' );
}
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-remote-exception.php';
require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-safe-http.php';

$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) { throw new RuntimeException( $message ); }
};
$refused = static function ( callable $call ): bool {
	try { $call(); } catch ( \GetMCP\Remote\RemoteException $e ) { return true; }
	return false;
};
$check( $refused( static fn() => \GetMCP\Remote\SafeHttp::request( 'https://127.0.0.1/' ) ), 'Private addresses must be refused.' );
$check( $refused( static fn() => \GetMCP\Remote\SafeHttp::request( 'http://1.1.1.1/' ) ), 'Plain HTTP must be refused.' );
$check( $refused( static fn() => \GetMCP\Remote\SafeHttp::request( 'https://1.1.1.1/', 'GET', array( 'Host' => 'example.com' ) ) ), 'Reserved headers must be refused.' );

$intercepted = 0;
$pinned = false;
$mock = static function ( $pre, $args, $url ) use ( &$intercepted, &$pinned ) {
	if ( 'https://example.com/qa-extensions-transport' !== $url ) { return $pre; }
	++$intercepted;
	$pinned = false !== has_action( 'http_api_curl' );
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	$result = \GetMCP\Remote\SafeHttp::request( 'https://example.com/qa-extensions-transport' );
	$check( 1 === $intercepted && 200 === $result['status'], 'Public synthetic request reaches HTTP interception.' );
	$check( $pinned, 'Hostname requests must use the reviewed native DNS pinning transport.' );
	$check( '{"ok":true}' === $result['body'], 'Response body is preserved.' );
	$check( 'application/json' === $result['headers']['content-type'], 'Response headers are preserved.' );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
}
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic HTTP interception' ) ) . PHP_EOL;
