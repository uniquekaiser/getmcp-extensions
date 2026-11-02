<?php
/** Verify anonymous and managed-key Gateway delegation to synthetic remote servers. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb;
$manager = new \GetMCP\Core\ServerManager(); $scope = array(); $servers = array(); $checks = 0; $key_id = 0;
$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
$names = array( 'getmcp_gateway_enabled', 'getmcp_gateway_auth_type', 'getmcp_gateway_mode', 'getmcp_gateway_servers', 'getmcp_extensions_180_gateway_snapshot', \GetMCPExtensions\Upgrade180Auto::LEDGER, 'getmcp_db_version' );
$prior = array(); foreach ( $names as $name ) { $prior[$name] = get_option( $name, null ); }
if ( null !== $prior['getmcp_extensions_180_gateway_snapshot'] ) { $saved = json_decode( (string) $prior['getmcp_extensions_180_gateway_snapshot'], true ); foreach ( array_keys( $saved['members'] ?? array() ) as $id ) { $s = $manager->get( (int) $id ); if ( ! $s || ! str_starts_with( $s->name, 'QA 180 ' ) ) { throw new RuntimeException( 'Only synthetic snapshots may be replaced.' ); } } delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); }
$user = get_current_user_id(); $old_header = $_SERVER['HTTP_X_API_KEY'] ?? null;
$row = new ReflectionProperty( \GetMCP\Auth\McpAuth::class, 'current_token_row' ); $old_row = $row->getValue();
$filter = static function() use ( &$scope ): array { return $scope; }; add_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 );
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$has = static function( int $id ): bool { return in_array( $id, array_map( static fn( $s ) => $s->id, \GetMCP\Gateway\ProjectGateway::members( \GetMCP\Gateway\McpGateway::server() ) ), true ); };
try {
	wp_set_current_user( 1 );
	foreach ( array( 'none', 'shared', 'oauth' ) as $mode ) {
		$remote = array( 'endpoint' => 'https://example.org/mcp', 'auth_mode' => $mode, 'publish_original' => true );
		$args = array( 'kind' => 'remote-mcp', 'name' => 'QA 180 remote ' . $mode, 'slug' => 'qa-180-remote-' . $mode . '-' . $suffix, 'remote' => $remote, 'allowed_user_ids' => array() );
		if ( 'shared' === $mode ) { $args['credentials'] = array( 'headers' => array( 'Authorization' => 'Bearer synthetic-shared' ) ); }
		if ( 'oauth' === $mode ) { $args['remote'] += array( 'authorization_endpoint' => 'https://example.org/authorize', 'token_endpoint' => 'https://example.org/token', 'client_id' => 'synthetic' ); }
		$created = \GetMCP\Gateway\FeatureManager::run( 'save', $args, 1 ); $servers[$mode] = (int) $created['id'];
		$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $servers[$mode] ) );
	}
	$scope = array_values( $servers );
	$key = 'qa180-' . wp_generate_password( 40, false, false );
	if ( 1 !== $wpdb->insert( $wpdb->prefix . 'getmcp_api_keys', array( 'server_id' => \GetMCP\Gateway\McpGateway::SERVER_ID, 'name' => 'QA 180 synthetic remote key', 'key_hash' => hash( 'sha256', $key ), 'key_prefix' => 'qa180', 'scopes' => '*', 'is_active' => 1 ) ) ) { throw new RuntimeException( 'Could not create key.' ); }
	$key_id = (int) $wpdb->insert_id; update_option( 'getmcp_gateway_enabled', 1 ); update_option( 'getmcp_gateway_auth_type', 'api-key' ); update_option( 'getmcp_gateway_mode', 'selected' ); update_option( 'getmcp_gateway_servers', $scope );
	update_option( 'getmcp_db_version', '1.26' ); $prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] );
	$result = \GetMCPExtensions\Upgrade180Auto::convert(); $row->setValue( null ); $_SERVER['HTTP_X_API_KEY'] = $key;
	$check( ! is_wp_error( $prepared ) && ! is_wp_error( $result ) && $has( $servers['none'] ) && $has( $servers['shared'] ), 'Existing key retains none and shared remote members.' );
	$check( ! $has( $servers['oauth'] ), 'A gateway without a user cannot acquire a personal upstream OAuth grant.' );
	$original = $wpdb->get_row( $wpdb->prepare( "SELECT settings,outbound_auth_credentials FROM {$wpdb->prefix}getmcp_servers WHERE id=%d", $servers['shared'] ), ARRAY_A );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'outbound_auth_credentials' => \GetMCP\Utils\Encryption::encrypt( wp_json_encode( array( 'headers' => array( 'Authorization' => 'Bearer other' ) ) ) ) ), array( 'id' => $servers['shared'] ) );
	$check( ! $has( $servers['shared'] ), 'Changing a shared upstream credential does not extend old key delegation.' );
	$wpdb->update( $wpdb->prefix . 'getmcp_servers', $original, array( 'id' => $servers['shared'] ) );
	$check( $has( $servers['shared'] ), 'The unchanged original configuration retains delegation.' );
	delete_option( \GetMCPExtensions\LegacyGateway180::OPTION ); update_option( 'getmcp_gateway_auth_type', 'none' ); unset( $_SERVER['HTTP_X_API_KEY'] );
	foreach ( $servers as $id ) { $wpdb->update( $wpdb->prefix . 'getmcp_servers', array( 'auth_type' => 'oauth' ), array( 'id' => $id ) ); }
	update_option( 'getmcp_db_version', '1.26' ); $prepared = \GetMCPExtensions\LegacyGateway180::prepare(); update_option( 'getmcp_db_version', $prior['getmcp_db_version'] ); $result = \GetMCPExtensions\Upgrade180Auto::convert();
	$check( ! is_wp_error( $prepared ) && ! is_wp_error( $result ) && $has( $servers['none'] ) && $has( $servers['shared'] ) && ! $has( $servers['oauth'] ), 'Explicitly selected public remotes retain their previous non-personal credentials.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic remote key and public Gateway' ) ) . PHP_EOL;
} finally {
	remove_filter( 'getmcp_extensions_180_migration_scope', $filter, 1000 ); $row->setValue( $old_row ); if ( null === $old_header ) { unset( $_SERVER['HTTP_X_API_KEY'] ); } else { $_SERVER['HTTP_X_API_KEY'] = $old_header; }
	if ( $key_id ) { $wpdb->delete( $wpdb->prefix . 'getmcp_api_keys', array( 'id' => $key_id, 'name' => 'QA 180 synthetic remote key' ) ); }
	foreach ( $servers as $id ) { $s = $manager->get( $id ); if ( $s && str_ends_with( $s->slug, $suffix ) ) { $manager->delete( $id ); } }
	foreach ( $prior as $name => $value ) { if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } } wp_set_current_user( $user );
}
