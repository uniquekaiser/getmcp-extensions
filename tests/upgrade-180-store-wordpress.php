<?php
/** Synthetic table-only rehearsal. Run only in a local, snapshotted fixture. */
if ( wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'A local fixture is required.' ); }
global $wpdb;
$original_prefix = $wpdb->prefix;
$prefix = 'qa180_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) . '_';
$source = $prefix . 'getmcp_upstream_connections';
$target = $prefix . 'getmcp_extensions_upstream_connections';
$checks = 0;
$check = static function ( bool $ok, string $name ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $name ); } };
$exists = static fn( string $table ): bool => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
$legacy_schema = static fn( string $table ) => "CREATE TABLE `{$table}` (server_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,credentials LONGTEXT NOT NULL,version VARCHAR(36) NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(server_id,user_id)) ENGINE=InnoDB";
$insert = static fn( string $table, int $server, string $secret, string $version ) => $wpdb->insert( $table, array( 'server_id' => $server, 'user_id' => 1, 'credentials' => $secret, 'version' => $version, 'updated_at' => '2026-10-30 12:00:00' ) );
$pending_marker = static fn() => 'synthetic-unrecorded';
add_filter( 'pre_option_' . \GetMCPExtensions\Upgrade180Store::OPTION, $pending_marker );
try {
    $wpdb->prefix = $prefix;
    $check( false !== $wpdb->query( $legacy_schema( $source ) ), 'legacy fixture created' );
    $check( 1 === $insert( $source, 10, 'encrypted-synthetic-a', 'version-a' ), 'legacy synthetic grant created' );
    $check( $source === \GetMCPExtensions\Upgrade180Store::legacy_table(), 'Unmigrated store remains authoritative.' );
    $result = \GetMCPExtensions\Upgrade180Store::split_tables();
    $check( ! is_wp_error( $result ) && 'renamed' === $result['state'], 'old table moved atomically' );
    $check( ! $exists( $source ) && $exists( $target ), 'original table name freed for native schema' );
    $check( $target === \GetMCPExtensions\Upgrade180Store::legacy_table(), 'Interrupted rename is readable without a marker.' );
    $check( 'encrypted-synthetic-a' === $wpdb->get_var( "SELECT credentials FROM `{$target}` WHERE server_id=10 AND user_id=1" ), 'ciphertext retained byte-for-byte' );
    $check( ! is_wp_error( \GetMCPExtensions\Upgrade180Store::split_tables() ), 'interrupted rename can be retried' );
    // Simulate a 1.8 install whose core already added config_hash to the old table.
    $check( false !== $wpdb->query( $legacy_schema( $source ) ), 'mixed source created' );
    $check( $source === \GetMCPExtensions\Upgrade180Store::legacy_table(), 'Conflicting old-format source remains authoritative.' );
    $check( false !== $wpdb->query( "ALTER TABLE `{$source}` ADD config_hash CHAR(64) NOT NULL DEFAULT ''" ), 'native marker added' );
    $check( 1 === $insert( $source, 20, 'encrypted-synthetic-b', 'version-b' ), 'second legacy grant created' );
    $incomplete_refused = false;
    try { \GetMCPExtensions\Upgrade180Store::legacy_table(); } catch ( \RuntimeException $e ) { $incomplete_refused = true; }
    $check( $incomplete_refused, 'Incomplete mixed separation refuses ambiguous reads.' );
    $check( 1 === $wpdb->insert( $source, array( 'server_id' => 30, 'user_id' => 1, 'credentials' => 'native-synthetic', 'version' => 'native-version', 'updated_at' => '2026-10-30 12:00:00', 'config_hash' => str_repeat( 'a', 64 ) ) ), 'native grant created' );
    $result = \GetMCPExtensions\Upgrade180Store::split_tables();
    $check( ! is_wp_error( $result ) && 'moved' === $result['state'], 'mixed table split succeeds' );
    $check( $target === \GetMCPExtensions\Upgrade180Store::legacy_table(), 'Completed mixed split is readable without a marker.' );
    $check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` WHERE server_id=20" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$target}` WHERE server_id=20" ), 'legacy grant moved to the owned table' );
    $check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` WHERE server_id=30 AND config_hash='" . str_repeat( 'a', 64 ) . "'" ), 'native grant left in the native table' );
    $check( ! is_wp_error( \GetMCPExtensions\Upgrade180Store::split_tables() ), 'mixed split is idempotent' );
    $check( 1 === $insert( $source, 10, 'different-synthetic', 'version-conflict' ), 'conflicting source created' );
    $conflict = \GetMCPExtensions\Upgrade180Store::split_tables();
    $check( is_wp_error( $conflict ), 'conflicting destination is refused' );
    $check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$source}` WHERE server_id=10" ) && 'encrypted-synthetic-a' === $wpdb->get_var( "SELECT credentials FROM `{$target}` WHERE server_id=10" ), 'conflict preserves both versions' );
    echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'disposable tables' ) ) . PHP_EOL;
} finally {
    if ( ! preg_match( '/^qa180_[a-f0-9]{10}_$/D', $prefix ) ) { throw new RuntimeException( 'Unsafe fixture prefix.' ); }
    $wpdb->query( "DROP TABLE IF EXISTS `{$source}`" );
    $wpdb->query( "DROP TABLE IF EXISTS `{$target}`" );
    $wpdb->prefix = $original_prefix;
    remove_filter( 'pre_option_' . \GetMCPExtensions\Upgrade180Store::OPTION, $pending_marker );
}
