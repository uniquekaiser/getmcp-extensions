<?php
/** Preserve only pre-upgrade, explicitly selected gateway delegation. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $tools = new \GetMCP\Core\ToolManager(); $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$server = null; $other = null; $tool = null; $scope = array(); $checks = 0; $calls = 0;
$names = array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers', 'getmcp_gateway_namespace_tools', 'getmcp_extensions_180_gateway_snapshot', \GetMCPExtensions\Upgrade180Auto::LEDGER, 'getmcp_db_version' );
$prior = array(); foreach ( $names as $name ) { $prior[$name] = get_option( $name, null ); }
if ( null !== $prior['getmcp_extensions_180_gateway_snapshot'] ) {
	$existing = json_decode( (string) $prior['getmcp_extensions_180_gateway_snapshot'], true );
	if ( ! is_array( $existing['members'] ?? null ) ) { throw new RuntimeException( 'Unexpected existing gateway snapshot.' ); }
	foreach ( array_keys( $existing['members'] ) as $id ) { $existing_server = $manager->get( (int) $id ); if ( ! $existing_server || ! str_starts_with( $existing_server->name, 'QA 180 ' ) ) { throw new RuntimeException( 'Only synthetic gateway snapshots may be temporarily replaced.' ); } }
	delete_option( 'getmcp_extensions_180_gateway_snapshot' );
}
$previous_user = get_current_user_id(); $token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue(); $saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$http = static function( $pre, $args, $url ) use ( &$calls ) {
	if ( 'https://example.org/qa-180-builtin-gateway' !== $url ) { return $pre; }
	if ( ! empty( $args['headers']['Authorization'] ) ) { throw new RuntimeException( 'The downstream credential must not be forwarded.' ); }
	++$calls; return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{"synthetic":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}; add_filter( 'pre_http_request', $http, 1, 3 );
try {
	wp_set_current_user( 1 ); $auth = wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) );
	$server = $manager->create( array( 'name' => 'QA 180 legacy builtin member', 'slug' => 'qa-180-builtin-member-' . $suffix, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => $auth, 'settings' => '{}' ) );
	$other = $manager->create( array( 'name' => 'QA 180 native builtin member', 'slug' => 'qa-180-builtin-native-' . $suffix, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => $auth, 'settings' => '{}' ) );
	if ( ! $server || ! $other ) { throw new RuntimeException( 'Could not create synthetic native members.' ); }
	$tool = $tools->create( array( 'server_id' => $server->id, 'name' => 'QA builtin gateway read', 'endpoint_url' => 'https://example.org/qa-180-builtin-gateway', 'http_method' => 'GET', 'tool_type' => 'http', 'status' => 'active', 'input_schema' => array( 'type' => 'object', 'properties' => array() ) ) );
	if ( ! $tool ) { throw new RuntimeException( 'Could not create synthetic native tool.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	update_option( 'getmcp_gateway_enabled', 1 ); update_option( 'getmcp_gateway_auth_type', 'oauth' ); update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', array( $server->id, $other->id ) );
	\GetMCPExtensions\LegacyGateway180::prepare();
	$check( false === get_option( \GetMCPExtensions\LegacyGateway180::OPTION, false ), 'A gateway already upgraded by core is not retroactively granted legacy delegation.' );
	update_option( 'getmcp_db_version', '1.26' );
	update_option( 'getmcp_gateway_enabled', 0 ); \GetMCPExtensions\LegacyGateway180::prepare();
	$check( false === get_option( \GetMCPExtensions\LegacyGateway180::OPTION, false ), 'A previously disabled gateway does not acquire legacy delegation.' ); update_option( 'getmcp_gateway_enabled', 1 );
	$prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$check( ! is_wp_error( $prepared ), 'The explicitly selected legacy gateway scope is captured before core upgrade.' );
	update_option( 'getmcp_db_version', '1.26' ); update_option( 'getmcp_gateway_servers', array( $other->id ) );
	$check( is_wp_error( \GetMCPExtensions\LegacyGateway180::prepare() ), 'A gateway changed after an interrupted snapshot defers core migration.' );
	update_option( 'getmcp_gateway_servers', array( $server->id, $other->id ) ); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $live = $manager->get( $server->id );
	$check( ! is_wp_error( $result ) && 1 === $result['migrated'] && ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $live ), 'The member converts without changing its direct access.' );
	$gateway = \GetMCP\Gateway\McpGateway::server(); $resource = \GetMCP\Gateway\McpGateway::resource();
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'resource' => $resource ) ); $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-downstream';
	$handler = new \GetMCP\Gateway\FeatureHandler( $gateway, null ); $list = $handler->handle( 'tools/list', array() ); $name = '';
	foreach ( $list['tools'] as $entry ) { if ( str_ends_with( $entry['name'], $tool->slug ) ) { $name = $entry['name']; } }
	$call = $name ? $handler->handle( 'tools/call', array( 'name' => $name, 'arguments' => array() ) ) : array();
	$check( $name && empty( $call['isError'] ) && 1 === $calls, 'The valid pre-existing gateway grant retains selected legacy member access.' );
	$members = \GetMCP\Gateway\ProjectGateway::members( $gateway );
	$check( ! in_array( $other->id, array_map( static fn( $s ) => $s->id, $members ), true ), 'A native server outside the legacy snapshot does not bypass its own allowlist.' );
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => 0, 'resource' => $resource ) );
	$check( in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'A core-validated unbound client retains its exactly resource-bound grant.' );
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => $other->id, 'resource' => $resource ) );
	$check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'A client bound to a different server cannot use compatibility delegation.' );
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'resource' => $resource ) );
	$saved_snapshot = get_option( \GetMCPExtensions\LegacyGateway180::OPTION ); $tampered = json_decode( $saved_snapshot, true ); $tampered['members'][(string) $server->id] = $other->uuid;
	update_option( \GetMCPExtensions\LegacyGateway180::OPTION, wp_json_encode( $tampered ) );
	$check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'A modified snapshot cannot authorize a member.' ); update_option( \GetMCPExtensions\LegacyGateway180::OPTION, $saved_snapshot );
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'resource' => $resource . '/other' ) );
	$check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'A grant for a different resource cannot use the compatibility delegation.' );
	$token_row->setValue( (object) array( 'id' => 97009, 'user_id' => 1, 'client_server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'resource' => $resource ) ); update_option( 'getmcp_gateway_servers', array( $other->id ) );
	$check( ! in_array( $server->id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ), 'Removing a selected member immediately revokes its gateway access.' );
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); update_option( 'getmcp_gateway_mode', 'all' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) );
	update_option( 'getmcp_db_version', '1.26' ); $prepared_all = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$converted_all = \GetMCPExtensions\Upgrade180Auto::convert(); $members_all = \GetMCP\Gateway\ProjectGateway::members( $gateway );
	$ids_all = array_map( static fn( $s ) => $s->id, $members_all );
	$check( ! is_wp_error( $prepared_all ) && ! is_wp_error( $converted_all ) && in_array( $server->id, $ids_all, true ) && ! in_array( $other->id, $ids_all, true ), 'All-mode preserves only captured legacy members and retains native per-user checks.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic legacy builtin gateway delegation' ) ) . PHP_EOL;
} finally {
	remove_filter( 'pre_http_request', $http, 1 ); remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row );
	if ( null === $saved_auth ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
	if ( $tool ) { $tools->delete( $tool->id ); } foreach ( array( $server, $other ) as $item ) { if ( $item ) { $current = $manager->get( $item->id ); if ( $current && str_ends_with( $current->slug, $suffix ) ) { $manager->delete( $current->id ); } } }
	foreach ( $prior as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } } wp_set_current_user( $previous_user );
}
