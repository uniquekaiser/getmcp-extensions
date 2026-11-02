<?php
/** Only old managed keys or an explicitly public legacy gateway retain delegation. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ); $server = null; $scope = array(); $checks = 0; $key_ids = array();
$names = array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers', 'getmcp_gateway_namespace_tools', 'getmcp_extensions_180_gateway_snapshot', \GetMCPExtensions\Upgrade180Auto::LEDGER, 'getmcp_db_version' );
$prior = array(); foreach ( $names as $name ) { $prior[$name] = get_option( $name, null ); }
if ( null !== $prior['getmcp_extensions_180_gateway_snapshot'] ) { $existing = json_decode( (string) $prior['getmcp_extensions_180_gateway_snapshot'], true ); if ( ! is_array( $existing['members'] ?? null ) ) { throw new RuntimeException( 'Unexpected existing snapshot.' ); } foreach ( array_keys( $existing['members'] ) as $id ) { $s = $manager->get( (int) $id ); if ( ! $s || ! str_starts_with( $s->name, 'QA 180 ' ) ) { throw new RuntimeException( 'Only synthetic snapshots may be temporarily replaced.' ); } } delete_option( 'getmcp_extensions_180_gateway_snapshot' ); }
$previous_user = get_current_user_id(); $token_row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $token_row->getValue(); $old_header = $_SERVER['HTTP_X_API_KEY'] ?? null;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$has = static function( $gateway, int $id ): bool { return in_array( $id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( $gateway ) ), true ); };
try {
	wp_set_current_user( 1 ); $server = $manager->create( array( 'name' => 'QA 180 legacy key member', 'slug' => 'qa-180-key-member-' . $suffix, 'status' => 'active', 'auth_type' => 'wordpress', 'auth_config' => wp_json_encode( array( 'provider' => 'getmcp', 'allowed_user_ids' => array() ) ), 'settings' => '{}' ) );
	if ( ! $server ) { throw new RuntimeException( 'Could not create synthetic key member.' ); }
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); $scope = array( $server->id );
	$make_key = static function() use ( $wpdb, &$key_ids ): string { $key = 'qa180-' . wp_generate_password( 40, false, false ); if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_api_keys', array( 'server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'name' => 'QA 180 synthetic key', 'key_hash' => hash( 'sha256', $key ), 'key_prefix' => 'qa180', 'scopes' => '*', 'is_active' => 1 ) ) ) { throw new RuntimeException( 'Could not create synthetic Gateway key.' ); } $key_ids[] = (int) $wpdb->insert_id; return $key; };
	$old_key = $make_key(); update_option( 'getmcp_gateway_enabled', 1 ); update_option( 'getmcp_gateway_auth_type', 'api-key' ); update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', array( $server->id ) );
	update_option( 'getmcp_db_version', '1.26' ); \GetMCPExtensions\Upgrade180Store::maybe_prepare(); $prepared = get_option( \GetMCPExtensions\LegacyGateway180::OPTION, false ); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $gateway = \GetMCP\Gateway\McpGateway::server(); $token_row->setValue( null ); $_SERVER['HTTP_X_API_KEY'] = $old_key;
	$check( is_string( $prepared ) && ! is_wp_error( $result ) && $has( $gateway, $server->id ), 'An already-separated store still captures the Gateway before core migration and preserves its old key.' );
	$check( ! \GetMCPExtensions\LegacyBridge::can_user_access( 1, $manager->get( $server->id ) ), 'Gateway-key delegation does not change direct access.' );
	$new_key = $make_key(); $_SERVER['HTTP_X_API_KEY'] = $new_key; $check( ! $has( $gateway, $server->id ), 'A key issued after the snapshot does not gain the old delegation.' );
	$_SERVER['HTTP_X_API_KEY'] = $old_key; $wpdb->update( $wpdb->prefix . 'getmcp_api_keys', array( 'is_active' => 0 ), array( 'id' => $key_ids[0] ) );
	$check( ! $has( $gateway, $server->id ), 'A revoked old key immediately loses legacy access.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_api_keys', array( 'is_active' => 1, 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $key_ids[0] ) );
	$check( ! $has( $gateway, $server->id ), 'An expired old key cannot use legacy delegation.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_api_keys', array( 'expires_at' => null ), array( 'id' => $key_ids[0] ) ); delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); update_option( 'getmcp_gateway_mode', 'all' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); update_option( 'getmcp_db_version', '1.26' ); $prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $gateway = \GetMCP\Gateway\McpGateway::server();
	$check( ! is_wp_error( $prepared ) && ! is_wp_error( $result ) && $has( $gateway, $server->id ), 'An old Gateway key retains an eligible legacy member in all mode.' );
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); update_option( 'getmcp_gateway_auth_type', 'none' ); unset( $_SERVER['HTTP_X_API_KEY'] );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $server->id ) ); update_option( 'getmcp_db_version', '1.26' ); $prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $gateway = \GetMCP\Gateway\McpGateway::server();
	$check( ! is_wp_error( $prepared ) && ! is_wp_error( $result ) && $has( $gateway, $server->id ), 'An explicitly public all-mode Gateway retains its previously selected member.' );
	update_option( 'getmcp_gateway_servers', array() ); $check( ! $has( $gateway, $server->id ), 'Removing a public Gateway member immediately stops delegation.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic legacy key and public Gateway delegation' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $token_row->setValue( $old_row ); if ( null === $old_header ) { unset( $_SERVER['HTTP_X_API_KEY'] ); } else { $_SERVER['HTTP_X_API_KEY'] = $old_header; }
	foreach ( $key_ids as $id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_api_keys', array( 'id' => $id, 'name' => 'QA 180 synthetic key' ) ); }
	if ( $server ) { $current = $manager->get( $server->id ); if ( $current && str_ends_with( $current->slug, $suffix ) ) { $manager->delete( $server->id ); } }
	foreach ( $prior as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } } wp_set_current_user( $previous_user );
}
