<?php
/** Disposable, real-core regression for legacy gateway and remote readiness. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) { throw new RuntimeException( 'Reviewed local GetMCP 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager();
$tools = new \GetMCP\Core\ToolManager();
$controller = new \GetMCP\Api\ServersController();
$ids = array(); $tool_id = 0; $checks = 0; $requests = 0;
$previous_user = get_current_user_id(); wp_set_current_user( 1 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$block_http = static function() use ( &$requests ) { ++$requests; return new WP_Error( 'synthetic_network_blocked', 'Readiness must not perform network requests.' ); };
add_filter( 'pre_http_request', $block_http, -100 );
$new_server = static function( string $label ) use ( $manager, &$ids ) {
	$slug = 'qa-180-ready-' . strtolower( $label ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
	$server = $manager->create( array( 'name' => 'QA 180 readiness ' . $label, 'slug' => $slug, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) ), 'settings' => '{}' ) );
	if ( ! $server ) { throw new RuntimeException( 'Could not create a synthetic server.' ); }
	$ids[] = array( 'id' => $server->id, 'slug' => $slug ); return $server;
};
$readiness = static function( $server ) use ( $controller ): array {
	$request = new WP_REST_Request( 'GET' ); $request->set_param( 'id', $server->uuid );
	$response = $controller->get_readiness( $request );
	if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
	return $response->get_data();
};
try {
	$member = $new_server( 'member' );
	$tool = $tools->create( array( 'server_id' => $member->id, 'name' => 'synthetic_read', 'endpoint_url' => 'https://example.org/synthetic', 'status' => 'active' ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create a synthetic tool.' ); } $tool_id = (int) $tool->id;
	$gateway = $new_server( 'gateway' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'server_kind' => 'gateway', 'settings' => wp_json_encode( array( 'gateway' => array( 'server_ids' => array( (int) $member->id ) ) ) ) ), array( 'id' => $gateway->id ) );
	$gateway = $manager->get( $gateway->id );
	$check( 'gateway' === \GetMCPExtensions\LegacyBridge::kind( $gateway ) && 1 === count( \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), 'Synthetic legacy gateway must have one active member.' );
	$result = $readiness( $gateway ); $cap = array_column( $result['checks'], null, 'id' )['capabilities'] ?? array();
	$check( 'pass' === ( $cap['status'] ?? null ) && 'not_ready' !== $result['status'], 'An active legacy gateway must recognize its member capabilities.' );
	$authorized = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/servers/' . $gateway->uuid . '/readiness' ) );
	$check( 200 === $authorized->get_status() && 'not_ready' !== ( $authorized->get_data()['status'] ?? 'not_ready' ), 'An authorized administrator sees the corrected readiness through REST.' );
	$list = ( new \GetMCP\Gateway\FeatureHandler( $gateway, null, 1 ) )->handle( 'tools/list', array() );
	$check( 1 === count( $list['tools'] ?? array() ) && str_ends_with( $list['tools'][0]['name'] ?? '', '__synthetic_read' ), 'The same member tool remains available through the gateway.' );
	$denied_gateway = false;
	try { ( new \GetMCP\Gateway\FeatureHandler( $gateway, null, 999999 ) )->handle( 'tools/list', array() ); } catch ( \GetMCP\Remote\RemoteException $e ) { $denied_gateway = true; }
	$check( $denied_gateway, 'The readiness change does not grant gateway access to another user.' );
	wp_set_current_user( 0 );
	$denied = rest_do_request( new WP_REST_Request( 'GET', '/getmcp/v1/servers/' . $gateway->uuid . '/readiness' ) );
	$check( in_array( $denied->get_status(), array( 401, 403 ), true ), 'Anonymous users cannot read administrative readiness.' );
	wp_set_current_user( 1 );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'status' => 'paused' ), array( 'id' => $member->id ) );
	$result = $readiness( $gateway ); $cap = array_column( $result['checks'], null, 'id' )['capabilities'] ?? array();
	$check( 'fail' === ( $cap['status'] ?? null ) && 'not_ready' === $result['status'], 'A gateway with no active members must remain not ready.' );
	$empty = $new_server( 'empty' ); $result = $readiness( $empty );
	$check( 'not_ready' === $result['status'], 'An empty ordinary server must remain not ready.' );
	$bundle = $new_server( 'bundle' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'kind' => 'bundle' ), array( 'id' => $bundle->id ) );
	$result = $readiness( $manager->get( $bundle->id ) );
	$check( 'not_ready' === $result['status'], 'An empty native bundle retains its native readiness behavior.' );
	$remote = $new_server( 'remote' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'server_kind' => 'remote-mcp', 'settings' => wp_json_encode( array( 'remote_mcp' => array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => 'none' ) ) ) ), array( 'id' => $remote->id ) );
	$result = $readiness( $manager->get( $remote->id ) ); $cap = array_column( $result['checks'], null, 'id' )['capabilities'] ?? array();
	$check( 'pass' === ( $cap['status'] ?? null ), 'A configured legacy remote server must recognize its upstream.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'settings' => wp_json_encode( array( 'remote_mcp' => array( 'endpoint' => '', 'auth_mode' => 'none' ) ) ) ), array( 'id' => $remote->id ) );
	$result = $readiness( $manager->get( $remote->id ) ); $cap = array_column( $result['checks'], null, 'id' )['capabilities'] ?? array();
	$check( 'fail' === ( $cap['status'] ?? null ), 'A legacy remote without a valid endpoint must remain not ready.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'status' => 'paused' ), array( 'id' => $gateway->id ) );
	$result = $readiness( $manager->get( $gateway->id ) );
	$check( 'not_ready' === $result['status'], 'A paused gateway must remain not ready.' );
	$check( 0 === $requests, 'Readiness must not contact upstream servers.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic legacy readiness on reviewed GetMCP 1.8' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $block_http, -100 );
	if ( $tool_id ) { $tools->delete( $tool_id ); }
	foreach ( array_reverse( $ids ) as $item ) { $server = $manager->get( $item['id'] ); if ( $server && $item['slug'] === $server->slug ) { $manager->delete( $item['id'] ); } }
	wp_set_current_user( $previous_user );
}
