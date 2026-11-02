<?php
/** Local contract for legacy kind and WordPress-user access on core 1.8. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION ) { throw new RuntimeException( 'Local 1.8 fixture required.' ); }
global $wpdb;
$table = $wpdb->prefix . 'getmcp_servers';
$temporary_id = 0;
if ( is_file( '/tmp/getmcp-180-synthetic-grant.json' ) ) {
	$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-grant.json' ), true );
} else {
	$http_state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
	$fixture = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND name='QA 180 automatic remote'", (int) ( $http_state['remote'] ?? 0 ) ), ARRAY_A );
	if ( ! $fixture || $wpdb->get_var( "SELECT id FROM {$table} WHERE slug='qa-180-existing-grant'" ) ) { throw new RuntimeException( 'An isolated synthetic source is required.' ); }
	unset( $fixture['id'] );
	$fixture['uuid'] = wp_generate_uuid4(); $fixture['server_id'] = bin2hex( random_bytes( 8 ) ); $fixture['slug'] = 'qa-180-existing-grant'; $fixture['name'] = 'QA 180 temporary bridge'; $fixture['server_kind'] = 'native'; $fixture['kind'] = 'standard'; $fixture['auth_type'] = 'wordpress';
	if ( 1 !== $wpdb->insert( $table, $fixture ) ) { throw new RuntimeException( 'Could not create synthetic bridge server.' ); }
	$temporary_id = (int) $wpdb->insert_id; $state = array( 'id' => $temporary_id );
}
$id = (int) ( $state['id'] ?? 0 );
$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND slug='qa-180-existing-grant'", $id ), ARRAY_A );
if ( ! $before ) { throw new RuntimeException( 'Synthetic server required.' ); }
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$member_id = 0;
$mock = null;
$transport_key = '';
$oauth_transport_key = '';
$direct_transport_key = '';
$builtin_transport_key = '';
$upstream_calls = 0;
$leaked_inbound = false;
$upstream_auth = array();
$saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$ready = new ReflectionProperty( \GetMCPExtensions\Runtime::class, 'ready' );
$old_ready = $ready->getValue();
$auth_state = array();
$gateway_options = array();
try {
	$auth = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array( 1 ) ) );
	$wpdb->update( $table, array( 'server_kind' => 'gateway', 'auth_type' => 'wordpress', 'auth_config' => $auth ), array( 'id' => $id, 'slug' => 'qa-180-existing-grant' ) );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $id );
	$check( 'gateway' === \GetMCPExtensions\LegacyBridge::kind( $server ), 'Legacy kind survives native hydration.' );
	$check( \GetMCPExtensions\LegacyBridge::can_user_access( 1, $server ), 'Allowed user retains access.' );
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 999999, $server ), 'Unlisted user is refused.' );
	$wpdb->update( $table, array( 'server_kind' => 'remote-mcp', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ) ), array( 'id' => $id ) );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $id );
	$check( 'remote-mcp' === \GetMCPExtensions\LegacyBridge::kind( $server ), 'Live kind change is observed.' );
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $server ), 'Revoked user is refused.' );

	// Exercise only the isolated delegation classes while the public runtime remains guarded.
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-remote-exception.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-upstream-connections.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-safe-http.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-header-schema.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/remote/class-mcp-client.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-project-gateway.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-handler.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/includes/gateway/class-feature-module.php';
	require_once GETMCP_EXTENSIONS_PATH . 'compat/versions/1.8.0/includes/protocol/class-json-rpc-router.php';
	if ( realpath( ( new ReflectionClass( '\\GetMCP\\Protocol\\JsonRpcRouter' ) )->getFileName() ) !== realpath( GETMCP_EXTENSIONS_PATH . 'compat/versions/1.8.0/includes/protocol/class-json-rpc-router.php' ) ) { throw new RuntimeException( 'The isolated router adapter is not loaded.' ); }
	$member = $before;
	unset( $member['id'] );
	$member['uuid'] = wp_generate_uuid4();
	$member['server_id'] = bin2hex( random_bytes( 8 ) );
	$member['slug'] = 'qa-180-bridge-member';
	$member['name'] = 'QA 180 bridge member';
	$member['server_kind'] = 'remote-mcp';
	$member['auth_type'] = 'wordpress';
	$member['auth_config'] = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) );
	$member['settings'] = wp_json_encode( array( 'remote_mcp' => array( 'endpoint' => 'https://example.com/qa-180-legacy-mcp', 'auth_mode' => 'none' ) ) );
	$check( 1 === $wpdb->insert( $table, $member ), 'Synthetic remote member created.' );
	$member_id = (int) $wpdb->insert_id;
	$wpdb->update( $table, array( 'server_kind' => 'gateway', 'auth_config' => $auth, 'settings' => wp_json_encode( array( 'gateway' => array( 'server_ids' => array( $member_id ) ) ) ) ), array( 'id' => $id ) );
	$ready->setValue( true );
	$gateway = ( new \GetMCP\Core\ServerManager() )->get( $id );
	\GetMCP\Gateway\ProjectGateway::authorize( $gateway, 1 );
	$check( count( \GetMCP\Gateway\ProjectGateway::members( $gateway ) ) === 1, 'Gateway retains delegated remote membership.' );
	$remote = ( new \GetMCP\Core\ServerManager() )->get( $member_id );
	$check( \GetMCP\Remote\UpstreamConnections::can_connect( $remote, 1 ), 'Gateway membership permits its user to connect their own remote account.' );
	$check( ! \GetMCP\Remote\UpstreamConnections::can_connect( $remote, 999999 ), 'Unlisted user cannot connect a remote account.' );
	$transport_key = 'getmcp_remote_' . hash( 'sha256', \GetMCP\Remote\UpstreamConnections::identity( $remote, 1 ) . '|' . $id );
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream-credential';
	$mock = static function ( $pre, $args, $url ) use ( &$upstream_calls, &$leaked_inbound, &$upstream_auth ) {
		if ( 'https://example.com/qa-180-legacy-mcp' !== $url ) { return $pre; }
		++$upstream_calls;
		$leaked_inbound = $leaked_inbound || in_array( 'Bearer synthetic-downstream-credential', (array) ( $args['headers'] ?? array() ), true );
		$upstream_auth[] = $args['headers']['Authorization'] ?? '';
		$request = json_decode( $args['body'] ?? '', true );
		switch ( $request['method'] ?? '' ) {
			case 'server/discover': $result = array( 'capabilities' => array( 'tools' => new stdClass(), 'resources' => new stdClass(), 'prompts' => new stdClass() ) ); break;
			case 'tools/list': $result = array( 'tools' => array( array( 'name' => 'synthetic_read', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ), 'annotations' => array( 'readOnlyHint' => true ) ), array( 'name' => 'synthetic_write', 'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ), 'annotations' => array( 'readOnlyHint' => false ) ) ) ); break;
			case 'tools/call': $result = array( 'content' => array( array( 'type' => 'text', 'text' => 'synthetic tool result' ) ) ); break;
			case 'resources/list': $result = array( 'resources' => array( array( 'uri' => 'https://example.org/synthetic-resource', 'name' => 'synthetic resource' ) ) ); break;
			case 'resources/read': $result = array( 'contents' => array( array( 'uri' => 'https://example.org/synthetic-resource', 'text' => 'synthetic resource content' ) ) ); break;
			case 'prompts/list': $result = array( 'prompts' => array( array( 'name' => 'synthetic_prompt' ) ) ); break;
			case 'prompts/get': $result = array( 'messages' => array( array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => 'synthetic prompt' ) ) ) ); break;
			default: throw new RuntimeException( 'Unexpected synthetic upstream method.' );
		}
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'result' => $result ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $mock, 10, 3 );
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null, 1 );
	$listing = $handler->handle( 'tools/list', array() );
	$check( 'qa-180-bridge-member__synthetic_read' === ( $listing['tools'][0]['name'] ?? '' ), 'Gateway lists a namespaced tool from its remote member.' );
	$called = $handler->handle( 'tools/call', array( 'name' => $listing['tools'][0]['name'], 'arguments' => array() ) );
	$check( 'synthetic tool result' === ( $called['content'][0]['text'] ?? '' ), 'Gateway invokes the remote tool.' );
	$resources = $handler->handle( 'resources/list', array() );
	$uri = $resources['resources'][0]['uri'] ?? '';
	$read = $handler->handle( 'resources/read', array( 'uri' => $uri ) );
	$check( str_starts_with( $uri, 'getmcp://' ) && $uri === ( $read['contents'][0]['uri'] ?? '' ), 'Gateway lists and reads a rewritten remote resource.' );
	$prompts = $handler->handle( 'prompts/list', array() );
	$prompt = $handler->handle( 'prompts/get', array( 'name' => $prompts['prompts'][0]['name'] ?? '' ) );
	$check( 'synthetic prompt' === ( $prompt['messages'][0]['content']['text'] ?? '' ), 'Gateway lists and retrieves a remote prompt.' );
	$check( ! $leaked_inbound, 'Downstream authorization is not forwarded upstream.' );
	$refused = false;
	try { ( new \GetMCP\Gateway\FeatureHandler( $remote, null, 1 ) )->handle( 'tools/list', array() ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused, 'Gateway delegation does not grant direct access to the remote server.' );
	$wpdb->update( $table, array( 'auth_config' => $auth ), array( 'id' => $member_id ) );
	$direct_remote = ( new \GetMCP\Core\ServerManager() )->get( $member_id );
	$direct_transport_key = 'getmcp_remote_' . hash( 'sha256', \GetMCP\Remote\UpstreamConnections::identity( $direct_remote, 1 ) . '|0' );
	$direct = ( new \GetMCP\Gateway\FeatureHandler( $direct_remote, null, 1 ) )->handle( 'tools/list', array() );
	$check( 'synthetic_read' === ( $direct['tools'][0]['name'] ?? '' ), 'Directly allowed user can list the remote server without a gateway prefix.' );
	$wpdb->update( $table, array( 'auth_config' => $member['auth_config'] ), array( 'id' => $member_id ) );
	foreach ( array( 'auth_mode', 'current_token_row', 'current_scopes', 'passthrough' ) as $property ) {
		$reflection = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, $property );
		$auth_state[$property] = array( $reflection, $reflection->getValue() );
	}
	$auth_state['auth_mode'][0]->setValue( 'oauth' );
	$auth_state['current_token_row'][0]->setValue( (object) array( 'user_id' => 1 ) );
	$auth_state['current_scopes'][0]->setValue( array( 'mcp:read' ) );
	$native_handler = \GetMCPExtensions\LegacyBridge::native_handler( '\\GetMCP\\Protocol\\ToolsCallHandler', $gateway, null, true );
	$delegated = new ReflectionProperty( $native_handler, 'delegated' );
	$check( true === $delegated->getValue( $native_handler ), 'Native tool execution receives the delegated flag.' );
	$oauth_member = clone $gateway;
	$oauth_member->auth_type = 'oauth';
	$oauth_member->outbound_auth_type = null;
	$oauth_member->outbound_auth_credentials = null;
	$refused = false;
	try { ( new \GetMCP\Execution\AuthInjector() )->inject( array( 'headers' => array() ), false, $oauth_member, $delegated->getValue( $native_handler ) ); } catch ( \RuntimeException $e ) { $refused = true; }
	$check( $refused, 'A gateway token cannot become an unrelated native member’s upstream credential.' );
	$router = new \GetMCP\Protocol\JsonRpcRouter( $gateway );
	$rpc = static fn( string $method, array $params = array() ): string => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) );
	$routed = $router->process( $rpc( 'tools/list' ) );
	$check( 'qa-180-bridge-member__synthetic_read' === ( $routed['result']['tools'][0]['name'] ?? '' ), 'Native 1.8 router dispatches legacy gateway listing.' );
	$before_calls = $upstream_calls;
	$denied = $router->process( $rpc( 'tools/call', array( 'name' => 'qa-180-bridge-member__synthetic_write' ) ) );
	$check( isset( $denied['error'] ) && $before_calls === $upstream_calls, 'Read-only OAuth scope refuses a remote write before upstream.' );
	$auth_state['current_token_row'][0]->setValue( (object) array( 'user_id' => 999999 ) );
	$denied = $router->process( $rpc( 'tools/list' ) );
	$check( isset( $denied['error'] ), 'Router refuses an unlisted gateway user.' );
	$auth_state['current_token_row'][0]->setValue( (object) array( 'user_id' => 1 ) );
	$wpdb->update( $table, array( 'server_kind' => 'native' ), array( 'id' => $id ) );
	$native = ( new \GetMCP\Core\ServerManager() )->get( $id );
	$native_router = new \GetMCP\Protocol\JsonRpcRouter( $native );
	$before_calls = $upstream_calls;
	$native_result = $native_router->process( $rpc( 'tools/list' ) );
	$check( isset( $native_result['result']['tools'] ) && array() === $native_result['result']['tools'] && $before_calls === $upstream_calls, 'Native standard servers retain core routing.' );
	$original_gateway = \GetMCP\Gateway\McpGateway::server();
	$original_router = new \GetMCP\Protocol\JsonRpcRouter( $original_gateway );
	$original_ping = $original_router->process( $rpc( 'ping' ) );
	$check( isset( $original_ping['result'] ) && ! isset( $original_ping['error'] ), 'Native virtual gateway retains core routing.' );
	$wpdb->update( $table, array( 'server_kind' => 'gateway' ), array( 'id' => $id ) );
	foreach ( array( \GetMCP\Gateway\McpGateway::ENABLED_OPTION, \GetMCP\Gateway\McpGateway::AUTH_OPTION, \GetMCP\Gateway\McpGateway::MODE_OPTION, \GetMCP\Gateway\McpGateway::SERVERS_OPTION ) as $option ) { $gateway_options[$option] = get_option( $option, false ); }
	update_option( \GetMCP\Gateway\McpGateway::ENABLED_OPTION, 1 );
	update_option( \GetMCP\Gateway\McpGateway::AUTH_OPTION, 'oauth' );
	update_option( \GetMCP\Gateway\McpGateway::MODE_OPTION, 'selected' );
	update_option( \GetMCP\Gateway\McpGateway::SERVERS_OPTION, array( $member_id ) );
	\GetMCP\Gateway\FeatureModule::register();
	$builtin_settings = json_decode( $member['settings'], true );
	$builtin_settings['remote_mcp']['publish_original'] = false;
	$wpdb->update( $table, array( 'auth_config' => $auth, 'settings' => wp_json_encode( $builtin_settings ) ), array( 'id' => $member_id ) );
	$check( ! in_array( $member_id, array_map( static fn( $item ) => $item->id, \GetMCP\Gateway\McpGateway::included_servers() ), true ), 'Legacy remote is excluded from the original gateway without explicit publication.' );
	$builtin_settings['remote_mcp']['publish_original'] = true;
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $builtin_settings ) ), array( 'id' => $member_id ) );
	$builtin_remote = ( new \GetMCP\Core\ServerManager() )->get( $member_id );
	$builtin_transport_key = 'getmcp_remote_' . hash( 'sha256', \GetMCP\Remote\UpstreamConnections::identity( $builtin_remote, 1 ) . '|' . \GetMCP\Gateway\McpGateway::SERVER_ID );
	$builtin_router = new \GetMCP\Protocol\JsonRpcRouter( \GetMCP\Gateway\McpGateway::server() );
	$builtin_list = $builtin_router->process( $rpc( 'tools/list' ) );
	$check( 'qa-180-bridge-member__synthetic_read' === ( $builtin_list['result']['tools'][0]['name'] ?? '' ), 'Original gateway publishes an explicitly selected legacy remote to an allowed user.' );
	$auth_state['current_token_row'][0]->setValue( (object) array( 'user_id' => 999999 ) );
	$before_calls = $upstream_calls;
	$builtin_denied = $builtin_router->process( $rpc( 'tools/list' ) );
	$check( array() === ( $builtin_denied['result']['tools'] ?? null ) && $before_calls === $upstream_calls, 'Original gateway enforces the member’s WordPress allowlist.' );
	$auth_state['current_token_row'][0]->setValue( (object) array( 'user_id' => 1 ) );
	$wpdb->update( $table, array( 'auth_config' => $member['auth_config'], 'settings' => $member['settings'] ), array( 'id' => $member_id ) );
	$auth_state['passthrough'][0]->setValue( true );
	$denied = $router->process( $rpc( 'tools/call', array( 'name' => 'qa-180-bridge-member__synthetic_read' ) ) );
	$check( isset( $denied['error'] ), 'Unverified passthrough credentials cannot use a legacy gateway.' );
	$auth_state['passthrough'][0]->setValue( false );
	$auth_state['auth_mode'][0]->setValue( null );
	$auth_state['current_scopes'][0]->setValue( null );
	$oauth_settings = array( 'remote_mcp' => array( 'endpoint' => 'https://example.com/qa-180-legacy-mcp', 'auth_mode' => 'oauth', 'client_id' => 'synthetic-client' ) );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $oauth_settings ) ), array( 'id' => $member_id ) );
	$remote = ( new \GetMCP\Core\ServerManager() )->get( $member_id );
	$grant = array( 'access_token' => 'synthetic-personal-token', 'refresh_token' => 'synthetic-personal-refresh', 'expires_at' => time() + 3600, 'client_id' => 'synthetic-user-client', 'config_hash' => \GetMCP\Remote\UpstreamConnections::config_hash( $remote ) );
	\GetMCP\Remote\UpstreamConnections::save( $member_id, 1, $grant );
	$oauth_transport_key = 'getmcp_remote_' . hash( 'sha256', \GetMCP\Remote\UpstreamConnections::identity( $remote, 1 ) . '|' . $id );
	$called = $handler->handle( 'tools/call', array( 'name' => $listing['tools'][0]['name'] ) );
	$check( 'synthetic tool result' === ( $called['content'][0]['text'] ?? '' ) && 'Bearer synthetic-personal-token' === end( $upstream_auth ), 'Gateway uses the authorized user’s preserved personal grant.' );
	$oauth_settings['remote_mcp']['client_id'] = 'changed-client';
	$wpdb->update( $table, array( 'settings' => wp_json_encode( $oauth_settings ) ), array( 'id' => $member_id ) );
	$before_calls = $upstream_calls;
	$refused = false;
	try { $handler->handle( 'tools/call', array( 'name' => $listing['tools'][0]['name'] ) ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused && $before_calls === $upstream_calls, 'Changed remote configuration refuses the old grant before calling upstream.' );
	$refused = false;
	try { \GetMCP\Gateway\ProjectGateway::authorize( $gateway, 999999 ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused, 'Unlisted gateway user is refused.' );
	$wpdb->update( $table, array( 'settings' => wp_json_encode( array( 'gateway' => array( 'server_ids' => array() ) ) ) ), array( 'id' => $id ) );
	$check( array() === \GetMCP\Gateway\ProjectGateway::members( $gateway ), 'Removed membership takes effect immediately.' );
	$check( ! \GetMCP\Remote\UpstreamConnections::can_connect( $remote, 1 ), 'Removed membership revokes delegated connection access.' );
	$before_calls = $upstream_calls;
	$refused = false;
	try { $handler->handle( 'tools/call', array( 'name' => $listing['tools'][0]['name'] ) ); } catch ( \GetMCP\Remote\RemoteException $e ) { $refused = true; }
	$check( $refused && $before_calls === $upstream_calls, 'Removed member is refused before any upstream call.' );
	$server->id = 999999999;
	$check( 'unknown' === \GetMCPExtensions\LegacyBridge::kind( $server ), 'Missing server kind fails closed.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic legacy-kind bridge' ) ) . PHP_EOL;
} finally {
	$ready->setValue( $old_ready );
	foreach ( $auth_state as $property ) { $property[0]->setValue( $property[1] ); }
	if ( $mock ) { remove_filter( 'pre_http_request', $mock, 10 ); }
	foreach ( array( $transport_key, $oauth_transport_key, $direct_transport_key, $builtin_transport_key ) as $key ) { if ( $key ) { foreach ( array( '', '_tools_list', '_resources_list', '_prompts_list' ) as $suffix ) { delete_transient( $key . $suffix ); } } }
	foreach ( $gateway_options as $option => $value ) { if ( false === $value ) { delete_option( $option ); } else { update_option( $option, $value ); } }
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	if ( $member_id ) { $wpdb->delete( \GetMCP\Remote\UpstreamConnections::table(), array( 'server_id' => $member_id, 'user_id' => 1 ) ); $wpdb->delete( $table, array( 'id' => $member_id, 'slug' => 'qa-180-bridge-member' ) ); }
	$wpdb->update( $table, array( 'server_kind' => $before['server_kind'], 'auth_type' => $before['auth_type'], 'auth_config' => $before['auth_config'], 'settings' => $before['settings'], 'updated_at' => $before['updated_at'] ), array( 'id' => $id, 'slug' => 'qa-180-existing-grant' ) );
	if ( $temporary_id ) { $wpdb->delete( $table, array( 'id' => $temporary_id, 'slug' => 'qa-180-existing-grant' ) ); }
}
