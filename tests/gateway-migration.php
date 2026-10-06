<?php
/** Real dbDelta upgrade in dedicated temporary tables; no site or credential migration. */
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$original = $wpdb->prefix; $wpdb->prefix = 'qa_getmcp_migration_'; $checks = array();
$assert = function( $ok, $name ) use ( &$checks ) { $checks[ $name ] = (bool) $ok; if ( ! $ok ) { throw new RuntimeException( $name ); } };
try {
	$schema = \GetMCP\Database\Schema::get_schema();
	$old = preg_replace( '/\s+server_kind\s+VARCHAR\(20\) NOT NULL DEFAULT \'native\',/', '', $schema );
	$old = preg_replace( '/CREATE TABLE [^\s]+getmcp_upstream_connections \([\s\S]*?\) [^;]+;/', '', $old );
	dbDelta( $old );
	$secret = \GetMCP\Utils\Encryption::encrypt( '{"token":"migration-fixture"}' );
	$wpdb->insert( $wpdb->prefix . 'getmcp_servers', array( 'uuid' => wp_generate_uuid4(), 'name' => 'Migration native fixture', 'slug' => 'migration-native-fixture', 'auth_credentials' => $secret, 'outbound_auth_credentials' => $secret ) );
	$id = (int) $wpdb->insert_id; $assert( $id > 0, 'old_schema_native_fixture_persisted' );
	$run = new ReflectionMethod( \GetMCP\Database\Migrator::class, 'run_migration' ); $run->invoke( null, '1.25', '1.26' );
	$server = ( new \GetMCP\Core\ServerManager() )->get( $id );
	$assert( $server->server_kind === 'native', 'existing_rows_default_native' );
	$assert( $server->auth_credentials === $secret && $server->outbound_auth_credentials === $secret, 'migration_preserves_existing_credential_bytes' );
	$assert( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}getmcp_upstream_connections" ) === '0', 'no_existing_credentials_migrated_into_user_grants' );
	$run->invoke( null, '1.25', '1.26' );
	$assert( ( new \GetMCP\Core\ServerManager() )->get( $id )->outbound_auth_credentials === $secret, 'migration_idempotent' );
	$assert( in_array( $wpdb->prefix . 'getmcp_upstream_connections', \GetMCP\Database\Schema::get_tables(), true ), 'uninstall_table_cleanup_includes_connections' );
	echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT );
} finally {
	foreach ( \GetMCP\Database\Schema::get_tables() as $table ) {
		if ( ! str_starts_with( $table, 'qa_getmcp_migration_' ) ) { throw new RuntimeException( 'Unsafe test cleanup target.' ); }
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
	$wpdb->prefix = $original;
}
