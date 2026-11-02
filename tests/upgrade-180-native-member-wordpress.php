<?php
/** Ensure delegated native member listings retain 1.8's availability filters. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
$state = json_decode( (string) file_get_contents( '/tmp/getmcp-180-synthetic-http.json' ), true );
global $wpdb;
$servers = $wpdb->prefix . 'getmcp_servers'; $tools = $wpdb->prefix . 'getmcp_tools';
$gateway_id = (int) ( $state['gateway'] ?? 0 );
$gateway_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$servers} WHERE id=%d AND name='QA 180 automatic gateway'", $gateway_id ), ARRAY_A );
if ( ! $gateway_row || 'wordpress' !== $gateway_row['auth_type'] ) { throw new RuntimeException( 'Converted synthetic gateway required.' ); }
$checks = 0; $member_id = 0; $tool_rows = array(); $filter = null; $http_mock = null; $resource_id = 0; $prompt_id = 0;
$saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	$member = $gateway_row; unset( $member['id'] );
	$member['uuid'] = wp_generate_uuid4(); $member['server_id'] = bin2hex( random_bytes( 8 ) ); $member['slug'] = 'qa-180-native-member'; $member['name'] = 'QA 180 native member'; $member['server_kind'] = 'native'; $member['kind'] = 'standard'; $member['settings'] = '{}';
	$member['auth_config'] = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) );
	$check( 1 === $wpdb->insert( $servers, $member ), 'Synthetic native member created.' ); $member_id = (int) $wpdb->insert_id;
	foreach ( array( 'missing_ability' => 'ability', 'available_http' => 'http' ) as $name => $type ) {
		$uuid = wp_generate_uuid4();
		$check( 1 === $wpdb->insert( $tools, array( 'uuid' => $uuid, 'server_id' => $member_id, 'name' => $name, 'slug' => $name, 'description' => '', 'endpoint_url' => 'https://example.org/api', 'tool_type' => $type, 'handler' => 'qa_missing_ability', 'status' => 'active' ) ), 'Synthetic native tool created.' );
		$tool_rows[] = array( 'id' => (int) $wpdb->insert_id, 'uuid' => $uuid );
	}
	$auth = json_decode( $gateway_row['auth_config'], true ); $auth['allowed_user_ids'] = array( 1 );
	$settings = json_decode( $gateway_row['settings'], true ); $settings['gateway']['server_ids'] = array( $member_id );
	$wpdb->update( $servers, array( 'auth_config' => wp_json_encode( $auth ), 'settings' => wp_json_encode( $settings ) ), array( 'id' => $gateway_id ) );
	$gateway = ( new \GetMCP\Core\ServerManager() )->get( $gateway_id );
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null, 1 );
	$filter = static fn( $items, $server ) => $server->id === $member_id ? array() : $items;
	add_filter( 'getmcp_tools_list', $filter, 10, 2 );
	$list = $handler->handle( 'tools/list', array() );
	$check( array() === $list['tools'], 'Native tool-list filters apply through a legacy project gateway.' );
	remove_filter( 'getmcp_tools_list', $filter, 10 ); $filter = null;
	$list = $handler->handle( 'tools/list', array() );
	$check( count( $list['tools'] ) === 1 && 'qa-180-native-member__available_http' === $list['tools'][0]['name'], 'Unavailable native abilities are not advertised.' );
	$http_calls = 0; $leaked = false;
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream-credential';
	$http_mock = static function ( $pre, $args, $url ) use ( &$http_calls, &$leaked ) {
		if ( 'https://example.org/api' !== $url ) { return $pre; }
		++$http_calls;
		$leaked = $leaked || in_array( 'Bearer synthetic-downstream-credential', (array) ( $args['headers'] ?? array() ), true );
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $http_mock, 10, 3 );
	$called = $handler->handle( 'tools/call', array( 'name' => $list['tools'][0]['name'], 'arguments' => array() ) );
	$check( 1 === $http_calls && ! $leaked && empty( $called['isError'] ), 'Delegated native tool executes without forwarding the gateway credential.' );
	$check( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_resources', array( 'server_id' => $member_id, 'uri' => 'qa://native-resource', 'name' => 'Native resource', 'data_source_type' => 'static', 'data_source_config' => wp_json_encode( array( 'content' => 'synthetic native resource' ) ) ) ), 'Synthetic native resource created.' );
	$resource_id = (int) $wpdb->insert_id;
	$check( 1 === $wpdb->insert( $wpdb->prefix . 'getmcp_prompts', array( 'server_id' => $member_id, 'name' => 'native_prompt', 'template_content' => 'synthetic native prompt' ) ), 'Synthetic native prompt created.' );
	$prompt_id = (int) $wpdb->insert_id;
	$resources = $handler->handle( 'resources/list', array() );
	$uri = $resources['resources'][0]['uri'] ?? '';
	$read = $handler->handle( 'resources/read', array( 'uri' => $uri ) );
	$check( 'synthetic native resource' === ( $read['contents'][0]['text'] ?? '' ), 'Gateway lists and reads a native member resource.' );
	$prompts = $handler->handle( 'prompts/list', array() );
	$prompt = $handler->handle( 'prompts/get', array( 'name' => $prompts['prompts'][0]['name'] ?? '' ) );
	$check( 'synthetic native prompt' === ( $prompt['messages'][0]['content']['text'] ?? '' ), 'Gateway lists and retrieves a native member prompt.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic delegated native member' ) ) . PHP_EOL;
} finally {
	if ( $filter ) { remove_filter( 'getmcp_tools_list', $filter, 10 ); }
	if ( $http_mock ) { remove_filter( 'pre_http_request', $http_mock, 10 ); }
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	foreach ( $tool_rows as $row ) { $wpdb->delete( $tools, $row ); }
	if ( $resource_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_resources', array( 'id' => $resource_id, 'server_id' => $member_id ) ); }
	if ( $prompt_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_prompts', array( 'id' => $prompt_id, 'server_id' => $member_id ) ); }
	if ( $member_id ) { $wpdb->delete( $servers, array( 'id' => $member_id, 'slug' => 'qa-180-native-member' ) ); }
	$wpdb->update( $servers, array( 'auth_config' => $gateway_row['auth_config'], 'settings' => $gateway_row['settings'], 'updated_at' => $gateway_row['updated_at'] ), array( 'id' => $gateway_id ) );
}
