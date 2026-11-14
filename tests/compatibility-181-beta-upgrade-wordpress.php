<?php
/** Seed on 1.8.0, then verify on 1.8.1-beta2 in the same disposable site. */
if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) || ! \GetMCPExtensions\Runtime::ready() ) {
	throw new RuntimeException( 'An isolated reviewed 1.8 fixture is required.' );
}
global $wpdb;
wp_set_current_user( 1 );
$phase = getenv( 'GETMCP_BETA_UPGRADE_PHASE' );
$option = 'getmcp_beta_upgrade_fixture';
$manager = new \GetMCP\Core\ServerManager();
$servers = $wpdb->prefix . 'getmcp_servers';
$legacy = \GetMCP\Remote\UpstreamConnections::table();
$native = $wpdb->prefix . \GetMCP\RemoteMcp\UpstreamConnections::TABLE;
$row = static fn( string $table, int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE server_id=%d AND user_id=1", $id ), ARRAY_A );
$server_row = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$servers}` WHERE id=%d", $id ), ARRAY_A );
$digest = static fn( $value ) => hash( 'sha256', wp_json_encode( $value ) );

if ( 'seed' === $phase ) {
	if ( '1.8.0' !== GETMCP_VERSION || false !== get_option( $option, false ) ) { throw new RuntimeException( 'The seed requires a clean 1.8.0 fixture.' ); }
	$server = $manager->create( array(
		'name' => 'Synthetic beta upgrade preservation', 'slug' => 'synthetic-beta-upgrade-' . substr( wp_generate_uuid4(), 0, 8 ),
		'auth_type' => 'oauth', 'auth_config' => wp_json_encode( array( 'provider' => 'external', 'client_id' => 'synthetic-client' ) ),
		'auth_credentials' => wp_json_encode( array( 'client_secret' => 'synthetic-server-secret' ) ), 'settings' => '{}',
	) );
	if ( ! $server ) { throw new RuntimeException( 'Could not create the synthetic upgrade server.' ); }
	$id = (int) $server->id;
	try {
		\GetMCP\Remote\UpstreamConnections::save( $id, 1, array( 'access_token' => 'synthetic-legacy-token', 'expires_at' => time() + 3600 ) );
		$native_version = \GetMCP\RemoteMcp\UpstreamConnections::save( $id, 1, str_repeat( 'a', 64 ), array( 'access_token' => 'synthetic-native-token', 'refresh_token' => 'synthetic-refresh', 'expires_at' => time() + 3600, 'scope' => 'synthetic' ) );
		if ( ! $native_version || ! $server_row( $id ) || ! $row( $legacy, $id ) || ! $row( $native, $id ) ) { throw new RuntimeException( 'Could not seed both synthetic grant stores.' ); }
		add_option( $option, array( 'id' => $id, 'server' => $digest( $server_row( $id ) ), 'legacy' => $digest( $row( $legacy, $id ) ), 'native' => $digest( $row( $native, $id ) ), 'settings' => $digest( get_option( 'getmcp_extensions_settings', false ) ) ), '', false );
		echo "Seeded synthetic stable-core server and both grant stores.\n";
	} catch ( Throwable $e ) {
		$wpdb->delete( $legacy, array( 'server_id' => $id, 'user_id' => 1 ) );
		$wpdb->delete( $native, array( 'server_id' => $id, 'user_id' => 1 ) );
		$manager->delete( $id );
		throw $e;
	}
	return;
}
if ( 'check' !== $phase || '1.8.1-beta2' !== GETMCP_VERSION ) { throw new RuntimeException( 'The check requires the reviewed beta.' ); }
$state = get_option( $option );
if ( ! is_array( $state ) || empty( $state['id'] ) ) { throw new RuntimeException( 'The synthetic upgrade seed is missing.' ); }
$id = (int) $state['id']; $checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	$check( 'vendor-original-1.8.1-beta2' === \GetMCPExtensions\Runtime::profile() && \GetMCPExtensions\Upgrade180::reviewed_core(), 'The beta must load its reviewed profile.' );
	$check( hash_equals( $state['server'], $digest( $server_row( $id ) ) ), 'The server configuration and encrypted credentials must be unchanged.' );
	$check( hash_equals( $state['legacy'], $digest( $row( $legacy, $id ) ) ) && hash_equals( $state['native'], $digest( $row( $native, $id ) ) ), 'Both grant rows must remain byte-identical.' );
	$check( hash_equals( $state['settings'], $digest( get_option( 'getmcp_extensions_settings', false ) ) ), 'Extensions settings must be unchanged.' );
	$legacy_grant = \GetMCP\Remote\UpstreamConnections::get( $id, 1 );
	$native_grant = \GetMCP\RemoteMcp\UpstreamConnections::find( $id, 1, str_repeat( 'a', 64 ) );
	$check( 'synthetic-legacy-token' === ( $legacy_grant['data']['access_token'] ?? '' ) && 'synthetic-native-token' === ( $native_grant['credentials']['access_token'] ?? '' ), 'Both original user grants must remain decryptable.' );
	$check( null === \GetMCP\Remote\UpstreamConnections::get( $id, 2 ) && null === \GetMCP\RemoteMcp\UpstreamConnections::find( $id, 2, str_repeat( 'a', 64 ) ), 'Another user must not inherit either grant.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic 1.8.0 to beta upgrade' ) ) . PHP_EOL;
} finally {
	$wpdb->delete( $legacy, array( 'server_id' => $id, 'user_id' => 1 ) );
	$wpdb->delete( $native, array( 'server_id' => $id, 'user_id' => 1 ) );
	$live = $manager->get( $id );
	if ( $live && 'Synthetic beta upgrade preservation' === $live->name ) { $manager->delete( $id ); }
	delete_option( $option );
}
