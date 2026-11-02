<?php
/** Exercise modified gateway and remote behavior on the supported 1.7 core. */
if ( 'local' !== wp_get_environment_type() || '1.7.0' !== GETMCP_VERSION || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Local reviewed 1.7 fixture required.' ); }
wp_set_current_user( 1 );
ob_start();
require GETMCP_EXTENSIONS_PATH . 'tests/upgrade-180-auto-seed.php';
$seed = json_decode( (string) ob_get_clean(), true );
if ( empty( $seed['remote'] ) || empty( $seed['gateway'] ) ) { throw new RuntimeException( 'Synthetic gateway fixtures were not created.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$remote_id = (int) $seed['remote'];
$gateway_id = (int) $seed['gateway'];
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$mock = static function ( $pre, $args, $url ) {
	if ( 'https://example.org/mcp' !== $url ) { return $pre; }
	if ( 'Bearer synthetic-access' !== ( $args['headers']['Authorization'] ?? '' ) ) { throw new RuntimeException( 'Unexpected synthetic upstream credential.' ); }
	$request = json_decode( $args['body'] ?? '', true );
	$result = 'server/discover' === ( $request['method'] ?? '' ) ? array( 'capabilities' => array( 'tools' => new stdClass() ) ) : array( 'tools' => array( array( 'name' => 'synthetic_read', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ) ) ) );
	return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'result' => $result ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$key = '';
try {
	$manager = new \GetMCP\Core\ServerManager();
	$remote = $manager->get( $remote_id );
	$gateway = $manager->get( $gateway_id );
	$check( 'gateway' === \GetMCPExtensions\LegacyBridge::kind( $gateway ) && 'remote-mcp' === \GetMCPExtensions\LegacyBridge::kind( $remote ), 'Legacy server kinds remain available.' );
	$auth = json_decode( $remote->auth_config, true );
	$auth['allowed_user_ids'] = array();
	$wpdb->update( $table, array( 'auth_config' => wp_json_encode( $auth ) ), array( 'id' => $remote_id, 'name' => 'QA 180 automatic remote' ) );
	$remote = $manager->get( $remote_id );
	$check( \GetMCP\Remote\UpstreamConnections::can_connect( $remote, 1 ), 'Gateway delegation still permits the personal upstream account.' );
	$refused = false;
	try { ( new \GetMCP\Gateway\FeatureHandler( $remote, null, 1 ) )->handle( 'tools/list', array() ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused, 'Delegation does not grant direct remote access.' );
	$key = 'getmcp_remote_' . hash( 'sha256', \GetMCP\Remote\UpstreamConnections::identity( $remote, 1 ) . '|' . $gateway_id );
	$list = ( new \GetMCP\Gateway\FeatureHandler( $gateway, null, 1 ) )->handle( 'tools/list', array() );
	$check( $remote->slug . '__synthetic_read' === ( $list['tools'][0]['name'] ?? '' ), 'Gateway lists the synthetic remote using the preserved personal grant.' );
	$changed = \GetMCP\Gateway\FeatureManager::run( 'save', array( 'id' => $remote_id, 'name' => 'QA 180 automatic remote edited' ), 1 );
	$after = $manager->get( $remote_id );
	$check( $changed['id'] === $remote_id && 'oauth' === $after->auth_type && 'remote-mcp' === $after->server_kind, 'Management preserves 1.7 sign-in and kind.' );
	$check( ! empty( \GetMCP\Remote\UpstreamConnections::get( $remote_id, 1 )['data']['refresh_token'] ), 'Personal grant survives a management edit.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.7 regression' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	if ( $key ) { delete_transient( $key ); delete_transient( $key . '_tools_list' ); }
	$manager = new \GetMCP\Core\ServerManager();
	$gateway = $manager->get( $gateway_id );
	if ( $gateway && 'QA 180 automatic gateway' === $gateway->name ) { $manager->delete( $gateway_id ); }
	$remote = $manager->get( $remote_id );
	if ( $remote && in_array( $remote->name, array( 'QA 180 automatic remote', 'QA 180 automatic remote edited' ), true ) ) { $manager->delete( $remote_id ); }
}
