<?php
/** Confirm a conflicting old-format destination defers core schema migration. */
if ( 'local' !== wp_get_environment_type() || '1.8.0' !== GETMCP_VERSION || ! getenv( 'GETMCP_AUTO_GRANT_HASH' ) ) { throw new RuntimeException( 'Snapshotted local conflict fixture required.' ); }
global $wpdb;
$server = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}getmcp_servers WHERE name='QA 180 automatic remote'" );
$source = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::NATIVE_SUFFIX;
$target = $wpdb->prefix . \GetMCPExtensions\Upgrade180Store::LEGACY_SUFFIX;
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } };
$check( $server > 0 && '1.26' === get_option( 'getmcp_db_version' ), 'Core schema update was deferred.' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT credentials,version,updated_at FROM {$source} WHERE server_id=%d AND user_id=1", $server ), ARRAY_A );
$check( is_array( $row ) && hash_equals( getenv( 'GETMCP_AUTO_GRANT_HASH' ), hash( 'sha256', wp_json_encode( $row ) ) ), 'Original encrypted grant remains intact.' );
$check( 'synthetic-conflicting-ciphertext' === $wpdb->get_var( $wpdb->prepare( "SELECT credentials FROM {$target} WHERE server_id=%d AND user_id=1", $server ) ), 'Conflicting destination remains intact.' );
$check( false === get_option( \GetMCPExtensions\Upgrade180Store::OPTION, false ), 'No successful split is recorded.' );
$check( is_wp_error( \GetMCPExtensions\Upgrade180Store::split_tables() ), 'Retry refuses the unresolved conflict.' );
$check( ! \GetMCPExtensions\Runtime::ready(), 'Legacy runtime remains guarded.' );
echo wp_json_encode( array( 'passed' => $checks, 'fixture' => 'synthetic conflicting grant stores' ) ) . PHP_EOL;
