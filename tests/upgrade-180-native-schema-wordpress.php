<?php
/** Rehearse the already-upgraded mixed-table repair using disposable tables only. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || '1' !== getenv( 'GETMCP_180_QA_ENABLE' ) ) { throw new RuntimeException( 'Isolated 1.8 fixture required.' ); }
global $wpdb; $prior_prefix = $wpdb->prefix; $prefix = 'qa180_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) . '_'; $table = $prefix . 'getmcp_upstream_connections'; $child = $prefix . 'references'; $checks = 0;
$check = static function( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
try {
	$wpdb->prefix = $prefix;
	$created = $wpdb->query( "CREATE TABLE `{$table}` (server_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,credentials LONGTEXT NOT NULL,version VARCHAR(36) NOT NULL,updated_at DATETIME NOT NULL,config_hash CHAR(64) NOT NULL DEFAULT '',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(server_id,user_id),KEY user_id(user_id)) ENGINE=InnoDB" );
	$check( false !== $created, 'The synthetic old-primary-key native table was created.' );
	$check( 1 === $wpdb->insert( $table, array( 'server_id' => 10, 'user_id' => 1, 'credentials' => 'synthetic-legacy-ciphertext', 'version' => 'synthetic-legacy', 'updated_at' => '2026-10-30 12:00:00', 'config_hash' => '' ) ), 'The legacy row was created.' );
	$check( is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'Repair refuses a table still containing legacy grants.' );
	$check( ! in_array( 'id', $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ), true ), 'Refusal leaves the original schema unchanged.' );
	$wpdb->delete( $table, array( 'server_id' => 10, 'user_id' => 1 ) );
	$check( 1 === $wpdb->insert( $table, array( 'server_id' => 20, 'user_id' => 2, 'credentials' => 'synthetic-native-ciphertext', 'version' => 'synthetic-native', 'updated_at' => '2026-10-30 12:00:00', 'config_hash' => str_repeat( 'a', 64 ) ) ), 'A native row was created.' );
	$before = $wpdb->get_row( "SELECT server_id,user_id,credentials,version,updated_at,config_hash,created_at FROM `{$table}` WHERE server_id=20", ARRAY_A );
	$check( false !== $wpdb->query( "ALTER TABLE `{$table}` ADD custom_field VARCHAR(10) NULL" ) && is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'An unreviewed custom schema is left unchanged.' );
	$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN custom_field" );
	$check( false !== $wpdb->query( "ALTER TABLE `{$table}` MODIFY config_hash CHAR(64) NULL" ) && is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'A nullable native configuration hash is left unchanged.' );
	$wpdb->query( "ALTER TABLE `{$table}` MODIFY config_hash CHAR(64) NOT NULL DEFAULT ''" );
	$check( false !== $wpdb->query( "CREATE TABLE `{$child}` (server_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,FOREIGN KEY (server_id,user_id) REFERENCES `{$table}` (server_id,user_id)) ENGINE=InnoDB" ) && is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'A primary key referenced by another table is left unchanged.' );
	$wpdb->query( "DROP TABLE `{$child}`" );
	$check( ! is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'The reviewed old-primary-key schema is repaired.' );
	$after = $wpdb->get_row( "SELECT server_id,user_id,credentials,version,updated_at,config_hash,created_at FROM `{$table}` WHERE server_id=20", ARRAY_A );
	$check( $before === $after && (int) $wpdb->get_var( "SELECT id FROM `{$table}` WHERE server_id=20" ) > 0, 'Native ciphertext and metadata remain unchanged and receive an id.' );
	$check( ! is_wp_error( \GetMCPExtensions\Upgrade180Store::repair_native_schema() ), 'Repair is idempotent.' );
	$previous = $wpdb->suppress_errors( true ); try { $duplicate = $wpdb->insert( $table, array( 'server_id' => 20, 'user_id' => 2, 'credentials' => 'other', 'version' => 'other', 'updated_at' => '2026-10-30 12:00:00', 'config_hash' => str_repeat( 'b', 64 ) ) ); } finally { $wpdb->suppress_errors( $previous ); }
	$check( false === $duplicate, 'The server and user uniqueness boundary survives repair.' );
	echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic already-upgraded native schema' ) ) . PHP_EOL;
} finally {
	$wpdb->prefix = $prior_prefix; if ( ! preg_match( '/\Aqa180_[a-f0-9]{10}_\z/D', $prefix ) ) { throw new RuntimeException( 'Unexpected fixture table prefix.' ); }
	$wpdb->query( "DROP TABLE IF EXISTS `{$child}`" ); $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}
